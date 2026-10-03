<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\User;
use App\Services\CashShiftService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CashShiftController extends Controller
{
    protected CashShiftService $cashShiftService;

    public function __construct(CashShiftService $cashShiftService)
    {
        $this->cashShiftService = $cashShiftService;
    }

    public function index(Request $request)
    {
        $shifts = CashShift::with('cashRegister', 'user', 'closedByUser')
            ->orderBy('opened_at', 'desc')
            ->paginate(50);

        foreach ($shifts as $shift) {
            if ($shift->status === 'open') {
                $liveTotals = $this->cashShiftService->calculateLiveTotals($shift);
                $shift->forceFill($liveTotals);
            }
        }

        return response()->json($shifts);
    }

    public function current(Request $request)
    {
        $registerId = $request->query('cash_register_id');
        $shift = $this->cashShiftService->getCurrentShift($registerId ? (int) $registerId : null);

        if (! $shift) {
            return response()->json(['message' => 'No hay caja abierta.'], 404);
        }

        if ($shift->status === 'open') {
            $liveTotals = $this->cashShiftService->calculateLiveTotals($shift);
            $shift->forceFill($liveTotals);
        }

        return response()->json($shift->load('cashRegister', 'user'));
    }

    public function open(Request $request)
    {
        $validated = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'cash_register_id' => 'nullable|exists:cash_registers,id',
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            $shift = $this->cashShiftService->openShift(
                (int) $validated['user_id'],
                (float) $validated['opening_balance'],
                isset($validated['cash_register_id']) ? (int) $validated['cash_register_id'] : null
            );

            return response()->json([
                'message' => 'Turno abierto correctamente.',
                'shift' => $shift->load('cashRegister', 'user'),
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() === 403 ? 403 : 500;

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }

    public function close(Request $request, $id)
    {
        $validated = $request->validate([
            'actual_balance' => 'required|numeric|min:0',
            'closer_user_id' => 'nullable|exists:users,id',
            'pin' => 'nullable|string',
        ]);

        $authUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $closerUserId = $authUser?->id ?? $validated['closer_user_id'] ?? null;

        if (empty($validated['pin'])) {
            return response()->json(['message' => 'El PIN de seguridad es obligatorio para cerrar el turno de caja.'], 403);
        }

        $user = User::withoutGlobalScope('visible')->find($closerUserId);
        if (! $user || ! Hash::check($validated['pin'], $user->pin)) {
            return response()->json(['message' => 'PIN de autorización incorrecto.'], 403);
        }

        // ── Pre-calcular diferencia para exigir autorización si hay discrepancia ──
        $shift = CashShift::where('id', $id)->where('status', 'open')->first();
        if (! $shift) {
            return response()->json(['message' => 'El turno no existe o ya está cerrado.'], 404);
        }

        $totals = $this->cashShiftService->calculateLiveTotals($shift);
        $expectedBalance = $totals['expected_balance'];
        $difference = (float) $validated['actual_balance'] - $expectedBalance;

        $authorizedByAdminId = null;

        if (abs($difference) > 0.01) {
            // Hay faltante o sobrante → se necesita autorización de admin
            $userPermissions = is_array($user->permissions) ? $user->permissions : json_decode($user->permissions ?? '[]', true);
            $hasManageShifts = $user->isAdmin() || in_array('manage_shifts', $userPermissions ?: []) || in_array('all', $userPermissions ?: []);

            if ($hasManageShifts) {
                // El propio usuario tiene permiso manage_shifts → se auto-autoriza
                $authorizedByAdminId = $user->id;
            } else {
                // Verificar si viene un X-Admin-Pin de un supervisor o body admin_pin
                $rawHeaderPin = $request->header('X-Admin-Pin');
                $rawBodyPin = $request->input('admin_pin');
                $adminPin = (is_string($rawHeaderPin) && trim($rawHeaderPin) !== '')
                    ? trim($rawHeaderPin)
                    : ((is_string($rawBodyPin) && trim($rawBodyPin) !== '') ? trim($rawBodyPin) : null);

                if ($adminPin === null) {
                    return response()->json([
                        'message' => 'La diferencia de caja requiere autorización de un supervisor.',
                        'error_code' => 'DIFFERENCE_REQUIRES_ADMIN',
                        'expected_balance' => $expectedBalance,
                        'actual_balance' => (float) $validated['actual_balance'],
                        'difference' => $difference,
                    ], 403);
                }

                $throttleKey = 'pin_attempts:' . ($user ? $user->id : 'guest') . ':' . $request->ip();
                if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                    $seconds = RateLimiter::availableIn($throttleKey);

                    return response()->json([
                        'message' => "Demasiados intentos erróneos de PIN. Operación bloqueada temporalmente por {$seconds} segundos.",
                        'error_code' => 'PIN_LOCKED_TEMPORARILY',
                        'retry_after' => $seconds,
                    ], 429);
                }

                // Validar el PIN del admin
                $admin = User::withoutGlobalScope('visible')
                    ->where('role', 'admin')
                    ->whereNotNull('pin')
                    ->get()
                    ->first(fn ($a) => Hash::check($adminPin, $a->pin));

                if (! $admin) {
                    RateLimiter::hit($throttleKey, 300);

                    return response()->json([
                        'message' => 'PIN de supervisor incorrecto.',
                        'error_code' => 'INVALID_ADMIN_PIN',
                    ], 403);
                }

                RateLimiter::clear($throttleKey);
                $authorizedByAdminId = $admin->id;
            }
        } else {
            // Sin diferencia → el mismo cajero autoriza
            $authorizedByAdminId = $user->isAdmin() ? $user->id : null;
        }

        try {
            $closedShift = $this->cashShiftService->closeShift(
                (int) $id,
                (float) $validated['actual_balance'],
                $closerUserId ? (int) $closerUserId : null,
                $authorizedByAdminId
            );

            return response()->json([
                'message' => 'Turno cerrado correctamente.',
                'shift' => $closedShift->load('user', 'cashRegister', 'closedByUser'),
            ]);
        } catch (Exception $e) {
            Log::error('Error closing shift: '.$e->getMessage()."\n".$e->getTraceAsString());
            $status = $e->getCode() === 403 ? 403 : 500;

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}
