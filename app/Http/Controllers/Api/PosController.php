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

        $products = Product::where('active', true)
            ->where(function($q) use ($query) {
                $q->where('barcode', $query)
                  ->orWhere('internal_code', $query)
                  ->orWhere('name', 'like', "%{$query}%");
            })
            ->get();

        return response()->json($products);
    }

    public function processSale(Request $request)
    {
        $validated = $request->validate([
            'total'                  => 'required|numeric',
            'payment_method'         => 'required|string',
            'tendered_amount'        => 'nullable|numeric',
            'change_amount'          => 'nullable|numeric',
            'cash_register_shift_id' => 'required|integer|exists:cash_register_shifts,id',
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
            'cash_register_shift_id.exists' => 'El turno de caja no es válido o ya fue cerrado.',
            'items.*.product_id.exists' => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
        ]);

        $isCuentaCorriente = ($validated['payment_method'] === 'cuenta_corriente');

        if ($isCuentaCorriente && empty($validated['customer_id'])) {
            return response()->json([
                'message' => 'Error de validación.',
                'errors'  => ['customer_id' => ['Debe seleccionar un cliente para ventas en Cuenta Corriente.']],
            ], 422);
        }

        return DB::transaction(function () use ($validated, $isCuentaCorriente) {
            $total = (float) $validated['total'];

            $sale = Sale::create([
                'total'                  => $total,
                'payment_method'         => $validated['payment_method'],
                'payment_status'         => $isCuentaCorriente ? 'pending' : 'paid',
                'amount_due'             => $isCuentaCorriente ? $total : 0,
                'tendered_amount'        => $validated['tendered_amount'] ?? null,
                'change_amount'          => $validated['change_amount'] ?? null,
                'cash_register_shift_id' => $validated['cash_register_shift_id'],
                'user_id'                => $validated['user_id'] ?? null,
                'customer_id'            => $validated['customer_id'] ?? null,
                'status'                 => $validated['status'] ?? 'completed',
            ]);

            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $sale->items()->create([
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'quantity'     => $itemData['quantity'],
                    'unit_price'   => $itemData['unit_price'],
                    'subtotal'     => $itemData['subtotal'],
                ]);

                if (!$product->is_sold_by_weight && $product->stock < $itemData['quantity']) {
                    throw new \Exception(
                        "Stock insuficiente para '{$product->name}'. Disponible: {$product->stock}, Solicitado: {$itemData['quantity']}"
                    );
                }

                $product->stock -= $itemData['quantity'];
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
                $customer->balance += $total;
                $customer->save();

                CustomerTransaction::create([
                    'customer_id'   => $customer->id,
                    'user_id'       => $validated['user_id'] ?? 1,
                    'sale_id'       => $sale->id,
                    'type'          => 'charge',
                    'amount'        => $total,
                    'balance_after' => $customer->balance,
                    'description'   => "Venta en Cta. Cte. — Ticket #{$sale->id}",
                ]);
            }

            return response()->json([
                'message' => 'Sale processed successfully',
                'sale'    => $sale->load('items'),
            ], 201);
        });
    }
}
