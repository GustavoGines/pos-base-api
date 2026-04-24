<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ThirdPartyCheck;
use Illuminate\Http\Request;

class ThirdPartyCheckController extends Controller
{
    public function index(Request $request)
    {
        $checks = ThirdPartyCheck::with('customer:id,name')
            ->orderBy('payment_date', 'asc')
            ->get();
            
        return response()->json($checks);
    }

    public function updateStatus(Request $request, ThirdPartyCheck $check)
    {
        $request->validate([
            'status' => 'required|in:in_wallet,deposited,endorsed,rejected',
            'endorsement_note' => 'nullable|string|max:255',
        ]);

        $check->status = $request->status;
        if ($request->status === 'endorsed' && $request->has('endorsement_note')) {
            $check->endorsement_note = $request->endorsement_note;
        }

        $check->save();

        return response()->json([
            'message' => 'Estado del cheque actualizado exitosamente',
            'check' => $check
        ]);
    }
}
