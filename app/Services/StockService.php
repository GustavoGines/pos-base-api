<?php

namespace App\Services;

use App\DTOs\SaleContextDTO;
use App\Models\BusinessSetting;
use App\Models\DeliveryNote;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(
        protected ?BatchStockService $batchStockService = null
    ) {}

    protected function batchService(): BatchStockService
    {
        return $this->batchStockService ??= app(BatchStockService::class);
    }

    protected function shouldTrackBatchesFor(Product $product): bool
    {
        return BatchStockService::hasLotTrackingSchema()
            && (bool) $product->track_batches
            && BusinessSetting::canUseBatchExpirationForProduct($product);
    }

    protected function hasSaleBatchAllocations(Sale $sale, Product $product): bool
    {
        return BatchStockService::hasLotTrackingSchema()
            && \App\Models\SaleItemBatchAllocation::where('sale_id', $sale->id)
                ->where('product_id', $product->id)
                ->whereColumn('quantity', '>', 'restored_quantity')
                ->exists();
    }

    /**
     * Pre-carga y bloquea todos los productos (padres e hijos) en una sola query para evitar N+1 y Deadlocks.
     */
    public function lockProducts(array $productIds): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('lockProducts debe ser llamado dentro de una transacción activa para garantizar el Anti-Deadlock.');
        }

        $uniqueIds = array_unique(array_filter($productIds));

        if (empty($uniqueIds)) {
            return new Collection;
        }

        $childIds = DB::table('product_combos')
            ->whereIn('parent_product_id', $uniqueIds)
            ->pluck('child_product_id')
            ->toArray();

        $allIds = array_unique(array_merge($uniqueIds, $childIds));

        // Bloqueo Pesimista REAL: Delegamos a la DB el orden (orderBy) para evitar Deadlocks Circulares
        return Product::whereIn('id', $allIds)
            ->with(['children.category.rubro', 'category.rubro'])
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Deduct stock for a brand new sale or checkout.
     */
    public function processCartStock(array $items, Collection $products, Sale $sale, SaleContextDTO $context, bool $requiresDispatch, string $fulfillmentStatus): array
    {
        $shouldDeductStock = (! $requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');

        if (! $shouldDeductStock) {
            return []; // Stock will be deducted upon delivery
        }

        $metric = $this->getPopularityMetric();
        $countedInTicket = [];
        $allDeductedIds = [];
        $saleItems = $sale->items()->orderBy('id')->get()->values();

        foreach ($items as $idx => $itemData) {
            $candidateSaleItem = $saleItems->get($idx);
            $matchingSaleItem = ($candidateSaleItem && (int) ($candidateSaleItem->product_id ?? 0) === (int) ($itemData['product_id'] ?? 0))
                ? $candidateSaleItem
                : null;
            $hasCustomCost = isset($itemData['custom_cost_price'])
                && is_numeric($itemData['custom_cost_price'])
                && (float) $itemData['custom_cost_price'] >= 0;

            $isTemporary = filter_var($itemData['is_temporary'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (empty($itemData['product_id']) || $isTemporary) {
                // Si es un Combo Rápido temporal armado en POS con ingredientes reales del catálogo,
                // descontamos el stock de sus productos hijos bloqueados en $products.
                if (! empty($itemData['combo_ingredients']) && is_array($itemData['combo_ingredients'])) {
                    $comboQty = max(0.0, (float) ($itemData['quantity'] ?? 1));
                    $comboLabel = trim((string) ($itemData['custom_description'] ?? $itemData['product_name'] ?? $itemData['name'] ?? 'Combo Rápido'));
                    $quickComboCost = 0.0;
                    $quickComboUsedBatches = false;
                    foreach ($itemData['combo_ingredients'] as $ing) {
                        $childId = (int) ($ing['id'] ?? 0);
                        $ingQty = (float) ($ing['quantity'] ?? 0);
                        if ($childId > 0 && $ingQty > 0 && $comboQty > 0 && isset($products[$childId])) {
                            $childProduct = $products[$childId];
                            if (! $childProduct->is_service) {
                                $totalChildDeduct = $comboQty * $ingQty;
                                $noteText = 'Venta '.StockMovement::CHECKOUT_NOTE_TOKEN."{$sale->id} (Hijo de Combo Rápido: {$comboLabel})";
                                if ($this->shouldTrackBatchesFor($childProduct)) {
                                    $res = $this->batchService()->deductStockFEFO($childProduct, $totalChildDeduct, [
                                        'sale_id' => $sale->id,
                                        'sale_item_id' => $matchingSaleItem?->id,
                                        'user_id' => $context->userId,
                                        'authorized_by_admin_id' => $context->authorizedByAdminId,
                                        'cash_shift_id' => $context->cashShiftId,
                                        'movement_type' => 'sale',
                                        'movement_notes' => $noteText,
                                    ]);
                                    $quickComboCost += $res['weighted_unit_cost'] * $ingQty;
                                    $quickComboUsedBatches = true;
                                } else {
                                    $effectiveDelta = $this->applyStockDeltaWithFractionSnap($childProduct, -$totalChildDeduct);
                                    $childProduct->save();
                                    $this->logMovement(
                                        $childProduct->id,
                                        $effectiveDelta,
                                        'sale',
                                        $noteText,
                                        $context,
                                        $sale
                                    );
                                    $quickComboCost += ((float) $childProduct->cost_price) * $ingQty;
                                }
                                $allDeductedIds[] = $childProduct->id;
                            }
                        }
                    }

                    if ($quickComboUsedBatches && ! $hasCustomCost && $matchingSaleItem) {
                        $matchingSaleItem->update(['unit_cost_price' => round($quickComboCost, 2)]);
                    }
                }

                continue;
            }

            if (! isset($products[$itemData['product_id']])) {
                throw new \InvalidArgumentException("El producto con ID {$itemData['product_id']} ya no está disponible o fue dado de baja.");
            }

            $product = $products[$itemData['product_id']];
            $qty = (float) $itemData['quantity'];
            $explicitBatchId = ! empty($itemData['batch_id']) ? (int) $itemData['batch_id'] : null;

            // En modo 'tickets' cada producto suma como máximo 1 vez por ticket
            $trackPopularity = true;
            if ($metric === 'tickets' && $qty > 0) {
                if (isset($countedInTicket[$product->id])) {
                    $trackPopularity = false;
                } else {
                    $countedInTicket[$product->id] = true;
                }
            }

            // Si el producto fue creado al vuelo con save_to_catalog = true y stock = 0,
            // mantener stock = 0 para productos simples (o descontar ingredientes si es combo)
            if (! empty($itemData['_created_on_the_fly']) && ! $product->is_combo) {
                if ($trackPopularity && ! $context->isInternalAccount && $qty > 0) {
                    $product->sales_count += $this->popularityPoints($qty);
                    $product->save();
                }
                $allDeductedIds[] = $product->id;

                continue;
            }

            $deducted = $this->deductProductStock(
                $product,
                $qty,
                $sale,
                $context,
                false,
                $products,
                $trackPopularity,
                $matchingSaleItem,
                $explicitBatchId,
                ! $hasCustomCost
            );
            $allDeductedIds = array_merge($allDeductedIds, $deducted);
        }

        $affectedCombos = $this->recalculateAndGetAffectedParentCombos($allDeductedIds);

        return array_values(array_unique(array_merge($allDeductedIds, $affectedCombos)));
    }

    /**
     * Aplica un delta con signo al stock del producto. Si la operación involucra
     * fracciones (ej. 1/6 = 0.167 o 1/12 = 0.083) y el resultado queda a <= 0.010
     * de un número entero (ej. -0.002 o 0.004 respecto a 0.000 / 1.000),
     * ajusta (snap) el stock exactamente al entero y devuelve el delta real aplicado
     * para que el Kardex (stock_movements) cierre al milésimo con products.stock.
     */
    protected function applyStockDeltaWithFractionSnap(Product $target, float $signedDelta): float
    {
        $result = BatchStockService::computeQuantityDeltaWithFractionSnap((float) $target->stock, $signedDelta);
        $target->stock = $result['new_stock'];

        return $result['effective_delta'];
    }

    /**
     * Deduct stock mathematically for a specific product (Combo & Batch-aware)
     */
    protected function deductProductStock(
        Product $product,
        float $qty,
        Sale $sale,
        SaleContextDTO $context,
        bool $isAdjustment,
        Collection $products,
        bool $trackPopularity = true,
        ?SaleItem $saleItem = null,
        ?int $explicitBatchId = null,
        bool $updateSaleItemCost = true
    ): array {
        $deductedIds = [];
        if ($product->is_service) {
            // Los servicios / honorarios no descuentan stock físico ni registran movimientos de stock,
            // pero sí incrementan su popularidad para el Acceso Rápido POS.
        } elseif ($product->is_combo) {
            $comboWeightedCost = 0.0;
            $usedBatchChild = false;

            foreach ($product->children as $child) {
                $qtyDeducted = $qty * (float) $child->pivot->quantity;

                // CRITICAL FIX: Use canonical instance from the root $products collection
                // to prevent in-memory race conditions if the same product is processed twice.
                $canonicalChild = $products[$child->id] ?? $child;
                $note = ($isAdjustment ? 'Ajuste Recall ' : 'Venta ').StockMovement::CHECKOUT_NOTE_TOKEN."{$sale->id} (Hijo de: {$product->name})";

                if (($qtyDeducted > 0 && $this->shouldTrackBatchesFor($canonicalChild))
                    || ($qtyDeducted < 0 && ($this->shouldTrackBatchesFor($canonicalChild) || $this->hasSaleBatchAllocations($sale, $canonicalChild)))) {
                    $usedBatchChild = true;
                    if ($qtyDeducted > 0) {
                        $res = $this->batchService()->deductStockFEFO($canonicalChild, $qtyDeducted, [
                            'sale_id' => $sale->id,
                            'sale_item_id' => $saleItem?->id,
                            'user_id' => $context->userId,
                            'authorized_by_admin_id' => $context->authorizedByAdminId,
                            'cash_shift_id' => $context->cashShiftId,
                            'movement_type' => 'sale',
                            'movement_notes' => $note,
                        ]);
                        $comboWeightedCost += $res['weighted_unit_cost'] * (float) $child->pivot->quantity;
                    } elseif ($qtyDeducted < 0) {
                        $this->batchService()->restoreFromSaleAllocations($sale, $canonicalChild, abs($qtyDeducted), [
                            'user_id' => $context->userId,
                            'authorized_by_admin_id' => $context->authorizedByAdminId,
                            'cash_shift_id' => $context->cashShiftId,
                            'movement_type' => 'in',
                            'movement_notes' => "Ajuste Recall (Reversión Hijo de: {$product->name}) Venta #{$sale->id}",
                        ]);
                        $comboWeightedCost += ((float) $canonicalChild->cost_price) * (float) $child->pivot->quantity;
                    }
                } else {
                    $effectiveDelta = $this->applyStockDeltaWithFractionSnap($canonicalChild, -$qtyDeducted);
                    $canonicalChild->save(); // Dispara Observers

                    $this->logMovement(
                        $canonicalChild->id,
                        $effectiveDelta,
                        'sale',
                        $note,
                        $context,
                        $sale
                    );
                    $comboWeightedCost += ((float) $canonicalChild->cost_price) * (float) $child->pivot->quantity;
                }
                $deductedIds[] = $canonicalChild->id;
            }

            if ($usedBatchChild && $updateSaleItemCost && $saleItem && $qty > 0) {
                $saleItem->update(['unit_cost_price' => round($comboWeightedCost, 2)]);
            }
        } else {
            // Update the canonical product (which $product already is, as it comes from $products)
            if (($qty > 0 && $this->shouldTrackBatchesFor($product))
                || ($qty < 0 && ($this->shouldTrackBatchesFor($product) || $this->hasSaleBatchAllocations($sale, $product)))) {
                if ($qty > 0) {
                    $res = $this->batchService()->deductStockFEFO($product, $qty, [
                        'sale_id' => $sale->id,
                        'sale_item_id' => $saleItem?->id,
                        'explicit_batch_id' => $explicitBatchId,
                        'user_id' => $context->userId,
                        'authorized_by_admin_id' => $context->authorizedByAdminId,
                        'cash_shift_id' => $context->cashShiftId,
                        'movement_type' => 'sale',
                        'movement_notes' => ($isAdjustment ? 'Ajuste Recall ' : 'Venta ').StockMovement::CHECKOUT_NOTE_TOKEN."{$sale->id}",
                    ]);
                    if ($updateSaleItemCost && $saleItem) {
                        $saleItem->update(['unit_cost_price' => $res['weighted_unit_cost']]);
                    }
                } elseif ($qty < 0) {
                    $this->batchService()->restoreFromSaleAllocations($sale, $product, abs($qty), [
                        'user_id' => $context->userId,
                        'authorized_by_admin_id' => $context->authorizedByAdminId,
                        'cash_shift_id' => $context->cashShiftId,
                        'movement_type' => 'in',
                        'movement_notes' => "Ajuste Recall Venta #{$sale->id}",
                    ]);
                }
            } else {
                $effectiveDelta = $this->applyStockDeltaWithFractionSnap($product, -$qty);
                $this->logMovement(
                    $product->id,
                    $effectiveDelta,
                    'sale',
                    ($isAdjustment ? 'Ajuste Recall ' : 'Venta ').StockMovement::CHECKOUT_NOTE_TOKEN."{$sale->id}",
                    $context,
                    $sale
                );
            }
            $deductedIds[] = $product->id;
        }

        if ($trackPopularity && ! $context->isInternalAccount && $qty > 0) {
            $product->sales_count += $this->popularityPoints($qty);
        }
        $product->save(); // Dispara Observers

        return $deductedIds;
    }

    /**
     * Devuelve la métrica de popularidad configurada para el Acceso Rápido POS ('tickets' | 'units').
     */
    public function getPopularityMetric(): string
    {
        try {
            $metric = BusinessSetting::where('key', 'pos_popularity_metric')->value('value');

            return in_array($metric, ['tickets', 'units'], true) ? $metric : 'tickets';
        } catch (\Throwable) {
            return 'tickets';
        }
    }

    /**
     * Puntos de popularidad (sales_count) que aporta una línea de venta:
     * - Modo 'tickets' (default): 1 aparición en el ticket = 1 punto de frecuencia en mostrador.
     * - Modo 'units': max(1, ceil($qty)) unidades.
     */
    protected function popularityPoints(float $qty, ?string $metric = null): int
    {
        if ($qty <= 0) {
            return 0;
        }

        $effectiveMetric = $metric ?? $this->getPopularityMetric();
        if ($effectiveMetric === 'units') {
            return max(1, (int) ceil($qty));
        }

        return 1;
    }

    /**
     * Exact math for Order Recall (modifying pending tickets).
     */
    public function reconcileStockDiff(array $newItems, Sale $sale, SaleContextDTO $context): Collection
    {
        $originalQuantities = [];
        foreach ($sale->items as $oldItem) {
            if (empty($oldItem->product_id) || $oldItem->is_temporary) {
                continue;
            }
            $originalQuantities[$oldItem->product_id] = ($originalQuantities[$oldItem->product_id] ?? 0) + $oldItem->quantity;
        }

        $newQuantities = [];
        $extraComboChildIds = [];
        foreach ($newItems as $newItem) {
            if (! empty($newItem['combo_ingredients']) && is_array($newItem['combo_ingredients'])) {
                foreach ($newItem['combo_ingredients'] as $ing) {
                    if (! empty($ing['id'])) {
                        $extraComboChildIds[] = (int) $ing['id'];
                    }
                }
            }
            $isTemp = filter_var($newItem['is_temporary'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (empty($newItem['product_id']) || $isTemp) {
                continue;
            }
            $newQuantities[$newItem['product_id']] = ($newQuantities[$newItem['product_id']] ?? 0) + $newItem['quantity'];
        }

        $allProductIds = array_unique(array_merge(array_keys($originalQuantities), array_keys($newQuantities), $extraComboChildIds));

        $products = $this->lockProducts($allProductIds);

        foreach ($allProductIds as $productId) {
            $oldQty = (float) ($originalQuantities[$productId] ?? 0);
            $newQty = (float) ($newQuantities[$productId] ?? 0);
            $diff = $newQty - $oldQty; // Positivo = agregó más al carrito, Negativo = quitó

            if ($diff != 0 && isset($products[$productId])) {
                $product = $products[$productId];
                $this->deductProductStock($product, $diff, $sale, $context, true, $products, false);

                // Popularidad: delta entre los puntos de la cantidad nueva y los de la vieja
                // (0.3 kg → 0.5 kg no suma; 0 → 0.5 kg suma 1; 5 → 3 u. resta 2).
                if (! $context->isInternalAccount) {
                    $pointsDelta = $this->popularityPoints($newQty) - $this->popularityPoints($oldQty);
                    if ($pointsDelta !== 0) {
                        $product->sales_count = max(0, $product->sales_count + $pointsDelta);
                        $product->save();
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Devuelve el stock al anular una venta (Void). Sensible a Remitos.
     */
    public function restoreStockForVoid(Sale $sale, SaleContextDTO $context, ?DeliveryNote $deliveryNote): array
    {
        $productIds = $sale->items->pluck('product_id')->filter()->unique()->values()->toArray();
        $quickComboMovements = StockMovement::where('sale_id', $sale->id)
            ->where('type', 'sale')
            ->where('notes', 'like', '%Hijo de Combo Rápido:%')
            ->get();
        $quickChildIds = $quickComboMovements->pluck('product_id')->filter()->unique()->values()->toArray();
        $allIdsToLock = array_values(array_unique(array_merge($productIds, $quickChildIds)));

        $products = $this->lockProducts($allIdsToLock);

        // Con remito diferido el mostrador nunca sumó popularidad (ni stock), por lo que no hay nada que revertir.
        $deferredDelivery = $deliveryNote && ! $sale->hasDeductedStock();
        $metric = $this->getPopularityMetric();
        $restoredPopularityProductIds = [];
        $physicallyRestoredIds = [];

        foreach ($sale->items as $item) {
            if (empty($item->product_id) || $item->is_temporary || ! isset($products[$item->product_id])) {
                continue;
            }

            $product = $products[$item->product_id];
            $qtyToRestore = (float) $item->quantity;

            if ($deferredDelivery) {
                // Si el remito era diferido (stock no descontado en mostrador), solo se restituye lo efectivamente entregado
                $dnItem = $deliveryNote->items->firstWhere('product_id', $item->product_id);
                $qtyToRestore = $dnItem ? (float) $dnItem->quantity_delivered : 0.0;
            }

            if ($qtyToRestore > 0) {
                $restored = $this->addStockBack($product, $qtyToRestore, $sale, $context, $products);
                $physicallyRestoredIds = array_merge($physicallyRestoredIds, $restored);
            }

            // Restauración segura de popularidad (GREATEST logic equivalent in memory since we locked)
            if (! $deferredDelivery && ! $context->isInternalAccount && (float) $item->quantity > 0) {
                if ($metric === 'tickets' && isset($restoredPopularityProductIds[$product->id])) {
                    continue;
                }
                $restoredPopularityProductIds[$product->id] = true;

                $product->sales_count = max(0, $product->sales_count - $this->popularityPoints((float) $item->quantity, $metric));
                $product->save();
            }
        }

        if (! $deferredDelivery && $quickComboMovements->isNotEmpty()) {
            foreach ($quickComboMovements as $mv) {
                $childProduct = $products[$mv->product_id] ?? null;
                $qtyToRestore = abs((float) $mv->quantity);
                if (! $childProduct || $qtyToRestore <= 0 || $childProduct->is_service) {
                    continue;
                }
                if ($this->shouldTrackBatchesFor($childProduct) || $this->hasSaleBatchAllocations($sale, $childProduct)) {
                    $this->batchService()->restoreFromSaleAllocations($sale, $childProduct, $qtyToRestore, [
                        'user_id' => $context->userId,
                        'authorized_by_admin_id' => $context->authorizedByAdminId,
                        'cash_shift_id' => $context->cashShiftId,
                        'movement_type' => 'in',
                        'movement_notes' => "Reversión (Combo Rápido) Venta #{$sale->id}",
                    ]);
                } else {
                    $effectiveDelta = $this->applyStockDeltaWithFractionSnap($childProduct, +$qtyToRestore);
                    $childProduct->save();
                    $this->logMovement($childProduct->id, abs($effectiveDelta), 'in', "Reversión (Combo Rápido) Venta #{$sale->id}", $context, $sale);
                }
                $physicallyRestoredIds[] = $childProduct->id;
            }
        }

        // Recalcular combos padres cuyos componentes fueron restituidos
        $affectedCombos = $this->recalculateAndGetAffectedParentCombos($physicallyRestoredIds);

        return array_values(array_unique(array_merge($physicallyRestoredIds, $affectedCombos)));
    }

    /**
     * Recalcula masivamente products.sales_count a partir de las ventas reales completadas
     * en la ventana temporal móvil configurada (por defecto 30 días, métrica 'tickets').
     *
     * @return int Cantidad de productos con popularidad > 0 tras el recálculo.
     */
    public function recalculatePopularityCounts(?int $days = null, ?string $metric = null): int
    {
        $rawDays = $days ?? BusinessSetting::where('key', 'pos_popularity_days')->value('value');
        $effectiveDays = $rawDays !== null ? (int) $rawDays : 30;
        $effectiveMetric = $metric ?? $this->getPopularityMetric();
        $resetAt = BusinessSetting::where('key', 'pos_popularity_reset_at')->value('value');

        $query = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sale_items.quantity', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_notes')
                    ->whereColumn('delivery_notes.sale_id', 'sales.id')
                    ->where('delivery_notes.status', 'pending');
            });

        if ($effectiveDays > 0) {
            $query->where('sales.created_at', '>=', now()->subDays($effectiveDays)->startOfDay());
        }

        if (! empty($resetAt)) {
            try {
                $resetCarbon = Carbon::parse($resetAt);
                $query->where('sales.created_at', '>=', $resetCarbon);
            } catch (\Throwable) {
                // Fecha inválida, ignorar filtro adicional
            }
        }

        $scores = [];
        if ($effectiveMetric === 'units') {
            $rows = $query->selectRaw('sale_items.sale_id, sale_items.product_id, SUM(sale_items.quantity) as total_qty')
                ->groupBy('sale_items.sale_id', 'sale_items.product_id')
                ->get();
            foreach ($rows as $row) {
                $pid = (int) $row->product_id;
                $totalQty = (float) $row->total_qty;
                if ($pid > 0 && $totalQty > 0) {
                    $scores[$pid] = ($scores[$pid] ?? 0) + max(1, (int) ceil($totalQty));
                }
            }
        } else {
            $rows = $query->selectRaw('sale_items.product_id, COUNT(DISTINCT sale_items.sale_id) as score')
                ->groupBy('sale_items.product_id')
                ->get();
            foreach ($rows as $row) {
                $pid = (int) $row->product_id;
                $score = (int) $row->score;
                if ($pid > 0 && $score > 0) {
                    $scores[$pid] = $score;
                }
            }
        }

        $runUpdate = function () use ($scores) {
            DB::table('products')->update(['sales_count' => 0]);

            if (! empty($scores)) {
                foreach (array_chunk($scores, 300, true) as $chunk) {
                    $cases = [];
                    $bindings = [];
                    $ids = [];
                    foreach ($chunk as $productId => $score) {
                        $cases[] = 'WHEN ? THEN ?';
                        $bindings[] = (int) $productId;
                        $bindings[] = (int) $score;
                        $ids[] = (int) $productId;
                    }
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $bindings = array_merge($bindings, $ids);
                    $caseSql = implode(' ', $cases);

                    DB::update(
                        "UPDATE products SET sales_count = CASE id {$caseSql} ELSE sales_count END WHERE id IN ({$placeholders})",
                        $bindings
                    );
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            $runUpdate();
        } else {
            DB::transaction($runUpdate);
        }

        return count($scores);
    }

    /**
     * Pone en cero todos los contadores de popularidad del Acceso Rápido POS y registra
     * la marca temporal de corte para que futuros cierres de caja solo cuenten ventas nuevas.
     */
    public function resetPopularityCounts(): void
    {
        $runReset = function () {
            BusinessSetting::updateOrCreate(
                ['key' => 'pos_popularity_reset_at'],
                ['value' => now()->toIso8601String()]
            );
            DB::table('products')->update(['sales_count' => 0]);
        };

        if (DB::transactionLevel() > 0) {
            $runReset();
        } else {
            DB::transaction($runReset);
        }
    }

    protected function addStockBack(Product $product, float $qtyToRestore, Sale $sale, SaleContextDTO $context, Collection $products): array
    {
        $restoredIds = [];
        if ($product->is_service) {
            return $restoredIds;
        }
        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyRestored = $qtyToRestore * (float) $child->pivot->quantity;
                $canonicalChild = $products[$child->id] ?? $child;
                if ($this->shouldTrackBatchesFor($canonicalChild) || $this->hasSaleBatchAllocations($sale, $canonicalChild)) {
                    $this->batchService()->restoreFromSaleAllocations($sale, $canonicalChild, $qtyRestored, [
                        'user_id' => $context->userId,
                        'authorized_by_admin_id' => $context->authorizedByAdminId,
                        'cash_shift_id' => $context->cashShiftId,
                        'movement_type' => 'in',
                        'movement_notes' => "Reversión (Combo Hijo) Venta #{$sale->id}",
                    ]);
                } else {
                    $effectiveDelta = $this->applyStockDeltaWithFractionSnap($canonicalChild, +$qtyRestored);
                    $canonicalChild->save();

                    $this->logMovement($canonicalChild->id, abs($effectiveDelta), 'in', "Reversión (Combo Hijo) Venta #{$sale->id}", $context, $sale);
                }
                $restoredIds[] = $canonicalChild->id;
            }
        } else {
            if ($this->shouldTrackBatchesFor($product) || $this->hasSaleBatchAllocations($sale, $product)) {
                $this->batchService()->restoreFromSaleAllocations($sale, $product, $qtyToRestore, [
                    'user_id' => $context->userId,
                    'authorized_by_admin_id' => $context->authorizedByAdminId,
                    'cash_shift_id' => $context->cashShiftId,
                    'movement_type' => 'in',
                    'movement_notes' => "Reversión por anulación de Venta #{$sale->id}",
                ]);
            } else {
                $effectiveDelta = $this->applyStockDeltaWithFractionSnap($product, +$qtyToRestore);
                $product->save();

                $this->logMovement($product->id, abs($effectiveDelta), 'in', "Reversión por anulación de Venta #{$sale->id}", $context, $sale);
            }
            $restoredIds[] = $product->id;
        }

        return $restoredIds;
    }

    /**
     * Descuenta stock al despachar un remito de entrega diferida (FEFO para lotes).
     */
    public function deductForDeferredDelivery(DeliveryNote $note, Product $product, float $qtyDelivered, Collection $products, ?int $userId = null): void
    {
        if ($qtyDelivered <= 0 || $product->is_service) {
            return;
        }

        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyDeducted = $qtyDelivered * (float) $child->pivot->quantity;
                $canonicalChild = $products[$child->id] ?? $child;
                if ($this->shouldTrackBatchesFor($canonicalChild)) {
                    $this->batchService()->deductStockFEFO($canonicalChild, $qtyDeducted, [
                        'sale_id' => $note->sale_id,
                        'user_id' => $userId,
                        'movement_type' => 'sale',
                        'movement_notes' => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}",
                    ]);
                } else {
                    $effectiveDelta = $this->applyStockDeltaWithFractionSnap($canonicalChild, -$qtyDeducted);
                    $canonicalChild->save();
                    StockMovement::create([
                        'product_id' => $canonicalChild->id,
                        'user_id' => $userId,
                        'sale_id' => $note->sale_id,
                        'type' => 'sale',
                        'quantity' => $effectiveDelta,
                        'notes' => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}",
                    ]);
                }
            }

            return;
        }

        if ($this->shouldTrackBatchesFor($product)) {
            $this->batchService()->deductStockFEFO($product, $qtyDelivered, [
                'sale_id' => $note->sale_id,
                'user_id' => $userId,
                'movement_type' => 'sale',
                'movement_notes' => "Despacho Logístico Remito #{$note->id}",
            ]);

            return;
        }

        $effectiveDelta = $this->applyStockDeltaWithFractionSnap($product, -$qtyDelivered);
        $product->save();

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $userId,
            'sale_id' => $note->sale_id,
            'type' => 'sale',
            'quantity' => $effectiveDelta,
            'notes' => "Despacho Logístico Remito #{$note->id}",
        ]);
    }

    /**
     * Calculate historical cost for combos or normal products (using real batch allocations when present).
     */
    public function calculateCostPrice(Product $product, ?Sale $sale = null): float
    {
        if ($product->is_combo) {
            $currentCostPrice = 0.0;
            foreach ($product->children as $child) {
                $childCost = ($sale !== null && $child->track_batches)
                    ? $this->resolveAllocatedWeightedCost($sale, $child)
                    : (float) $child->cost_price;
                $currentCostPrice += ($childCost * $child->pivot->quantity);
            }

            return round($currentCostPrice, 2);
        }

        if ($sale !== null && $product->track_batches) {
            return $this->resolveAllocatedWeightedCost($sale, $product);
        }

        return (float) $product->cost_price;
    }

    protected function resolveAllocatedWeightedCost(Sale $sale, Product $product): float
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return (float) $product->cost_price;
        }

        $allocations = \App\Models\SaleItemBatchAllocation::where('sale_id', $sale->id)
            ->where('product_id', $product->id)
            ->whereColumn('quantity', '>', 'restored_quantity')
            ->get();

        if ($allocations->isEmpty()) {
            return (float) $product->cost_price;
        }

        $totalQty = 0.0;
        $totalCost = 0.0;
        foreach ($allocations as $alloc) {
            $netQty = max(0.0, (float) $alloc->quantity - (float) $alloc->restored_quantity);
            $unitCost = $alloc->unit_cost !== null ? (float) $alloc->unit_cost : (float) $product->cost_price;
            $totalQty += $netQty;
            $totalCost += $netQty * $unitCost;
        }

        return $totalQty > 0.0005 ? round($totalCost / $totalQty, 2) : (float) $product->cost_price;
    }

    protected function logMovement(int $productId, float $quantity, string $type, string $notes, SaleContextDTO $context, ?Sale $sale = null): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'user_id' => $context->userId ?? 1,
            'authorized_by_admin_id' => $context->authorizedByAdminId,
            'cash_shift_id' => $context->cashShiftId,
            'sale_id' => $sale?->id,
            'type' => $type,
            'quantity' => $quantity,
            'notes' => $notes,
        ]);
    }
    /**
     * Obtiene los IDs de todos los combos padres que contienen a los productos dados como ingredientes,
     * para su propagación y sincronización de stock virtual hacia plataformas e-commerce.
     *
     * @param array<int> $ingredientProductIds
     * @return array<int> IDs de los combos padres afectados
     */
    public function recalculateAndGetAffectedParentCombos(array $ingredientProductIds): array
    {
        $uniqueIngredientIds = array_values(array_unique(array_filter($ingredientProductIds)));
        if (empty($uniqueIngredientIds)) {
            return [];
        }

        return DB::table('product_combos')
            ->whereIn('child_product_id', $uniqueIngredientIds)
            ->pluck('parent_product_id')
            ->unique()
            ->values()
            ->toArray();
    }

}
