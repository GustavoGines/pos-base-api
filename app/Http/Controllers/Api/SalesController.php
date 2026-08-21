<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    /**
     * GET /api/sales
     * Devuelve las ventas del turno de caja activo (o las del día si se especifica un shift_id).
     */
    public function index(Request $request)
    {
        $shiftId = $request->query('shift_id');
        $period = $request->query('period', 'shift');
        $userId = $request->query('user_id');

        $query = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight',
            'user:id,name',
            'cashier:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
        ])
        ->where('status', '!=', 'pending')
        ->latest();

        if ($period === 'today') {
            $query->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
        } elseif ($period === 'yesterday') {
            $query->whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()]);
        } elseif ($period === 'week') {
            $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'month') {
            $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($period === 'year') {
            $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]);
        } elseif ($period === 'all') {
            // Sin filtro de fecha
        } else {
            // Comportamiento por defecto ('shift')
            if ($shiftId) {
                $query->where('cash_shift_id', $shiftId);
            } else {
                // En un entorno Multi-Caja, si no envían shiftId, significa que la terminal 
                // actual no tiene un turno abierto. No debemos adivinar usando el último turno global.
                return response()->json([]);
            }
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/sales/{sale}
     * Devuelve una venta específica con todos sus ítems, pagos y cliente.
     */
    public function show(Sale $sale)
    {
        $sale->load([
            'items.product:id,name,internal_code,is_sold_by_weight',
            'user:id,name',
            'customer:id,name,document_number',
            'payments.paymentMethod:id,name,code,is_cash',
        ]);

        return response()->json($sale);
    }

    /**
     * GET /api/sales/pending
     * Devuelve todos los tickets en espera (status = 'pending').
     */
    public function pending()
    {
        $sales = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight',
            'user:id,name',
            'cashier:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
        ])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($sales);
    }

    /**
     * PUT /api/sales/{sale}/pay
     * Cobra una venta en espera: cambia status a 'completed' y registra el pago.
     */
    public function pay(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'payments'               => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount'      => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount'     => 'required|numeric|min:0',
            'total_surcharge'        => 'required|numeric|min:0',
            'shipping_cost'          => 'nullable|numeric|min:0',
            'tendered_amount'        => 'nullable|numeric|min:0',
            'change_amount'          => 'nullable|numeric',
            'items'                  => 'nullable|array',
            'items.*.product_id'     => 'required_with:items|integer|exists:products,id',
            'items.*.quantity'       => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price'     => 'required_with:items|numeric',
            'items.*.subtotal'       => 'required_with:items|numeric',
            'user_id'                => 'nullable|integer|exists:users,id',
            'cash_shift_id'          => 'nullable|integer|exists:cash_shifts,id',
        ]);

        $response = DB::transaction(function () use ($validated, $sale, $request) {
            // Recargar el modelo bloqueando la fila para evitar cobros dobles simultáneos (Multi-Caja)
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status !== 'pending') {
                return ['error' => true, 'message' => 'Esta venta ya no está en estado pendiente. Es posible que haya sido cobrada o anulada desde otra terminal.'];
            }

            $userId = $validated['user_id'] ?? $request->input('user_id') ?? $request->attributes->get('authenticated_user')?->id;

            // Si el cliente envía 'items', hubo Order Recall y modificaciones
            if (isset($validated['items'])) {
                $originalQuantities = [];
                foreach ($lockedSale->items as $oldItem) {
                    $originalQuantities[$oldItem->product_id] = ($originalQuantities[$oldItem->product_id] ?? 0) + $oldItem->quantity;
                }

                $newQuantities = [];
                $newTotal = 0.0;
                foreach ($validated['items'] as $newItem) {
                    $newQuantities[$newItem['product_id']] = ($newQuantities[$newItem['product_id']] ?? 0) + $newItem['quantity'];
                    $newTotal += $newItem['subtotal'];
                }

                // Reconciliación de stock
                $allProductIds = array_unique(array_merge(array_keys($originalQuantities), array_keys($newQuantities)));
                foreach ($allProductIds as $productId) {
                    $oldQty = $originalQuantities[$productId] ?? 0;
                    $newQty = $newQuantities[$productId] ?? 0;
                    $diff = $newQty - $oldQty; // Positivo = se agregaron más, Negativo = quitó
                    
                    if ($diff != 0) {
                        $product = \App\Models\Product::find($productId);
                        if ($product) {
                            $product->stock -= $diff;
                            $product->save();

                            \App\Models\StockMovement::create([
                                'product_id' => $productId,
                                'user_id'    => $userId,
                                'type'       => $diff > 0 ? 'sale' : 'in',
                                'quantity'   => $diff > 0 ? -$diff : abs($diff),
                                'notes'      => sprintf(
                                    "Ajuste Recall Venta #%d (Modificó de %g a %g)", 
                                    $lockedSale->id, $oldQty, $newQty
                                ),
                            ]);
                        }
                    }
                }

                // Borrar items viejos y reinsertar los nuevos
                $lockedSale->items()->delete();
                foreach ($validated['items'] as $itemData) {
                    $product = \App\Models\Product::find($itemData['product_id']);
                    if ($product) {
                        // Determinar el costo histórico
                        $currentCostPrice = 0;
                        if ($product->is_combo) {
                            $combos = \Illuminate\Support\Facades\DB::table('product_combos')->where('parent_product_id', $product->id)->get();
                            foreach ($combos as $c) {
                                $childProd = \App\Models\Product::find($c->child_product_id);
                                if ($childProd) {
                                    $currentCostPrice += ($childProd->cost_price * $c->quantity);
                                }
                            }
                        } else {
                            $currentCostPrice = (float) $product->cost_price;
                        }

                        $lockedSale->items()->create([
                            'product_id'      => $product->id,
                            'product_name'    => $product->name,
                            'quantity'        => $itemData['quantity'],
                            'unit_cost_price' => $currentCostPrice,
                            'unit_price'      => $itemData['unit_price'],
                            'subtotal'        => $itemData['subtotal'],
                        ]);
                    }
                }

                $lockedSale->setAttribute('total', $newTotal);
            }

            foreach ($validated['payments'] as $payment) {
                $paymentMethod = \App\Models\PaymentMethod::find($payment['payment_method_id']);

                $lockedSale->payments()->create([
                    'payment_method_id' => $payment['payment_method_id'],
                    'base_amount'       => $payment['base_amount'],
                    'surcharge_amount'  => $payment['surcharge_amount'],
                    'total_amount'      => $payment['total_amount'],
                ]);

                // ── 2. Bridge de Cheque (solo si el método es 'cheque' Y vienen datos del cartón) ──
                if ($paymentMethod && $paymentMethod->code === 'cheque' && $request->has('check_details')) {
                    $cd = $request->input('check_details');
                    \App\Models\ThirdPartyCheck::create([
                        'bank_name'    => $cd['bank_name'],
                        'check_number' => $cd['check_number'],
                        'amount'       => $payment['total_amount'],
                        'issue_date'   => $cd['issue_date'],
                        'payment_date' => $cd['payment_date'],
                        'issuer_name'  => $cd['issuer_name'],
                        'issuer_cuit'  => $cd['issuer_cuit'],
                        'customer_id'  => $lockedSale->customer_id,
                        'sale_id'      => $lockedSale->id,
                        'cash_shift_id'=> $validated['cash_shift_id'] ?? $lockedSale->cash_shift_id,
                        'supplier_id'  => null,
                        'status'       => 'in_wallet',
                    ]);
                }
            }

            $currentDue = $lockedSale->amount_due > 0 ? $lockedSale->amount_due : 0;

            // Hotfix: verificar si el cliente es cuenta interna para no inflar sales_count
            $isInternalSale = $lockedSale->customer_id
                && \App\Models\Customer::where('id', $lockedSale->customer_id)
                                       ->where('is_internal_account', true)
                                       ->exists();

            // Solo incrementamos el contador de ventas para clientes reales (no cuentas internas)
            if (!$isInternalSale) {
                foreach ($lockedSale->items as $item) {
                    if ($item->product) {
                        $item->product->increment('sales_count', (int) $item->quantity);
                    }
                }
            }

            $lockedSale->update([
                'status'          => 'completed',
                'tendered_amount' => $validated['tendered_amount'] ?? $lockedSale->total,
                'change_amount'   => $validated['change_amount'] ?? 0,
                'total_surcharge' => $validated['total_surcharge'] ?? 0,
                'shipping_cost'   => $validated['shipping_cost'] ?? $lockedSale->shipping_cost,
                'cash_shift_id'   => $validated['cash_shift_id'] ?? $lockedSale->cash_shift_id,
                'cashier_id'      => $userId,
                'payment_status'  => (isset($validated['payments']) && current($validated['payments'])['payment_method_id'] === 5) ? 'pending' : 'paid', // 5 = cuenta corriente
            ]);
            
            return ['error' => false, 'sale' => $lockedSale];
        });

        if (isset($response['error']) && $response['error'] === true) {
            return response()->json(['message' => $response['message']], 422);
        }

        $completedSale = $response['sale'];

        try { broadcast(new \App\Events\DashboardUpdated()); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error("Broadcast failed: " . $e->getMessage()); }

        return response()->json([
            'message' => "Venta #{$completedSale->id} cobrada correctamente.",
            'sale'    => $completedSale->fresh()->load('items.product', 'user:id,name', 'cashier:id,name'),
        ]);
    }

    /**
     * POST /api/sales/{sale}/void
     * Anula una venta: devuelve stock a cada producto y registra movimiento de reversión.
     */
    public function void(Request $request, Sale $sale)
    {
        $userId = $request->input('user_id') ?? $request->attributes->get('authenticated_user')?->id;

        $response = DB::transaction(function () use ($sale, $userId) {
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status === 'voided') {
                return ['error' => true, 'message' => 'Esta venta ya está anulada.'];
            }
            if ($lockedSale->status !== 'pending' && $lockedSale->status !== 'completed') {
                return ['error' => true, 'message' => 'No se puede anular una venta en este estado.'];
            }

            // Buscar si hay remito
            $deliveryNote = \App\Models\DeliveryNote::with('items')->where('sale_id', $lockedSale->id)->first();

            // Devolver stock de cada ítem al producto
            foreach ($lockedSale->items as $item) {
                if ($item->product) {
                    // Calcular cuánto stock hay que devolver realmente
                    $qtyToRestore = $item->quantity;
                    
                    if ($deliveryNote) {
                        // Si hay remito, solo devolvemos lo que ya fue entregado (y por tanto descontado)
                        $dnItem = $deliveryNote->items->where('product_id', $item->product_id)->first();
                        $qtyToRestore = $dnItem ? $dnItem->quantity_delivered : 0;
                    }

                    if ($qtyToRestore > 0) {
                        if ($item->product->is_combo) {
                            $combos = DB::table('product_combos')->where('parent_product_id', $item->product_id)->get();
                            foreach ($combos as $combo) {
                                $childProd = \App\Models\Product::find($combo->child_product_id);
                                if ($childProd) {
                                    $qtyRestored = $qtyToRestore * $combo->quantity;
                                    $childProd->increment('stock', $qtyRestored);

                                    \App\Models\StockMovement::create([
                                        'product_id' => $childProd->id,
                                        'user_id'    => $userId,
                                        'type'       => 'in',
                                        'quantity'   => $qtyRestored,
                                        'notes'      => "Reversión (Combo Hijo) Venta #{$lockedSale->id}",
                                    ]);
                                }
                            }
                        } else {
                            $item->product->increment('stock', $qtyToRestore);

                            \App\Models\StockMovement::create([
                                'product_id' => $item->product_id,
                                'user_id'    => $userId,
                                'type'       => 'in',
                                'quantity'   => $qtyToRestore,
                                'notes'      => "Reversión por anulación de Venta #{$lockedSale->id}",
                            ]);
                        }
                    }

                    // Disminuir contador de ventas (siempre se revierte la cantidad vendida total)
                    $newCount = max(0, $item->product->sales_count - (int) $item->quantity);
                    $item->product->update(['sales_count' => $newCount]);
                }
            }

            // Cancelar el remito si existe
            if ($deliveryNote) {
                $deliveryNote->update(['status' => 'cancelled']);
            }

            // Revertir transacciones de Cuenta Corriente si existen
            $customerTransactions = \App\Models\CustomerTransaction::where('sale_id', $lockedSale->id)->get();
            foreach ($customerTransactions as $tx) {
                if ($tx->type === 'charge') {
                    $customer = \App\Models\Customer::lockForUpdate()->find($tx->customer_id);
                    if ($customer) {
                        $customer->balance -= $tx->amount;
                        $customer->save();

                        \App\Models\CustomerTransaction::create([
                            'customer_id'   => $customer->id,
                            'user_id'       => $userId ?? 1,
                            'sale_id'       => $lockedSale->id,
                            'type'          => 'payment', // payment cancels out the charge
                            'amount'        => $tx->amount,
                            'balance_after' => $customer->balance,
                            'description'   => "Reversión por anulación de Venta #{$lockedSale->id}",
                        ]);
                    }
                }
            }

            // Marcar la venta como anulada
            $lockedSale->update(['status' => 'voided']);
            
            return ['error' => false, 'sale' => $lockedSale];
        });

        if (isset($response['error']) && $response['error'] === true) {
            return response()->json(['message' => $response['message']], 422);
        }

        $voidedSale = $response['sale'];

        try { broadcast(new \App\Events\DashboardUpdated()); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error("Broadcast failed: " . $e->getMessage()); }

        return response()->json([
            'message' => "Venta #{$voidedSale->id} anulada correctamente. El stock fue restaurado.",
            'sale'    => $voidedSale->fresh()->load('items.product', 'user:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ]);
    }

    /**
     * GET /api/sales/{sale}/ticket-pdf
     * Descarga el PDF del comprobante/factura en formato A4
     */
    public function ticketPdf(Sale $sale)
    {
        $sale->load('items.product', 'user', 'cashier', 'customer', 'payments.paymentMethod');
        $settings = \App\Models\BusinessSetting::all()->pluck('value', 'key')->toArray();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.ticket_a4', [
            'sale' => $sale,
            'settings' => $settings
        ]);

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="ticket-'.$sale->id.'.pdf"');
    }
}

