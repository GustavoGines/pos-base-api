<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\ThirdPartyCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThirdPartyCheckController extends Controller
{
    public function index(Request $request)
    {
        $query = ThirdPartyCheck::with(['customer:id,name', 'supplier:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $checks = $query->orderBy('payment_date', 'asc')->get();

        return response()->json($checks);
    }

    public function updateStatus(Request $request, ThirdPartyCheck $check)
    {
        $request->validate([
            'status' => 'required|in:in_wallet,deposited,endorsed,rejected',
            'endorsement_note' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $check) {
            $lockedCheck = ThirdPartyCheck::lockForUpdate()->find($check->id);
            if (! $lockedCheck) {
                return response()->json([
                    'message' => 'Cheque no encontrado.',
                ], 404);
            }

            if ($lockedCheck->status === 'voided') {
                return response()->json([
                    'message' => 'No se puede modificar el estado de un cheque que ha sido anulado.',
                ], 422);
            }

            $hasActiveCashMovement = CashMovement::where('check_id', $lockedCheck->id)->whereNull('deleted_at')->exists();
            if (($lockedCheck->supplier_id !== null || $hasActiveCashMovement) && $request->status !== 'endorsed') {
                return response()->json([
                    'message' => 'El cheque está vinculado a un pago a proveedor o movimiento de caja activo. Debe anular dicho movimiento de caja para reincorporarlo a cartera.',
                ], 422);
            }

            $lockedCheck->status = $request->status;
            if ($request->status === 'in_wallet') {
                $lockedCheck->endorsement_note = null;
                $lockedCheck->supplier_id = null;
            } elseif ($request->status === 'endorsed' && $request->has('endorsement_note')) {
                $lockedCheck->endorsement_note = $request->endorsement_note;
            }

            $user = $request->user() ?? $request->attributes->get('authenticated_user');
            $lockedCheck->status_changed_by_user_id = $user?->id;
            $lockedCheck->status_authorized_by_admin_id = $request->attributes->get('authorized_by_admin_id')
                ?? ($user?->isAdmin() ? $user->id : null);

            $lockedCheck->save();
            $lockedCheck->load(['customer:id,name', 'supplier:id,name']);

            return response()->json([
                'message' => 'Estado del cheque actualizado exitosamente',
                'check' => $lockedCheck,
            ]);
        });
    }
}
