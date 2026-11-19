<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductRecipeItem;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderConsumption;
use App\Models\SaleItemBatchAllocation;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orquestador de elaboración propia (Pastelería y Cocina), recetas multi-nivel y lotes producidos.
 */
class ProductionOrderService
{
    public function __construct(
        protected BatchStockService $batchService,
        protected StockService $stockService
    ) {}

    /**
     * Sincroniza los ingredientes de elaboración propia validando ausencia de ciclos (A -> ... -> A),
     * permitiendo sub-recetas multi-nivel (ej. Crema Pastelera -> Tarta de Frutilla).
     */
    public static function syncProductRecipe(Product $product, array $ingredients): void
    {
        $clean = [];
        foreach ($ingredients as $ing) {
            $ingId = (int) ($ing['id'] ?? $ing['ingredient_product_id'] ?? 0);
            $qty = round((float) ($ing['quantity'] ?? 0), 3);
            $waste = round(max(0.0, min(95.0, (float) ($ing['waste_pct'] ?? 0))), 2);

            if ($ingId <= 0 || $qty <= 0) {
                continue;
            }
            if ($ingId === $product->id) {
                throw ValidationException::withMessages([
                    'recipe_ingredients' => ['Un producto no puede ser ingrediente de su propia receta de elaboración.'],
                ]);
            }

            if (self::createsCircularDependency($product->id, $ingId)) {
                throw ValidationException::withMessages([
                    'recipe_ingredients' => ['Dependencia circular detectada: uno de los ingredientes seleccionados ya contiene a este producto en su cadena de elaboración.'],
                ]);
            }

            $clean[$ingId] = ['quantity' => $qty, 'waste_pct' => $waste];
        }

        ProductRecipeItem::where('parent_product_id', $product->id)->delete();

        foreach ($clean as $ingId => $attrs) {
            ProductRecipeItem::create([
                'parent_product_id' => $product->id,
                'ingredient_product_id' => $ingId,
                'quantity' => $attrs['quantity'],
                'waste_pct' => $attrs['waste_pct'],
            ]);
        }
    }

    /**
     * Verifica mediante BFS si asignar `$candidateIngredientId` dentro de `$targetParentId` forma un ciclo.
     */
    protected static function createsCircularDependency(int $targetParentId, int $candidateIngredientId): bool
    {
        $visited = [$targetParentId => true];
        $queue = [$candidateIngredientId];

        while (! empty($queue)) {
            $current = array_shift($queue);
            if ($current === $targetParentId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $downstream = ProductRecipeItem::where('parent_product_id', $current)
                ->pluck('ingredient_product_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($downstream as $childId) {
                if ($childId === $targetParentId) {
                    return true;
                }
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return false;
    }

    /**
     * Ejecuta una orden de producción descontando insumos/semi-elaborados por FEFO y generando el lote terminado.
     */
    public function createOrder(Product $finishedProduct, array $data): ProductionOrder
    {
        $qtyProduced = round((float) ($data['quantity_produced'] ?? 0), 3);
        if ($qtyProduced <= 0) {
            throw new \InvalidArgumentException('La cantidad producida debe ser mayor a cero.');
        }

        if ($finishedProduct->is_combo || $finishedProduct->is_service) {
            throw new \InvalidArgumentException('No se puede generar orden de producción para combos virtuales ni servicios.');
        }

        return DB::transaction(function () use ($finishedProduct, $data, $qtyProduced) {
            // Determinar líneas de consumo (explícitas del request o calculadas desde la receta guardada)
            $plannedIngredients = $this->resolvePlannedIngredients($finishedProduct, $qtyProduced, $data['ingredients'] ?? null);

            // Adquirir TODOS los locks de productos (producto terminado + ingredientes) en orden ID ASC anti-deadlock
            $lockIds = collect($plannedIngredients)->pluck('ingredient_product_id')
                ->push($finishedProduct->id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $lockedProducts = Product::whereIn('id', $lockIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Product $lockedFinished */
            $lockedFinished = $lockedProducts->get($finishedProduct->id);
            if (! $lockedFinished) {
                throw new \InvalidArgumentException('El producto terminado no existe.');
            }

            if (! $lockedFinished->track_batches) {
                $lockedFinished->track_batches = true;
                $lockedFinished->save();
            }
            $this->batchService->ensureInitialBatchOnEnable($lockedFinished, $data['user_id'] ?? null);

            $orderNumber = $this->generateOrderNumber();
            $manufacturedAt = ! empty($data['manufactured_at'])
                ? Carbon::parse((string) $data['manufactured_at'])->format('Y-m-d')
                : now()->format('Y-m-d');

            $explicitDays = isset($data['vencimiento_dias']) && (int) $data['vencimiento_dias'] > 0
                ? (int) $data['vencimiento_dias']
                : null;
            if ($explicitDays !== null && (int) ($lockedFinished->vencimiento_dias ?? 0) !== $explicitDays) {
                $lockedFinished->vencimiento_dias = $explicitDays;
                $lockedFinished->save();
            }

            $expirationDate = $data['expiration_date'] ?? null;
            $effectiveDays = $explicitDays ?? ((int) ($lockedFinished->vencimiento_dias ?? 0));
            if (empty($expirationDate) && $effectiveDays > 0) {
                $expirationDate = Carbon::parse($manufacturedAt)
                    ->addDays($effectiveDays)
                    ->format('Y-m-d');
            } elseif (! empty($expirationDate)) {
                $expirationDate = Carbon::parse((string) $expirationDate)->format('Y-m-d');
                if (empty($lockedFinished->vencimiento_dias)) {
                    $inferredDays = (int) Carbon::parse($manufacturedAt)->startOfDay()
                        ->diffInDays(Carbon::parse($expirationDate)->startOfDay(), false);
                    if ($inferredDays > 0 && $inferredDays <= 3650) {
                        $lockedFinished->vencimiento_dias = $inferredDays;
                        $lockedFinished->save();
                    }
                }
            }

            $order = ProductionOrder::create([
                'order_number' => $orderNumber,
                'product_id' => $lockedFinished->id,
                'quantity_produced' => $qtyProduced,
                'total_cost' => 0,
                'unit_cost' => 0,
                'manufactured_at' => $manufacturedAt,
                'expiration_date' => $expirationDate ?: null,
                'status' => ProductionOrder::STATUS_COMPLETED,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'authorized_by_admin_id' => $data['authorized_by_admin_id'] ?? null,
            ]);

            $totalProductionCost = 0.0;

            foreach ($plannedIngredients as $ing) {
                $ingId = (int) $ing['ingredient_product_id'];
                $ingQty = round((float) $ing['quantity'], 3);
                if ($ingQty <= 0) {
                    continue;
                }

                /** @var Product|null $lockedIngredient */
                $lockedIngredient = $lockedProducts->get($ingId);
                if (! $lockedIngredient || $lockedIngredient->is_service) {
                    continue;
                }

                if ($lockedIngredient->track_batches) {
                    $deduction = $this->batchService->deductStockFEFO($lockedIngredient, $ingQty, [
                        'explicit_batch_id' => $ing['batch_id'] ?? null,
                        'user_id' => $data['user_id'] ?? null,
                        'authorized_by_admin_id' => $data['authorized_by_admin_id'] ?? null,
                        'allow_deficit' => (bool) ($data['allow_deficit'] ?? true),
                        'record_sale_allocation' => false,
                        'movement_type' => 'production_out',
                        'movement_notes' => "Producción {$orderNumber} ({$lockedFinished->name})",
                    ]);

                    foreach ($deduction['batch_slices'] as $slice) {
                        $sliceQty = round((float) $slice['quantity'], 3);
                        $sliceCost = round((float) $slice['unit_cost'], 2);
                        $subtotal = round($sliceQty * $sliceCost, 2);
                        $totalProductionCost += $subtotal;

                        ProductionOrderConsumption::create([
                            'production_order_id' => $order->id,
                            'ingredient_product_id' => $lockedIngredient->id,
                            'batch_id' => $slice['batch_id'],
                            'quantity_consumed' => $sliceQty,
                            'unit_cost' => $sliceCost,
                            'subtotal_cost' => $subtotal,
                        ]);
                    }
                } else {
                    $snap = BatchStockService::computeQuantityDeltaWithFractionSnap((float) $lockedIngredient->stock, -$ingQty);
                    $lockedIngredient->stock = $snap['new_stock'];
                    $lockedIngredient->save();

                    $consumedQty = abs($snap['effective_delta']);
                    $unitCost = (float) $lockedIngredient->cost_price;
                    $subtotal = round($consumedQty * $unitCost, 2);
                    $totalProductionCost += $subtotal;

                    StockMovement::create([
                        'product_id' => $lockedIngredient->id,
                        'user_id' => $data['user_id'] ?? null,
                        'authorized_by_admin_id' => $data['authorized_by_admin_id'] ?? null,
                        'type' => 'production_out',
                        'quantity' => -$consumedQty,
                        'unit_cost' => $unitCost,
                        'notes' => "Producción {$orderNumber} ({$lockedFinished->name})",
                    ]);

                    ProductionOrderConsumption::create([
                        'production_order_id' => $order->id,
                        'ingredient_product_id' => $lockedIngredient->id,
                        'batch_id' => null,
                        'quantity_consumed' => $consumedQty,
                        'unit_cost' => $unitCost,
                        'subtotal_cost' => $subtotal,
                    ]);
                }
            }

            $totalProductionCost = round($totalProductionCost, 2);
            $resolvedUnitCost = $totalProductionCost > 0
                ? round($totalProductionCost / $qtyProduced, 2)
                : (float) ($data['unit_cost'] ?? $lockedFinished->cost_price);

            if ($resolvedUnitCost > 0) {
                $lockedFinished->cost_price = $resolvedUnitCost;
                $lockedFinished->save();
            }

            $producedBatch = $this->batchService->receiveStockBatch($lockedFinished, $qtyProduced, [
                'source_type' => ProductBatch::SOURCE_PRODUCTION,
                'production_order_id' => $order->id,
                'batch_code' => $data['batch_code'] ?? $orderNumber,
                'manufactured_at' => $manufacturedAt,
                'expiration_date' => $expirationDate,
                'unit_cost' => $resolvedUnitCost,
                'user_id' => $data['user_id'] ?? null,
                'authorized_by_admin_id' => $data['authorized_by_admin_id'] ?? null,
                'notes' => $data['notes'] ?? "Orden de Producción {$orderNumber}",
                'movement_type' => 'production_in',
                'movement_notes' => "Elaboración propia {$orderNumber}",
            ]);

            $order->update([
                'produced_batch_id' => $producedBatch->id,
                'total_cost' => $totalProductionCost > 0 ? $totalProductionCost : round($qtyProduced * $resolvedUnitCost, 2),
                'unit_cost' => $resolvedUnitCost,
            ]);

            // Propagar costo actualizado a combos padres (ej. Porción de Tarta)
            if (empty($data['skip_parent_combo_propagation'])) {
                $parentComboIds = $this->stockService->recalculateAndGetAffectedParentCombos([$lockedFinished->id]);
                if (! empty($parentComboIds)) {
                    $combos = Product::with('children')->whereIn('id', $parentComboIds)->get();
                    foreach ($combos as $combo) {
                        $combo->update([
                            'cost_price' => $combo->calculateComboCostPrice(),
                            'stock' => $combo->calculateComboAvailableStock(),
                        ]);
                    }
                }
            }

            return $order->fresh(['product', 'producedBatch', 'consumptions.ingredient', 'consumptions.batch', 'user']);
        });
    }

    /**
     * Ejecuta la "Planilla Rápida Matutina": registra múltiples órdenes de producción en una única transacción
     * ordenándolas topológicamente (semi-elaborados antes que terminados) y bloqueando todos los productos en orden ID ASC.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{orders: array<int, ProductionOrder>, affected_product_ids: array<int, int>}
     */
    public function createBatchOrders(array $items, array $sharedContext = []): array
    {
        $validItems = array_values(array_filter(
            $items,
            fn ($row) => ! empty($row['product_id']) && (float) ($row['quantity_produced'] ?? 0) > 0
        ));

        if (empty($validItems)) {
            throw new \InvalidArgumentException('Debe indicar al menos un producto con cantidad a producir mayor a cero.');
        }

        return DB::transaction(function () use ($validItems, $sharedContext) {
            $finishedIds = array_values(array_unique(array_map(fn ($r) => (int) $r['product_id'], $validItems)));

            // Pre-recolectar todos los IDs implicados (terminados + recetas + ingredientes explícitos) para lock ID ASC
            $allInvolvedIds = $finishedIds;
            $recipeRows = ProductRecipeItem::whereIn('parent_product_id', $finishedIds)->get();
            foreach ($recipeRows as $rr) {
                $allInvolvedIds[] = (int) $rr->ingredient_product_id;
            }
            foreach ($validItems as $row) {
                if (! empty($row['ingredients']) && is_array($row['ingredients'])) {
                    foreach ($row['ingredients'] as $ing) {
                        $ingId = (int) ($ing['ingredient_product_id'] ?? $ing['id'] ?? 0);
                        if ($ingId > 0) {
                            $allInvolvedIds[] = $ingId;
                        }
                    }
                }
            }

            $allInvolvedIds = array_values(array_unique($allInvolvedIds));
            sort($allInvolvedIds);

            $lockedCatalog = Product::whereIn('id', $allInvolvedIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Ordenar ítems para que si un semi-elaborado y un producto final van en la misma tanda,
            // el semi-elaborado se hornee primero y aporte stock/lote al producto terminado.
            $orderedItems = $this->sortBatchItemsTopologically($validItems, $recipeRows);

            $createdOrders = [];
            $affectedIds = [];

            foreach ($orderedItems as $item) {
                $prodId = (int) $item['product_id'];
                /** @var Product|null $product */
                $product = $lockedCatalog->get($prodId) ?? Product::find($prodId);
                if (! $product) {
                    throw new \InvalidArgumentException("El producto con ID {$prodId} no existe o fue eliminado.");
                }

                $payload = [
                    'quantity_produced' => $item['quantity_produced'],
                    'batch_code' => $item['batch_code'] ?? null,
                    'manufactured_at' => $item['manufactured_at'] ?? ($sharedContext['manufactured_at'] ?? null),
                    'expiration_date' => $item['expiration_date'] ?? null,
                    'vencimiento_dias' => $item['vencimiento_dias'] ?? ($sharedContext['vencimiento_dias'] ?? null),
                    'unit_cost' => $item['unit_cost'] ?? null,
                    'notes' => $item['notes'] ?? ($sharedContext['notes'] ?? 'Planilla rápida de producción'),
                    'allow_deficit' => $item['allow_deficit'] ?? ($sharedContext['allow_deficit'] ?? true),
                    'ingredients' => $item['ingredients'] ?? null,
                    'user_id' => $sharedContext['user_id'] ?? null,
                    'authorized_by_admin_id' => $sharedContext['authorized_by_admin_id'] ?? null,
                    'skip_parent_combo_propagation' => true,
                ];

                $order = $this->createOrder($product, $payload);
                $createdOrders[] = $order;
                $affectedIds[$product->id] = true;
                foreach ($order->consumptions as $c) {
                    $affectedIds[$c->ingredient_product_id] = true;
                }
            }

            $affectedList = array_map('intval', array_keys($affectedIds));
            $parentComboIds = $this->stockService->recalculateAndGetAffectedParentCombos($affectedList);
            if (! empty($parentComboIds)) {
                $combos = Product::with('children')->whereIn('id', $parentComboIds)->get();
                foreach ($combos as $combo) {
                    $combo->update([
                        'cost_price' => $combo->calculateComboCostPrice(),
                        'stock' => $combo->calculateComboAvailableStock(),
                    ]);
                    $affectedList[] = (int) $combo->id;
                }
            }

            return [
                'orders' => $createdOrders,
                'affected_product_ids' => array_values(array_unique($affectedList)),
            ];
        });
    }

    /**
     * Ordenamiento topológico estable: coloca primero los semi-elaborados referenciados como insumos
     * por otros ítems de la misma planilla matutina.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  \Illuminate\Support\Collection<int, ProductRecipeItem>  $recipeRows
     * @return array<int, array<string, mixed>>
     */
    protected function sortBatchItemsTopologically(array $items, $recipeRows): array
    {
        if (count($items) <= 1) {
            return $items;
        }

        $depsByParent = [];
        foreach ($recipeRows as $rr) {
            $depsByParent[(int) $rr->parent_product_id][(int) $rr->ingredient_product_id] = true;
        }
        foreach ($items as $row) {
            $pid = (int) $row['product_id'];
            if (! empty($row['ingredients']) && is_array($row['ingredients'])) {
                foreach ($row['ingredients'] as $ing) {
                    $ingId = (int) ($ing['ingredient_product_id'] ?? $ing['id'] ?? 0);
                    if ($ingId > 0) {
                        $depsByParent[$pid][$ingId] = true;
                    }
                }
            }
        }

        $remaining = array_values($items);
        $sorted = [];
        $guard = 0;
        $maxIter = count($remaining) + 2;

        while (! empty($remaining) && $guard++ < $maxIter) {
            $remainingProductIds = array_flip(array_map(fn ($r) => (int) $r['product_id'], $remaining));
            $progressed = false;

            foreach ($remaining as $idx => $candidate) {
                $pid = (int) $candidate['product_id'];
                $deps = $depsByParent[$pid] ?? [];
                $hasUnproducedDep = false;
                foreach ($deps as $ingId => $_) {
                    if ($ingId !== $pid && isset($remainingProductIds[$ingId])) {
                        $hasUnproducedDep = true;
                        break;
                    }
                }

                if (! $hasUnproducedDep) {
                    $sorted[] = $candidate;
                    unset($remaining[$idx]);
                    $remaining = array_values($remaining);
                    $progressed = true;
                    break;
                }
            }

            if (! $progressed) {
                // Fallback conservador en caso de dependencia mutua inesperada
                $sorted = array_merge($sorted, $remaining);
                break;
            }
        }

        return $sorted;
    }

    /**
     * Anula una orden de producción intacta y devuelve los insumos consumidos a sus lotes originales.
     */
    public function voidOrder(ProductionOrder $order, ?int $userId = null, ?int $authorizedAdminId = null, ?string $reason = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $userId, $authorizedAdminId, $reason) {
            $lockedOrder = ProductionOrder::with('consumptions')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === ProductionOrder::STATUS_VOIDED) {
                throw new \InvalidArgumentException("La orden {$lockedOrder->order_number} ya fue anulada.");
            }

            $lockIds = $lockedOrder->consumptions->pluck('ingredient_product_id')
                ->push($lockedOrder->product_id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $lockedProducts = Product::whereIn('id', $lockIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $finishedProduct = $lockedProducts->get($lockedOrder->product_id);

            if ($lockedOrder->produced_batch_id) {
                $producedBatch = ProductBatch::where('id', $lockedOrder->produced_batch_id)->lockForUpdate()->first();
                if ($producedBatch) {
                    $hasSales = SaleItemBatchAllocation::where('batch_id', $producedBatch->id)
                        ->whereColumn('quantity', '>', 'restored_quantity')
                        ->exists();
                    $hasWasteOrUse = StockMovement::where('batch_id', $producedBatch->id)
                        ->whereIn('type', ['waste', 'production_out'])
                        ->exists();
                    $wasUsed = ((float) $producedBatch->remaining_quantity + 0.0005) < (float) $producedBatch->initial_quantity
                        || $hasSales
                        || $hasWasteOrUse;

                    if ($wasUsed) {
                        throw new \InvalidArgumentException(
                            "No se puede anular {$lockedOrder->order_number} porque el lote producido {$producedBatch->batch_code} ya registra ventas, mermas o consumos posteriores."
                        );
                    }

                    $producedBatch->remaining_quantity = 0.0;
                    $producedBatch->status = ProductBatch::STATUS_VOIDED;
                    $producedBatch->save();

                    if ($finishedProduct) {
                        StockMovement::create([
                            'product_id' => $finishedProduct->id,
                            'batch_id' => $producedBatch->id,
                            'user_id' => $userId,
                            'authorized_by_admin_id' => $authorizedAdminId,
                            'type' => 'production_out',
                            'quantity' => -(float) $lockedOrder->quantity_produced,
                            'unit_cost' => $producedBatch->unit_cost,
                            'notes' => "Anulación producción {$lockedOrder->order_number}".($reason ? " ({$reason})" : ''),
                        ]);
                        $this->batchService->syncProductMaterializedFields($finishedProduct);
                    }
                }
            }

            foreach ($lockedOrder->consumptions as $consumption) {
                $ingredient = $lockedProducts->get($consumption->ingredient_product_id);
                if (! $ingredient) {
                    continue;
                }

                $qtyBack = (float) $consumption->quantity_consumed;
                if ($qtyBack <= 0) {
                    continue;
                }

                if ($ingredient->track_batches && $consumption->batch_id) {
                    $origBatch = ProductBatch::find($consumption->batch_id);
                    if ($origBatch) {
                        $this->batchService->restoreQuantityToBatch($ingredient, $origBatch, $qtyBack, [
                            'user_id' => $userId,
                            'authorized_by_admin_id' => $authorizedAdminId,
                            'movement_type' => 'production_in',
                            'movement_notes' => "Reversión insumos por anulación {$lockedOrder->order_number}",
                        ]);

                        continue;
                    }
                }

                $snap = BatchStockService::computeQuantityDeltaWithFractionSnap((float) $ingredient->stock, +$qtyBack);
                $ingredient->stock = $snap['new_stock'];
                $ingredient->save();

                StockMovement::create([
                    'product_id' => $ingredient->id,
                    'user_id' => $userId,
                    'authorized_by_admin_id' => $authorizedAdminId,
                    'type' => 'production_in',
                    'quantity' => abs($snap['effective_delta']),
                    'unit_cost' => $consumption->unit_cost,
                    'notes' => "Reversión insumos por anulación {$lockedOrder->order_number}",
                ]);
            }

            $lockedOrder->update([
                'status' => ProductionOrder::STATUS_VOIDED,
                'notes' => trim(($lockedOrder->notes ? $lockedOrder->notes."\n" : '').'Anulada'.($reason ? ": {$reason}" : '')),
            ]);

            return $lockedOrder->fresh(['product', 'producedBatch', 'consumptions.ingredient', 'consumptions.batch', 'user']);
        });
    }

    /**
     * Resuelve los insumos requeridos escalando la receta por rendimiento o aceptando consumos manuales.
     *
     * @return array<int, array{ingredient_product_id: int, quantity: float, batch_id: int|null}>
     */
    protected function resolvePlannedIngredients(Product $finishedProduct, float $qtyProduced, ?array $explicitIngredients): array
    {
        if (! empty($explicitIngredients)) {
            $planned = [];
            foreach ($explicitIngredients as $ing) {
                $ingId = (int) ($ing['ingredient_product_id'] ?? $ing['id'] ?? 0);
                $qty = round((float) ($ing['quantity'] ?? 0), 3);
                if ($ingId > 0 && $qty > 0) {
                    $planned[] = [
                        'ingredient_product_id' => $ingId,
                        'quantity' => $qty,
                        'batch_id' => ! empty($ing['batch_id']) ? (int) $ing['batch_id'] : null,
                    ];
                }
            }

            return $planned;
        }

        $recipeItems = $finishedProduct->recipeItems()->get();
        if ($recipeItems->isEmpty()) {
            return [];
        }

        $yield = max(0.001, (float) ($finishedProduct->recipe_yield_quantity ?? 1.0));
        $scale = $qtyProduced / $yield;

        return $recipeItems->map(function (ProductRecipeItem $item) use ($scale) {
            $wasteFactor = 1.0 + (max(0.0, (float) $item->waste_pct) / 100.0);

            return [
                'ingredient_product_id' => $item->ingredient_product_id,
                'quantity' => round((float) $item->quantity * $wasteFactor * $scale, 3),
                'batch_id' => null,
            ];
        })->all();
    }

    protected function generateOrderNumber(): string
    {
        $prefix = 'OP-'.now()->format('Ymd').'-';
        $last = ProductionOrder::where('order_number', 'like', "{$prefix}%")->count();
        $seq = $last + 1;
        while (ProductionOrder::where('order_number', sprintf('%s%03d', $prefix, $seq))->exists()) {
            $seq++;
        }

        return sprintf('%s%03d', $prefix, $seq);
    }
}
