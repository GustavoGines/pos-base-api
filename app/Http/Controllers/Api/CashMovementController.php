<?php

namespace App\Http\Controllers\Api;

use App\Exports\CashMovementsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashMovementRequest;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use App\Services\CashShiftService;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class CashMovementController extends Controller
{
    protected $shiftService;

    public function __construct(CashShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    /**
     * Resuelve el turno de caja objetivo soportando entornos Multi-Caja (F3-CAJA-01 / FN-F3-02)
     * con fallback retrocompatible a v1.8.5 cuando no se envían identificadores explícitos.
     */
    protected function resolveActiveShift(Request $request): ?CashShift
    {
        $shiftId = $request->input('cash_shift_id') ?? $request->query('cash_shift_id');
        if (! empty($shiftId) && is_numeric($shiftId)) {
            return CashShift::find((int) $shiftId);
        }

        $registerId = $request->input('cash_register_id') ?? $request->query('cash_register_id');
        if (! empty($registerId) && is_numeric($registerId)) {
            return $this->shiftService->getCurrentShift((int) $registerId);
        }

        return $this->shiftService->getCurrentShift();
    }

    public function index(Request $request)
    {
        $query = CashMovement::with(['user', 'authorizer', 'supplier', 'check', 'cashShift.cashRegister:id,name'])->latest();

        if ($request->query('all') !== '1') {
            $shift = $this->resolveActiveShift($request);
            if (! $shift) {
                return response()->json([
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ]);
            }
            $query->where('cash_shift_id', $shift->id);
        } elseif ($request->filled('cash_register_id') && is_numeric($request->query('cash_register_id'))) {
            $registerId = (int) $request->query('cash_register_id');
            $query->whereHas('cashShift', fn ($q) => $q->where('cash_register_id', $registerId));
        }

        // Optional filtering by category (smart mapping for UI filter groups + custom categories)
        if ($request->filled('category')) {
            $cat = $request->query('category');
            if ($cat === 'Retiro de Caja' || $cat === 'Retiro') {
                $query->where(fn ($q) => $q->where('type', 'withdrawal')->orWhereIn('category', ['Retiro', 'Retiro de Caja']));
            } elseif ($cat === 'Gasto') {
                $query->where(fn ($q) => $q
                    ->where(fn ($sq) => $sq->where('type', 'expense')->where(fn ($rq) => $rq->whereNull('category')->orWhere('category', '!=', 'refund')))
                    ->orWhere('category', 'Gasto'));
            } elseif ($cat === 'Ingreso Manual') {
                $query->where(fn ($q) => $q->where('type', 'deposit')->orWhere('category', 'Ingreso Manual'));
            } elseif ($cat === 'Pago a Proveedor') {
                $query->where(fn ($q) => $q->where('type', 'supplier_payment')->orWhere('category', 'Pago a Proveedor'));
            } else {
                $query->where('category', $cat);
            }
        }

        // Optional filtering by expense_category_id
        if ($request->has('expense_category_id')) {
            $query->where('expense_category_id', $request->query('expense_category_id'));
        }

        // Optional filtering by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [
                $request->query('start_date').' 00:00:00',
                $request->query('end_date').' 23:59:59',
            ]);
        }

        // Calcular totales globales del query actual (antes de paginación)
        $totalsQuery = clone $query;
        $totalIn = (clone $totalsQuery)->where('type', 'deposit')->sum('amount');
        $totalOut = (clone $totalsQuery)->whereIn('type', ['expense', 'withdrawal', 'supplier_payment'])->sum('amount');

        $movements = $query->paginate(50);

        return response()->json([
            'data' => $movements->items(),
            'current_page' => $movements->currentPage(),
            'last_page' => $movements->lastPage(),
            'total' => $movements->total(),
            'kpi_total_in' => $totalIn,
            'kpi_total_out' => $totalOut,
            'kpi_net' => $totalIn - $totalOut,
        ]);
    }

    public function export(Request $request)
    {
        $shiftId = null;
        $cashRegisterId = null;
        if ($request->query('all') !== '1') {
            $shift = $this->resolveActiveShift($request);
            if (! $shift) {
                return response()->json(['message' => 'No hay turno abierto para exportar.'], 400);
            }
            $shiftId = $shift->id;
        } elseif ($request->filled('cash_register_id') && is_numeric($request->query('cash_register_id'))) {
            $cashRegisterId = (int) $request->query('cash_register_id');
        }

        $category = $request->query('category');
        $expenseCategoryId = $request->query('expense_category_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        return Excel::download(
            new CashMovementsExport($shiftId, $category, $expenseCategoryId, $startDate, $endDate, $cashRegisterId),
            'cash_movements_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function store(StoreCashMovementRequest $request)
    {
        // FINDING-CASH-02: En Multi-Caja, si hay múltiples cajas abiertas, exigir especificar la caja/turno
        $openShiftsCount = CashShift::where('status', 'open')->count();
        if ($openShiftsCount > 1 && ! $request->filled('cash_shift_id') && ! $request->filled('cash_register_id')) {
            return response()->json([
                'message' => 'Existen múltiples cajas abiertas. Debe especificar cash_shift_id o cash_register_id.',
                'errors' => ['cash_shift_id' => ['Existen múltiples cajas abiertas. Debe especificar cash_shift_id o cash_register_id.']],
            ], 422);
        }

        $shift = $this->resolveActiveShift($request);

        if (! $shift) {
            return response()->json(['message' => 'No hay turno abierto para registrar el movimiento.'], 403);
        }

        // Validación extra de integridad del turno
        if ($shift->status !== 'open') {
            return response()->json(['message' => 'El turno actual se encuentra cerrado.'], 403);
        }

        $validated = $request->validated();
        $user = $request->user() ?? $request->attributes->get('authenticated_user');
        $authorizedBy = $request->attributes->get('authorized_by_admin_id');

        // Seguridad: Los Retiros exigen rol de admin o autorización por PIN
        if ($validated['type'] === 'withdrawal') {
            if (! ($user && ($user->isAdmin() || $user->hasPermission('all')))) {
                $rawHeaderPin = $request->header('X-Admin-Pin');
                $rawBodyPin = $request->input('admin_pin');
                $adminPin = (is_string($rawHeaderPin) && trim($rawHeaderPin) !== '')
                    ? trim($rawHeaderPin)
                    : ((is_string($rawBodyPin) && trim($rawBodyPin) !== '') ? trim($rawBodyPin) : null);

                if ($adminPin === null) {
                    return response()->json([
                        'message' => 'Los retiros de dinero requieren PIN de administrador.',
                        'error_code' => 'PIN_REQUIRED',
                    ], 403);
                }

                $throttleKey = 'pin_attempts:'.($user ? $user->id : 'guest').':'.$request->ip();
                if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                    $seconds = RateLimiter::availableIn($throttleKey);

                    return response()->json([
                        'message' => "Demasiados intentos erróneos de PIN. Operación bloqueada temporalmente por {$seconds} segundos.",
                        'error_code' => 'PIN_LOCKED_TEMPORARILY',
                        'retry_after' => $seconds,
                    ], 429);
                }

                $admin = User::withoutGlobalScope('visible')
                    ->where('role', 'admin')
                    ->whereNotNull('pin')
                    ->get()
                    ->first(function ($a) use ($adminPin) {
                        return Hash::check($adminPin, $a->pin);
                    });

                if (! $admin) {
                    RateLimiter::hit($throttleKey, 300);

                    return response()->json([
                        'message' => 'PIN de administrador inválido para retiro.',
                        'error_code' => 'INVALID_ADMIN_PIN',
                    ], 403);
                }

                RateLimiter::clear($throttleKey);
                $authorizedBy = $admin->id;
                $request->attributes->set('authorized_by_admin_id', $admin->id);
            } else {
                $authorizedBy = $user->id;
            }
        }

        try {
            $createdMovements = [];
            $batchUuid = (string) Str::uuid();

            DB::transaction(function () use ($validated, $shift, $user, $authorizedBy, &$createdMovements) {
                // F3-CAJA-03 / FN-F3-03: Bloqueo pesimista del turno dentro de la transacción
                $lockedShift = CashShift::where('id', $shift->id)->lockForUpdate()->first();
                if (! $lockedShift || $lockedShift->status !== 'open') {
                    throw new \RuntimeException('SHIFT_ALREADY_CLOSED');
                }

                // CP-09: Bloquear proveedor en orden canónico antes de iterar pagos/cheques
                $lockedSupplier = null;
                if (! empty($validated['supplier_id'])) {
                    $lockedSupplier = Supplier::where('id', $validated['supplier_id'])->lockForUpdate()->first();
                }

                $totalAmountPaid = 0;

                foreach ($validated['payments'] as $payment) {
                    $amount = $payment['amount'];
                    $method = $payment['payment_method'];
                    $checkId = $payment['check_id'] ?? null;

                    $totalAmountPaid += $amount;

                    $movement = CashMovement::create([
                        'cash_shift_id' => $shift->id,
                        'user_id' => $user->id,
                        'authorized_by' => $authorizedBy,
                        'supplier_id' => $validated['supplier_id'] ?? null,
                        'check_id' => $method === 'check' ? $checkId : null,
                        'amount' => $amount,
                        'payment_method' => $method,
                        'type' => $validated['type'],
                        'category' => $validated['category'] ?? null,
                        'expense_category_id' => $validated['expense_category_id'] ?? null,
                        'receipt_file_url' => $validated['receipt_file_url'] ?? null,
                        'description' => $validated['description'] ?? null,
                        'receipt_number' => $validated['receipt_number'] ?? null,
                    ]);

                    $createdMovements[] = $movement;

                    // Si se usó un cheque, endosarlo con bloqueo pesimista
                    if ($method === 'check' && $checkId) {
                        // CONCURRENCY HARDENING: Bloqueo pesimista para evitar carreras multiterinal (TOCTOU)
                        $check = ThirdPartyCheck::where('id', $checkId)->lockForUpdate()->first();
                        if (! $check || $check->status !== 'in_wallet') {
                            throw new \Exception("El cheque #{$checkId} ya no se encuentra disponible en cartera.");
                        }
                        $check->update([
                            'status' => 'endorsed',
                            'supplier_id' => $validated['supplier_id'] ?? null,
                            'endorsement_note' => 'Endosado a proveedor en Movimiento #'.$movement->id.' ('.($movement->receipt_number ?? 'S/N').')',
                        ]);
                    }
                }

                // Si es pago o reembolso de proveedor, actualizar deuda total bajo lock (CP-03 / CP-09)
                if ($lockedSupplier) {
                    if (in_array($validated['type'], ['supplier_payment', 'expense'])) {
                        $lockedSupplier->decrement('balance', $totalAmountPaid);
                        if ((float) $lockedSupplier->fresh()->balance <= 0.009) {
                            $lockedSupplier->invoices()
                                ->whereIn('status', ['pending', 'partial'])
                                ->update(['status' => 'paid']);
                        }
                    } elseif ($validated['type'] === 'deposit') {
                        $lockedSupplier->increment('balance', $totalAmountPaid);
                    }
                }
            });

            if (in_array($validated['type'], ['expense', 'supplier_payment', 'withdrawal'])) {
                ReportCacheService::flush();
            }

            return response()->json([
                'message' => 'Movimientos registrados exitosamente.',
                'batch_uuid' => $batchUuid,
                'movements' => collect($createdMovements)->map(fn ($m) => [
                    'id' => $m->id,
                    'amount' => $m->amount,
                    'payment_method' => $m->payment_method,
                    'type' => $m->type,
                    'category' => $m->category,
                    'created_at' => $m->created_at->toIso8601String(),
                ])->values(),
            ], 201);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'SHIFT_ALREADY_CLOSED') {
                return response()->json(['message' => 'El turno actual se encuentra cerrado.'], 403);
            }

            return response()->json([
                'message' => 'Error crítico al procesar el movimiento.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $movement = CashMovement::findOrFail($id);
        $user = $request->user() ?? $request->attributes->get('authenticated_user');

        // Candado de Seguridad 1: Debe haber un turno abierto
        if (! CashShift::where('status', 'open')->exists()) {
            return response()->json([
                'message' => 'No hay un turno de caja abierto.',
                'error' => 'Para anular un movimiento, debe tener una caja abierta.',
            ], 403);
        }

        // Candado de Seguridad 2: No permitir anular manualmente devoluciones automáticas de ventas anuladas
        if ($movement->category === 'refund') {
            return response()->json([
                'message' => 'Movimiento protegido del sistema.',
                'error' => 'Las devoluciones automáticas generadas por anulación de ventas no pueden eliminarse manualmente.',
            ], 403);
        }

        // Candado de Seguridad 3: El turno al que pertenece el movimiento debe seguir abierto (multi-caja safe)
        $movementShift = CashShift::find($movement->cash_shift_id);
        if (! $movementShift || $movementShift->status !== 'open') {
            return response()->json([
                'message' => 'Movimiento bloqueado por seguridad.',
                'error' => 'Solo se pueden anular movimientos pertenecientes al turno de caja actual. Los movimientos de turnos cerrados están bloqueados y son inmutables.',
            ], 403);
        }

        try {
            DB::transaction(function () use ($movement, $user) {
                $lockedShift = CashShift::where('id', $movement->cash_shift_id)->lockForUpdate()->first();
                if (! $lockedShift || $lockedShift->status !== 'open') {
                    throw new \RuntimeException('SHIFT_ALREADY_CLOSED');
                }
                // Revertir balance de proveedor con lockForUpdate (CP-09)
                if ($movement->supplier_id) {
                    $supplier = Supplier::where('id', $movement->supplier_id)->lockForUpdate()->first();
                    if ($supplier) {
                        if (in_array($movement->type, ['supplier_payment', 'expense'])) {
                            $supplier->increment('balance', $movement->amount);
                        } elseif ($movement->type === 'deposit') {
                            $supplier->decrement('balance', $movement->amount);
                        }
                    }
                }

                // Revertir estado del cheque
                if ($movement->payment_method === 'check' && $movement->check_id) {
                    $check = ThirdPartyCheck::lockForUpdate()->find($movement->check_id);
                    if ($check && $check->status !== 'voided') {
                        // ASYMMETRIC VOID CLEANUP: Limpiar proveedor y notas al anular el movimiento
                        $check->update([
                            'status' => 'in_wallet',
                            'supplier_id' => null,
                            'endorsement_note' => null,
                        ]);
                    }
                }

                // Marcar auditoría y borrar
                $movement->deleted_by = $user?->id;
                $movement->save();
                $movement->delete();
            });

            if (in_array($movement->type, ['expense', 'supplier_payment', 'withdrawal'])) {
                ReportCacheService::flush();
            }

            return response()->json(['message' => 'Movimiento anulado exitosamente.']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al anular el movimiento.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:5120|mimes:jpeg,png,jpg,pdf',
        ]);

        try {
            $file = $request->file('file');
            $path = $file->store('cash_receipts', 'public');

            return response()->json([
                'message' => 'Archivo subido correctamente.',
                'path' => $path,
                'url' => asset('storage/'.$path),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al subir el archivo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
