<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashMovementRequest;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashMovementController extends Controller
{
    protected $shiftService;

    public function __construct(CashShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    public function index(Request $request)
    {
        $shift = $this->shiftService->getCurrentShift();
        if (!$shift) {
            return response()->json([]);
        }

        $movements = CashMovement::with(['user', 'authorizer', 'supplier', 'check'])
            ->where('cash_shift_id', $shift->id)
            ->latest()
            ->get();

        return response()->json($movements);
    }

    public function store(StoreCashMovementRequest $request)
    {
        $shift = $this->shiftService->getCurrentShift();
        
        if (!$shift) {
            return response()->json(['message' => 'No hay turno abierto para registrar el movimiento.'], 403);
        }

        // Validación extra de integridad del turno
        if ($shift->status !== 'open') {
            return response()->json(['message' => 'El turno actual se encuentra cerrado.'], 403);
        }

        $validated = $request->validated();
        $user = $request->attributes->get('authenticated_user');
        $authorizedBy = $request->attributes->get('authorized_by_admin_id');

        // Seguridad: Los Retiros exigen rol de admin o autorización por PIN (X-Admin-Pin verificado por otro middleware? No, porque la ruta es pública. Validemos acá.)
        if ($validated['type'] === 'withdrawal') {
            if ($user->role !== 'admin') {
                // Verificar si mandó el PIN en la cabecera
                $adminPin = $request->header('X-Admin-Pin');
                if (!$adminPin) {
                    return response()->json(['message' => 'Los retiros de dinero requieren PIN de administrador.'], 403);
                }
                
                $admin = \App\Models\User::where('role', 'admin')->whereNotNull('pin')->get()->first(function($a) use ($adminPin) {
                    return \Illuminate\Support\Facades\Hash::check($adminPin, $a->pin);
                });

                if (!$admin) {
                    return response()->json(['message' => 'PIN de administrador inválido para retiro.'], 403);
                }
                $authorizedBy = $admin->id;
            } else {
                $authorizedBy = $user->id;
            }
        }

        try {
            $createdMovements = [];

            DB::transaction(function () use ($validated, $shift, $user, $authorizedBy, &$createdMovements) {
                $totalAmountPaid = 0;

                foreach ($validated['payments'] as $payment) {
                    $amount = $payment['amount'];
                    $method = $payment['payment_method'];
                    $checkId = $payment['check_id'] ?? null;
                    
                    $totalAmountPaid += $amount;

                    $movement = CashMovement::create([
                        'cash_shift_id'  => $shift->id,
                        'user_id'        => $user->id,
                        'authorized_by'  => $authorizedBy,
                        'supplier_id'    => $validated['supplier_id'] ?? null,
                        'check_id'       => $checkId,
                        'amount'         => $amount,
                        'payment_method' => $method,
                        'type'           => $validated['type'],
                        'category'       => $validated['category'] ?? null,
                        'expense_category_id' => $validated['expense_category_id'] ?? null,
                        'description'    => $validated['description'] ?? null,
                        'receipt_number' => $validated['receipt_number'] ?? null,
                    ]);

                    $createdMovements[] = $movement;

                    // Si se usó un cheque, endosarlo
                    if ($method === 'check' && $checkId) {
                        $check = ThirdPartyCheck::find($checkId);
                        $check->update(['status' => 'endorsed']);
                    }
                }

                // Si es pago o reembolso de proveedor, actualizar deuda total
                if (!empty($validated['supplier_id'])) {
                    $supplier = Supplier::find($validated['supplier_id']);
                    if ($supplier) {
                        if ($validated['type'] === 'supplier_payment' || $validated['type'] === 'expense') {
                            $supplier->decrement('balance', $totalAmountPaid);
                        } elseif ($validated['type'] === 'deposit') {
                            $supplier->increment('balance', $totalAmountPaid);
                        }
                    }
                }
            });

            return response()->json([
                'message'   => 'Movimientos registrados exitosamente.',
                'movements' => collect($createdMovements)->map(fn ($m) => [
                    'id'             => $m->id,
                    'amount'         => $m->amount,
                    'payment_method' => $m->payment_method,
                    'type'           => $m->type,
                    'category'       => $m->category,
                    'created_at'     => $m->created_at->toIso8601String(),
                ])->values(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error crítico al procesar el movimiento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $movement = CashMovement::findOrFail($id);
        $user = $request->attributes->get('authenticated_user');

        try {
            DB::transaction(function () use ($movement, $user) {
                // Revertir balance de proveedor
                if ($movement->supplier_id) {
                    $supplier = Supplier::find($movement->supplier_id);
                    if ($supplier) {
                        if ($movement->type === 'supplier_payment' || $movement->type === 'expense') {
                            $supplier->increment('balance', $movement->amount);
                        } elseif ($movement->type === 'deposit') {
                            $supplier->decrement('balance', $movement->amount);
                        }
                    }
                }

                // Revertir estado del cheque
                if ($movement->payment_method === 'check' && $movement->check_id) {
                    $check = ThirdPartyCheck::find($movement->check_id);
                    if ($check) {
                        $check->update(['status' => 'in_wallet']);
                    }
                }

                // Marcar auditoría y borrar
                $movement->deleted_by = $user->id;
                $movement->save();
                $movement->delete();
            });

            return response()->json(['message' => 'Movimiento anulado exitosamente.']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al anular el movimiento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
