<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\BatchStockService;
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

        try {
            DB::transaction(function () use ($validated, $product, $request) {
                $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

                if ($lockedProduct->is_combo) {
                    abort(422, 'No es posible ajustar el stock directamente sobre un producto combo. Modifique el stock de sus ingredientes.');
                }

                // Solo registramos movimiento si la cantidad es realmente mayor a cero
                if ($validated['quantity'] > 0) {
                    // Mapeo de tipos para consistencia (in/increment -> in, out/decrement -> out)
                    $type = in_array($validated['type'], ['in', 'increment']) ? 'in' : 'out';
                    $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                    $userId = $actorUser?->id ?? ($validated['user_id'] ?? null);
                    $adminId = $request->attributes->get('authorized_by_admin_id')
                        ?? ($actorUser?->isAdmin() ? $actorUser->id : null);
                    $notes = $validated['notes'] ?? 'Ajuste manual desde panel de control';

                    $hasExplicitLotInput = $type === 'in'
                        && (
                            ! empty(trim((string) ($validated['batch_code'] ?? '')))
                            || ! empty($validated['expiration_date'])
                        );

                    if (BatchStockService::hasLotTrackingSchema()
                        && ! $lockedProduct->is_service
                        && BusinessSetting::canUseBatchExpirationForProduct($lockedProduct)
                        && ($lockedProduct->track_batches || $hasExplicitLotInput)) {
                        if (! $lockedProduct->track_batches) {
                            $lockedProduct->track_batches = true;
                            $lockedProduct->save();
                        }
                        $batchService = app(BatchStockService::class);
                        $batchService->ensureInitialBatchOnEnable($lockedProduct, $userId);

                        if ($type === 'in') {
                            $batchService->receiveStockBatch($lockedProduct, (float) $validated['quantity'], [
                                'source_type' => $validated['reason_code'] ?? ProductBatch::SOURCE_ADJUSTMENT,
                                'batch_code' => $validated['batch_code'] ?? null,
                                'manufactured_at' => $validated['manufactured_at'] ?? null,
                                'expiration_date' => $validated['expiration_date'] ?? null,
                                'unit_cost' => $validated['unit_cost'] ?? null,
                                'user_id' => $userId,
                                'authorized_by_admin_id' => $adminId,
                                'movement_type' => 'in',
                                'movement_notes' => $notes,
                            ]);
                        } else {
                            if (($validated['reason_code'] ?? null) === 'waste' && ! empty($validated['batch_id'])) {
                                $batch = ProductBatch::where('product_id', $lockedProduct->id)
                                    ->findOrFail((int) $validated['batch_id']);
                                $batchService->writeOffBatch($batch, (float) $validated['quantity'], [
                                    'user_id' => $userId,
                                    'authorized_by_admin_id' => $adminId,
                                    'notes' => $notes,
                                ]);
                            } else {
                                $batchService->deductStockFEFO($lockedProduct, (float) $validated['quantity'], [
                                    'explicit_batch_id' => $validated['batch_id'] ?? null,
                                    'user_id' => $userId,
                                    'authorized_by_admin_id' => $adminId,
                                    'allow_deficit' => false,
                                    'ignore_expired_guard' => true,
                                    'movement_type' => ($validated['reason_code'] ?? null) === 'waste' ? 'waste' : 'out',
                                    'movement_notes' => $notes,
                                ]);
                            }
                        }
                    } else {
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

                        $isWasteOut = ($validated['reason_code'] ?? null) === 'waste' && $type === 'out';

                        // Registrar el movimiento solo si hubo cambio físico de stock
                        StockMovement::create([
                            'product_id' => $lockedProduct->id,
                            'user_id' => $userId,
                            'authorized_by_admin_id' => $adminId,
                            'type' => $isWasteOut ? 'waste' : $type,
                            'quantity' => $isWasteOut ? -abs((float) $validated['quantity']) : $validated['quantity'],
                            'unit_cost' => $lockedProduct->cost_price,
                            'notes' => $notes,
                        ]);
                    }
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
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->refresh();

        $relations = ['category.rubro', 'brand', 'supplier', 'children', 'priceTiers'];
        if (BatchStockService::hasProductionRecipeSchema()) {
            $ingCols = 'id,name,internal_code,cost_price,stock,unit_type,is_sold_by_weight';
            if (BatchStockService::hasLotTrackingSchema()) {
                $ingCols .= ',track_batches';
            }
            $relations[] = "recipeItems.ingredient:{$ingCols}";
        }

        return response()->json([
            'message' => 'Gestión de stock procesada con éxito.',
            'product' => $product->load($relations), // Enviamos el objeto completo para el frontend
            'new_stock' => $product->stock,
        ]);
    }

    /**
     * Historial de Movimientos de Stock (Kardex).
     * GET /api/audit/stock
     */
    public function kardex(Request $request)
    {
        $query = StockMovement::with(['product', 'batch:id,batch_code,expiration_date', 'user', 'authorizedByAdmin'])->latest();

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', (int) $request->query('batch_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
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
