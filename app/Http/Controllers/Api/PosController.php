<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
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
            ->get();

        return response()->json($products);
    }

    public function processSale(Request $request)
    {
        $validated = $request->validate([
            'total'                  => 'required|numeric',
            'total_surcharge'        => 'required|numeric|min:0',
            'payments'               => 'exclude_if:status,pending|required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount'      => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount'     => 'required|numeric|min:0',
            'tendered_amount'        => 'nullable|numeric',
            'change_amount'          => 'nullable|numeric',
            'cash_shift_id'          => 'required|integer|exists:cash_shifts,id',
            'user_id'                => 'nullable|integer|exists:users,id',
            'customer_id'            => 'nullable|integer|exists:customers,id',
            'status'                 => 'nullable|string|in:pending,completed',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|numeric|min:0.001',
            'items.*.unit_price'     => 'required|numeric',
            'items.*.subtotal'       => 'required|numeric',
        ], [
            'customer_id.exists' => 'El cliente seleccionado no existe en el sistema.',
            'user_id.exists' => 'El cajero actual no está registrado en el sistema. Inicie sesión nuevamente.',
            'cash_shift_id.exists'         => 'El turno de caja no es válido o ya fue cerrado.',
            'items.*.product_id.exists' => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
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

        return DB::transaction(function () use ($validated, $isCuentaCorriente, $isPendingSale, $ccPaymentTotal) {
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
                'status'                 => $validated['status'] ?? 'completed',
            ]);

            if (!$isPendingSale) {
                foreach ($validated['payments'] as $payment) {
                    $sale->payments()->create([
                        'payment_method_id' => $payment['payment_method_id'],
                        'base_amount'       => $payment['base_amount'],
                        'surcharge_amount'  => $payment['surcharge_amount'],
                        'total_amount'      => $payment['total_amount'],
                    ]);
                }
            }

            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $sale->items()->create([
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'quantity'     => $itemData['quantity'],
                    'unit_price'   => $itemData['unit_price'],
                    'subtotal'     => $itemData['subtotal'],
                ]);



                $product->stock -= $itemData['quantity'];
                $product->sales_count += (int) $itemData['quantity'];
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id'    => $validated['user_id'] ?? null,
                    'type'       => 'sale',
                    'quantity'   => -$itemData['quantity'],
                    'notes'      => "Sale #{$sale->id}"
                ]);
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

            return response()->json([
                'message' => 'Sale processed successfully',
                'sale'    => $sale->load('items', 'payments.paymentMethod'),
            ], 201);
        });
    }
}
