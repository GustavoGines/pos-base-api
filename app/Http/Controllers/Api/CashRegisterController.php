<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CashRegisterController extends Controller
{
    /**
     * Get all shifts (audit history).
     */
    public function index()
    {
        $shifts = CashRegisterShift::with('user')->orderBy('id', 'desc')->paginate(50);
        return response()->json($shifts);
    }

    /**
     * Get the currently active (open) cash register shift.
     * Returns null/empty if the register is closed.
     */
    public function current()
    {
        $shift = CashRegisterShift::where('status', 'open')->first();
        
        if ($shift) {
            return response()->json($shift);
        }

        return response()->json(null);
    }

    /**
     * Open a new cash register shift
     */
    public function open(Request $request)
    {
        $validated = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'user_id' => 'required|exists:users,id',
        ]);

        // Check if there's already an open shift
        $activeShift = CashRegisterShift::where('status', 'open')->first();
        if ($activeShift) {
            return response()->json(['message' => 'Cash register is already open.', 'shift' => $activeShift], 400);
        }

        $shift = CashRegisterShift::create([
            'opened_at' => Carbon::now(),
            'opening_balance' => $validated['opening_balance'],
            'user_id' => $validated['user_id'],
            'status' => 'open'
        ]);

        return response()->json($shift, 201);
    }

    /**
     * Close the currently active cash register shift
     */
    public function close(Request $request)
    {
        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
        ]);

        $activeShift = CashRegisterShift::where('status', 'open')->first();
        if (!$activeShift) {
            return response()->json(['message' => 'No active cash register shift found.'], 400);
        }

        // Calculate total sales for this shift (using correct FK name)
        $totalSales = \App\Models\Sale::where('cash_register_shift_id', $activeShift->id)->sum('total');

        // Calculate expected cash in drawer
        $expectedCash = $activeShift->opening_balance + $totalSales;

        // Calculate the difference (faltante o sobrante)
        $difference = $validated['counted_cash'] - $expectedCash;

        $activeShift->update([
            'closed_at' => Carbon::now(),
            'closing_balance' => $validated['counted_cash'],
            'total_sales' => $totalSales,     // Ensure these columns exist in migration
            'difference' => $difference,       // Ensure these columns exist in migration
            'status' => 'closed'
        ]);

        return response()->json($activeShift);
    }
}
