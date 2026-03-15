<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CashRegisterController extends Controller
{
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
        ]);

        // Check if there's already an open shift
        $activeShift = CashRegisterShift::where('status', 'open')->first();
        if ($activeShift) {
            return response()->json(['message' => 'Cash register is already open.', 'shift' => $activeShift], 400);
        }

        $shift = CashRegisterShift::create([
            'opened_at' => Carbon::now(),
            'opening_balance' => $validated['opening_balance'],
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
            'closing_balance' => 'required|numeric|min:0',
        ]);

        $activeShift = CashRegisterShift::where('status', 'open')->first();
        if (!$activeShift) {
            return response()->json(['message' => 'No active cash register shift found.'], 400);
        }

        $activeShift->update([
            'closed_at' => Carbon::now(),
            'closing_balance' => $validated['closing_balance'],
            'status' => 'closed'
        ]);

        return response()->json($activeShift);
    }
}
