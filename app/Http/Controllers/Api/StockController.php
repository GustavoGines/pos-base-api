<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    /**
     * Ajuste manual de stock (entrada o salida de mercadería).
     *
     * POST /api/catalog/products/{product}/adjust-stock
     * Body: { "type": "in"|"out", "quantity": 5.5, "notes": "Compra proveedor X" }
     */
    public function adjust(Request $request, Product $product)
    {
        $validated = $request->validate([
            'type'     => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.001',
            'notes'    => 'nullable|string|max:500',
            'user_id'  => 'nullable|exists:users,id',
        ]);

        DB::transaction(function () use ($validated, $product) {
            // Actualizar stock del producto
            if ($validated['type'] === 'in') {
                $product->increment('stock', $validated['quantity']);
            } else {
                // Validar que hay suficiente stock para egresos
                if ($product->stock < $validated['quantity']) {
                    abort(422, "Stock insuficiente. Stock actual: {$product->stock}");
                }
                $product->decrement('stock', $validated['quantity']);
            }

            // Registrar el movimiento en el historial
            StockMovement::create([
                'product_id' => $product->id,
                'user_id'    => $validated['user_id'] ?? null,
                'type'       => $validated['type'],
                'quantity'   => $validated['quantity'],
                'notes'      => $validated['notes'] ?? null,
            ]);
        });

        $product->refresh();

        return response()->json([
            'message'      => 'Stock ajustado correctamente.',
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'new_stock'    => $product->stock,
            'movement_type'=> $validated['type'],
            'quantity'     => $validated['quantity'],
        ]);
    }
}
