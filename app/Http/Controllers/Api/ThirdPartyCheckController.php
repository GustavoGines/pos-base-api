<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use App\Models\User;
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

            $previousStatus = $lockedCheck->status;
            $newStatus = $request->status;

            $lockedCheck->status = $newStatus;
            if ($newStatus === 'in_wallet') {
                $lockedCheck->endorsement_note = null;
                $lockedCheck->supplier_id = null;
            } elseif ($newStatus === 'endorsed' && $request->has('endorsement_note')) {
                $lockedCheck->endorsement_note = $request->endorsement_note;
            }

            $user = $request->user() ?? $request->attributes->get('authenticated_user');
            $actingUserId = $user?->id ?? User::first()?->id ?? 1;
            $lockedCheck->status_changed_by_user_id = $user?->id;
            $lockedCheck->status_authorized_by_admin_id = $request->attributes->get('authorized_by_admin_id')
                ?? ($user?->isAdmin() ? $user->id : null);

            $lockedCheck->save();

            // CC-11: Si el cheque pertenece a un cliente y pasa a estado 'rejected' (o se revierte desde 'rejected'),
            // re-imputar (o des-imputar) la deuda en su Cuenta Corriente y actualizar el asiento en el Ledger.
            if ($lockedCheck->customer_id !== null && $previousStatus !== $newStatus) {
                $lockedCustomer = Customer::where('id', $lockedCheck->customer_id)->lockForUpdate()->first();
                if ($lockedCustomer) {
                    $checkAmount = round((float) $lockedCheck->amount, 2);

                    if ($previousStatus !== 'rejected' && $newStatus === 'rejected') {
                        $lockedCustomer->balance = round((float) $lockedCustomer->balance + $checkAmount, 2);
                        $lockedCustomer->save();

                        if ($lockedCheck->sale_id) {
                            $linkedSale = Sale::where('id', $lockedCheck->sale_id)
                                ->where('customer_id', $lockedCustomer->id)
                                ->where('status', 'completed')
                                ->lockForUpdate()
                                ->first();
                            if ($linkedSale) {
                                $saleGrandTotal = round((float) $linkedSale->total + (float) $linkedSale->total_surcharge + (float) $linkedSale->shipping_cost, 2);
                                $newAmountDue = min($saleGrandTotal, round((float) $linkedSale->amount_due + $checkAmount, 2));
                                $linkedSale->amount_due = $newAmountDue;
                                $linkedSale->payment_status = $newAmountDue >= ($saleGrandTotal - 0.1) ? 'pending' : 'partial';
                                $linkedSale->save();
                            }
                        }

                        CustomerTransaction::create([
                            'customer_id' => $lockedCustomer->id,
                            'user_id' => $actingUserId,
                            'cash_shift_id' => $lockedCheck->cash_shift_id,
                            'sale_id' => $lockedCheck->sale_id,
                            'type' => 'charge',
                            'payment_method' => 'cheque',
                            'amount' => $checkAmount,
                            'balance_after' => $lockedCustomer->balance,
                            'description' => "Rechazo bancario de Cheque #{$lockedCheck->check_number} ({$lockedCheck->bank_name})",
                        ]);
                    } elseif ($previousStatus === 'rejected' && $newStatus !== 'rejected') {
                        $lockedCustomer->balance = round((float) $lockedCustomer->balance - $checkAmount, 2);
                        $lockedCustomer->save();

                        if ($lockedCheck->sale_id) {
                            $linkedSale = Sale::where('id', $lockedCheck->sale_id)
                                ->where('customer_id', $lockedCustomer->id)
                                ->where('status', 'completed')
                                ->lockForUpdate()
                                ->first();
                            if ($linkedSale && (float) $linkedSale->amount_due > 0) {
                                $newAmountDue = max(0.0, round((float) $linkedSale->amount_due - $checkAmount, 2));
                                $linkedSale->amount_due = $newAmountDue;
                                $linkedSale->payment_status = $newAmountDue <= 0.001 ? 'paid' : 'partial';
                                $linkedSale->save();
                            }
                        }

                        CustomerTransaction::create([
                            'customer_id' => $lockedCustomer->id,
                            'user_id' => $actingUserId,
                            'cash_shift_id' => $lockedCheck->cash_shift_id,
                            'sale_id' => $lockedCheck->sale_id,
                            'type' => 'payment',
                            'payment_method' => 'cheque',
                            'amount' => $checkAmount,
                            'balance_after' => $lockedCustomer->balance,
                            'description' => "Reversión de rechazo bancario de Cheque #{$lockedCheck->check_number} ({$lockedCheck->bank_name})",
                        ]);
                    }
                }
            }

            $lockedCheck->load(['customer:id,name', 'supplier:id,name']);

            return response()->json([
                'message' => 'Estado del cheque actualizado exitosamente',
                'check' => $lockedCheck,
            ]);
        });
    }
}
