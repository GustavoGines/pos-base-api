<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Exception;

class CashShiftController extends Controller
{
    protected CashShiftService $cashShiftService;

    public function __construct(CashShiftService $cashShiftService)
    {
        $this->cashShiftService = $cashShiftService;
    }

    public function index(Request $request)
    {
        $shifts = \App\Models\CashShift::with('cashRegister', 'user', 'closedByUser')
            ->orderBy('opened_at', 'desc')
            ->paginate(50);

        return response()->json($shifts);
    }

    public function current(Request $request)
    {
        $registerId = $request->query('cash_register_id');
        $shift = $this->cashShiftService->getCurrentShift($registerId ? (int)$registerId : null);

        if (!$shift) {
            return response()->json(['message' => 'No hay caja abierta.'], 404);
        }

        return response()->json($shift->load('cashRegister'));
    }

    public function open(Request $request)
    {
        $validated = $request->validate([
            'opening_balance'  => 'required|numeric|min:0',
            'cash_register_id' => 'nullable|exists:cash_registers,id',
            'user_id'          => 'required|exists:users,id',
        ]);

        try {
            $shift = $this->cashShiftService->openShift(
                (int)$validated['user_id'],
                (float)$validated['opening_balance'],
                isset($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null
            );

            return response()->json([
                'message' => 'Turno abierto correctamente.',
                'shift'   => $shift->load('cashRegister'),
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() === 403 ? 403 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
        }
    }

    public function close(Request $request, $id)
    {
        $validated = $request->validate([
            'actual_balance'   => 'required|numeric|min:0',
            'closer_user_id'   => 'nullable|exists:users,id',
        ]);

        // Prioridad: auth()->id() si hay sesión, sino viene del body (PIN auth sin tokens)
        $closerUserId = auth()->id() ?? $validated['closer_user_id'] ?? null;

        try {
            $shift = $this->cashShiftService->closeShift(
                (int)$id,
                (float)$validated['actual_balance'],
                $closerUserId ? (int)$closerUserId : null
            );

            return response()->json([
                'message' => 'Turno cerrado correctamente.',
                'shift'   => $shift->load('user', 'cashRegister', 'closedByUser'),
            ]);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error closing shift: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $status = $e->getCode() === 403 ? 403 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}
