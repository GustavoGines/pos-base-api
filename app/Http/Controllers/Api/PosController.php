<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
            'status'                 => 'nullable|string|in:pending,completed',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|numeric|min:0.001',
            'items.*.unit_price'     => 'required|numeric',
            'items.*.subtotal'       => 'required|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $sale = Sale::create([
                'total'                  => $validated['total'],
                'payment_method'         => $validated['payment_method'],
                'tendered_amount'        => $validated['tendered_amount'] ?? null,
                'change_amount'          => $validated['change_amount'] ?? null,
                'cash_register_shift_id' => $validated['cash_register_shift_id'],
                'user_id'                => $validated['user_id'] ?? null,
                'status'                 => $validated['status'] ?? 'completed',
            ]);

            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                
                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'subtotal' => $itemData['subtotal'],
                ]);

                // Validar stock suficiente (solo para productos vendidos por unidad)
                if (!$product->is_sold_by_weight && $product->stock < $itemData['quantity']) {
                    throw new \Exception(
                        "Stock insuficiente para '{$product->name}'. Disponible: {$product->stock}, Solicitado: {$itemData['quantity']}"
                    );
                }

                // Reduce stock
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

            return response()->json(['message' => 'Sale processed successfully', 'sale' => $sale->load('items')], 201);
        });
    }
}
