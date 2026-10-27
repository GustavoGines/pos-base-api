<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Jobs\SyncProductStockToPlatformsJob;
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
    public function adjust(AdjustStockRequest $request, Product $product)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product, $request) {
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if ($lockedProduct->is_combo) {
                abort(422, 'No es posible ajustar el stock directamente sobre un producto combo. Modifique el stock de sus ingredientes.');
            }

            // Solo registramos movimiento si la cantidad es realmente mayor a cero
            if ($validated['quantity'] > 0) {
                // Mapeo de tipos para consistencia (in/increment -> in, out/decrement -> out)
                $type = in_array($validated['type'], ['in', 'increment']) ? 'in' : 'out';

                if ($type === 'in') {
                    $lockedProduct->stock += $validated['quantity'];
                    $lockedProduct->save();
                } else {
                    // Validar stock para egresos
                    if ($lockedProduct->stock < $validated['quantity']) {
                        abort(422, "Stock insuficiente. Stock actual: {$lockedProduct->stock}");
                    }
                    $lockedProduct->stock -= $validated['quantity'];
                    $lockedProduct->save();
                }

                // Registrar el movimiento solo si hubo cambio físico de stock
                $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                StockMovement::create([
                    'product_id' => $lockedProduct->id,
                    'user_id' => $actorUser?->id ?? ($validated['user_id'] ?? null),
                    'authorized_by_admin_id' => $request->attributes->get('authorized_by_admin_id')
                        ?? ($actorUser?->isAdmin() ? $actorUser->id : null),
                    'type' => $type,
                    'quantity' => $validated['quantity'],
                    'notes' => $validated['notes'] ?? 'Ajuste manual desde panel de control',
                ]);
            }

            // Si se envió un nuevo stock mínimo, lo actualizamos siempre (independiente de la cantidad)
            if (array_key_exists('min_stock', $validated)) {
                $lockedProduct->min_stock = $validated['min_stock'];
                $lockedProduct->save();
            }

            // FINDING-STOCK-01: Sincronizar stock y propagar combos padres hacia plataformas e-commerce
            if ($validated['quantity'] > 0) {
                $affectedCombos = app(\App\Services\StockService::class)->recalculateAndGetAffectedParentCombos([$lockedProduct->id]);
                $allToSync = array_values(array_unique(array_merge([$lockedProduct->id], $affectedCombos)));
                SyncProductStockToPlatformsJob::dispatchForProducts($allToSync);
            }
        });

        $product->refresh();

        return response()->json([
            'message' => 'Gestión de stock procesada con éxito.',
            'product' => $product->load(['category', 'brand', 'supplier']), // Enviamos el objeto completo para el frontend
            'new_stock' => $product->stock,
        ]);
    }

    /**
     * Historial de Movimientos de Stock (Kardex).
     * GET /api/audit/stock
     */
    public function kardex(Request $request)
    {
        $query = StockMovement::with(['product', 'user'])->latest();

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date.' 00:00:00',
                $request->end_date.' 23:59:59',
            ]);
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }
}
