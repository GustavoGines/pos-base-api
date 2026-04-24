<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Quote;
use App\Models\ThirdPartyCheck;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function searchProducts(Request $request)
    {
        $query = $request->query('query');
        if (!$query) {
            return response()->json([]);
        }

        $plu = is_numeric($query) ? str_pad($query, 5, '0', STR_PAD_LEFT) : $query;

        $products = Product::where('active', true)
            ->where(function($q) use ($query, $plu) {
                $q->where('barcode', $query)
                  ->orWhere('internal_code', $query)
                  ->orWhere('internal_code', $plu)
                  ->orWhere('name', 'like', "%{$query}%");
                
                if (is_numeric($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->with(['children', 'priceTiers'])
            ->get();

        return response()->json($products);
    }

    public function processSale(Request $request)
    {
        $validated = $request->validate([
            'total'                  => 'required|numeric',
            'total_surcharge'        => 'required|numeric|min:0',
            'shipping_cost'          => 'nullable|numeric|min:0',
            'payments'               => 'exclude_if:status,pending|required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount'      => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount'     => 'required|numeric|min:0',
            'tendered_amount'        => 'nullable|numeric',
            'change_amount'          => 'nullable|numeric',
            'cash_shift_id'          => [
                'required',
                'integer',
                Rule::exists('cash_shifts', 'id')->where('status', 'open'),
            ],
            'user_id'                => 'nullable|integer|exists:users,id',
            'customer_id'            => 'nullable|integer|exists:customers,id',
            'delivery_address'       => 'nullable|string|max:500',
            'status'                 => 'nullable|string|in:pending,completed',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|numeric|min:0.001',
            'items.*.unit_price'     => 'required|numeric',
            'items.*.subtotal'       => 'required|numeric',
            'quote_id'               => 'nullable|integer|exists:quotes,id',
        ], [
            'cash_shift_id.exists'         => 'El turno de caja ya fue cerrado. Por favor, recargue la aplicación.',
            'customer_id.exists'           => 'El cliente seleccionado no existe en el sistema.',
            'user_id.exists'               => 'El cajero actual no está registrado en el sistema. Inicie sesión nuevamente.',
            'items.*.product_id.exists'    => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
        ]);

        $isPendingSale = ($validated['status'] ?? 'completed') === 'pending';

        $ccPaymentTotal = 0;
        $isCuentaCorriente = false;
        
        if (!$isPendingSale) {
            $ccPaymentTotal = collect($validated['payments'])->filter(function ($p) {
                $method = \App\Models\PaymentMethod::find($p['payment_method_id']);
                return $method && $method->code === 'cuenta_corriente';
            })->sum('total_amount');

            $isCuentaCorriente = $ccPaymentTotal > 0;
        }

        if ($isCuentaCorriente && empty($validated['customer_id'])) {
            return response()->json([
                'message' => 'Error de validación.',
                'errors'  => ['customer_id' => ['Debe seleccionar un cliente para ventas en Cuenta Corriente.']],
            ], 422);
        }

        return DB::transaction(function () use ($validated, $isCuentaCorriente, $isPendingSale, $ccPaymentTotal, $request) {
            $total = (float) $validated['total'];
            $totalSurcharge = (float) $validated['total_surcharge'];

            $sale = Sale::create([
                'total'                  => $total,
                'total_surcharge'        => $totalSurcharge,
                'payment_status'         => $isPendingSale ? 'pending' : ($isCuentaCorriente && $ccPaymentTotal >= ($total + $totalSurcharge) ? 'pending' : 'paid'),
                'amount_due'             => $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total : 0),
                'tendered_amount'        => $validated['tendered_amount'] ?? null,
                'change_amount'          => $validated['change_amount'] ?? null,
                'cash_shift_id'          => $validated['cash_shift_id'],
                'user_id'                => $validated['user_id'] ?? null,
                'customer_id'            => $validated['customer_id'] ?? null,
                'delivery_address'       => $validated['delivery_address'] ?? null,
                'status'                 => $validated['status'] ?? 'completed',
                'shipping_cost'          => $validated['shipping_cost'] ?? 0,
            ]);

            if (!$isPendingSale) {
                foreach ($validated['payments'] as $payment) {
                    $paymentMethod = \App\Models\PaymentMethod::find($payment['payment_method_id']);

                    // ── 1. Registro genérico SalePayment (siempre) ──
                    $sale->payments()->create([
                        'payment_method_id' => $payment['payment_method_id'],
                        'base_amount'       => $payment['base_amount'],
                        'surcharge_amount'  => $payment['surcharge_amount'],
                        'total_amount'      => $payment['total_amount'],
                    ]);

                    // ── 2. Bridge de Cheque (solo si el método es 'cheque' Y vienen datos del cartón) ──
                    // SEGURIDAD: Dos condiciones simultáneas. Un pago en efectivo nunca las satisface.
                    if ($paymentMethod && $paymentMethod->code === 'cheque' && $request->has('check_details')) {
                        $cd = $request->input('check_details');
                        ThirdPartyCheck::create([
                            'bank_name'    => $cd['bank_name'],
                            'check_number' => $cd['check_number'],
                            'amount'       => $payment['total_amount'], // importe ya calculado con recargo
                            'issue_date'   => $cd['issue_date'],
                            'payment_date' => $cd['payment_date'],
                            'issuer_name'  => $cd['issuer_name'],
                            'issuer_cuit'  => $cd['issuer_cuit'],
                            'customer_id'  => $sale->customer_id,
                            'sale_id'      => $sale->id,
                            'cash_shift_id'=> $validated['cash_shift_id'],
                            'supplier_id'  => null,
                            'status'       => 'in_wallet',
                        ]);
                    }
                }
            }

            $requiresDispatch = $request->input('requires_dispatch', false);
            $fulfillmentStatus = $request->input('fulfillment_status', 'pending');

            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                // Determinar el costo histórico
                $currentCostPrice = 0;
                if ($product->is_combo) {
                    $combos = DB::table('product_combos')->where('parent_product_id', $product->id)->get();
                    foreach ($combos as $c) {
                        $childProd = Product::find($c->child_product_id);
                        if ($childProd) {
                            $currentCostPrice += ($childProd->cost_price * $c->quantity);
                        }
                    }
                } else {
                    $currentCostPrice = (float) $product->cost_price;
                }

                // === MOTOR DE PRECIOS VOLUMÉTRICOS ===
                $expectedUnitPrice = $product->getPriceForQuantity((float) $itemData['quantity']);

                $saleItem = $sale->items()->create([
                    'product_id'      => $product->id,
                    'product_name'    => $product->name,
                    'quantity'        => $itemData['quantity'],
                    'unit_cost_price' => $currentCostPrice,
                    'unit_price'   => $itemData['unit_price'],
                    'subtotal'     => $itemData['subtotal'],
                ]);

                // Lógica de Descuento de Stock:
                // SOLO descontamos stock de INMEDIATO si:
                // 1) NO lleva remito (requiresDispatch == false)
                // 2) SÍ lleva remito, pero es "Se lo lleva AHORA" (fulfillmentStatus == 'delivered')
                $shouldDeductStock = (!$requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');

                if ($shouldDeductStock) {
                    // Si es un combo, descontar de los ingredientes (children)
                    if ($product->is_combo) {
                        $combos = DB::table('product_combos')->where('parent_product_id', $product->id)->get();
                        foreach ($combos as $combo) {
                            $childProd = Product::findOrFail($combo->child_product_id);
                            $qtyDeducted = $itemData['quantity'] * $combo->quantity;
                            
                            $childProd->stock -= $qtyDeducted;
                            $childProd->save();

                            StockMovement::create([
                                'product_id' => $childProd->id,
                                'user_id'    => $validated['user_id'] ?? null,
                                'type'       => 'sale',
                                'quantity'   => -$qtyDeducted,
                                'notes'      => "Venta #{$sale->id} (Hijo del Combo: {$product->name})"
                            ]);
                        }
                        
                        // Al producto Padre/Combo solo le subimos el contador estadístico de ventas
                        $product->sales_count += (int) $itemData['quantity'];
                        $product->save();
                        
                    } else {
                        // Producto normal unitario
                        $product->stock -= $itemData['quantity'];
                        $product->sales_count += (int) $itemData['quantity'];
                        $product->save();

                        StockMovement::create([
                            'product_id' => $product->id,
                            'user_id'    => $validated['user_id'] ?? null,
                            'type'       => 'sale',
                            'quantity'   => -$itemData['quantity'],
                            'notes'      => "Venta #{$sale->id}"
                        ]);
                    }
                }
            }

            // Crear el remito asociado a la venta
            if ($requiresDispatch) {
                $deliveryNote = \App\Models\DeliveryNote::create([
                    'sale_id' => $sale->id,
                    'status'  => $fulfillmentStatus, // 'pending' o 'delivered'
                    'notes'   => 'Generado automáticamente desde Checkout.',
                ]);

                foreach ($validated['items'] as $itemData) {
                    \App\Models\DeliveryNoteItem::create([
                        'delivery_note_id'   => $deliveryNote->id,
                        'product_id'         => $itemData['product_id'],
                        'quantity_purchased' => $itemData['quantity'],
                        'quantity_delivered' => $fulfillmentStatus === 'delivered' ? $itemData['quantity'] : 0,
                    ]);
                }
            }

            // Si es cuenta corriente, registrar la deuda en Customer + Ledger
            if ($isCuentaCorriente && !empty($validated['customer_id'])) {
                $customer = Customer::lockForUpdate()->find($validated['customer_id']);
                $customer->balance += $ccPaymentTotal;
                $customer->save();

                CustomerTransaction::create([
                    'customer_id'   => $customer->id,
                    'user_id'       => $validated['user_id'] ?? 1,
                    'sale_id'       => $sale->id,
                    'type'          => 'charge',
                    'amount'        => $ccPaymentTotal,
                    'balance_after' => $customer->balance,
                    'description'   => "Venta en Cta. Cte. — Ticket #{$sale->id}",
                ]);
            }

            // Si proviene de un presupuesto, cerrarlo
            if (!empty($validated['quote_id'])) {
                $quote = Quote::find($validated['quote_id']);
                if ($quote && $quote->status !== 'closed') {
                    $quote->update(['status' => 'closed']);
                }
            }

            return response()->json([
                'message' => 'Sale processed successfully',
                'sale'    => $sale->load('items', 'payments.paymentMethod', 'deliveryNote', 'deliveryNote.items', 'deliveryNote.items.product'),
            ], 201);
        });
    }
}
