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
            'type'      => 'required|in:in,out,increment,decrement',
            'quantity'  => 'required|numeric|min:0',
            'notes'     => 'nullable|string|max:500',
            'min_stock' => 'nullable|numeric|min:0',
            'user_id'   => 'nullable|exists:users,id',
        ]);

        DB::transaction(function () use ($validated, $product, $request) {
            // Solo registramos movimiento si la cantidad es realmente mayor a cero
            if ($validated['quantity'] > 0) {
                // Mapeo de tipos para consistencia (in/increment -> in, out/decrement -> out)
                $type = in_array($validated['type'], ['in', 'increment']) ? 'in' : 'out';

                if ($type === 'in') {
                    $product->increment('stock', $validated['quantity']);
                } else {
                    // Validar stock para egresos (opcional según reglas de negocio, lo mantenemos por seguridad)
                    if ($product->stock < $validated['quantity']) {
                        abort(422, "Stock insuficiente. Stock actual: {$product->stock}");
                    }
                    $product->decrement('stock', $validated['quantity']);
                }

                // Registrar el movimiento solo si hubo cambio físico de stock
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id'    => $validated['user_id'] ?? $request->attributes->get('authenticated_user')?->id,
                    'type'       => $type,
                    'quantity'   => $validated['quantity'],
                    'notes'      => $validated['notes'] ?? 'Ajuste manual desde panel de control',
                ]);
            }

            // Si se envió un nuevo stock mínimo, lo actualizamos siempre (independiente de la cantidad)
            if (array_key_exists('min_stock', $validated)) {
                $product->min_stock = $validated['min_stock'];
                $product->save();
            }
        });

        $product->refresh();

        return response()->json([
            'message'      => 'Gestión de stock procesada con éxito.',
            'product'      => $product->load(['category', 'brand', 'supplier']), // Enviamos el objeto completo para el frontend
            'new_stock'    => $product->stock,
        ]);
    }
}
