<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItemBatchAllocation;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor central de inventario por lotes con soporte FEFO/FIFO,
 * fracciones periódicas, lotes de contingencia y conciliación automática.
 */
class BatchStockService
{
    protected static ?bool $lotTrackingSchemaCache = null;

    protected static ?bool $productionRecipeSchemaCache = null;

    /**
     * Verifica de forma segura y cacheada si las tablas y columnas de control por lotes existen en la BD actual.
     * Previene errores SQL 42S02/42S22 si el código se despliega antes de correr `php artisan migrate`
     * o durante la restauración de un backup legacy (v1.8.5).
     */
    public static function hasLotTrackingSchema(): bool
    {
        if (self::$lotTrackingSchemaCache !== null) {
            return self::$lotTrackingSchemaCache;
        }

        try {
            self::$lotTrackingSchemaCache = Schema::hasTable('product_batches')
                && Schema::hasTable('sale_item_batch_allocations')
                && Schema::hasColumn('products', 'track_batches');
        } catch (\Throwable) {
            self::$lotTrackingSchemaCache = false;
        }

        return self::$lotTrackingSchemaCache;
    }

    /**
     * Verifica de forma segura y cacheada si las tablas y columnas de recetas y órdenes de producción existen.
     */
    public static function hasProductionRecipeSchema(): bool
    {
        if (self::$productionRecipeSchemaCache !== null) {
            return self::$productionRecipeSchemaCache;
        }

        try {
            self::$productionRecipeSchemaCache = Schema::hasTable('product_recipe_items')
                && Schema::hasTable('production_orders')
                && Schema::hasColumn('products', 'has_production_recipe');
        } catch (\Throwable) {
            self::$productionRecipeSchemaCache = false;
        }

        return self::$productionRecipeSchemaCache;
    }

    /**
     * Limpia la caché en memoria de verificación de esquema (útil tras migraciones en caliente o tests).
     */
    public static function flushSchemaCache(): void
    {
        self::$lotTrackingSchemaCache = null;
        self::$productionRecipeSchemaCache = null;
    }

    /**
     * Aplica un delta con signo sobre una cantidad y ajusta residuos de fracciones periódicas
     * (ej. 1/6 = 0.167 o 1/12 = 0.083) cuando quedan dentro de <= 0.010 de un entero,
     * preservando ventas pequeñas por peso (< 0.030).
     *
     * @return array{new_stock: float, effective_delta: float}
     */
    public static function computeQuantityDeltaWithFractionSnap(float $qtyBefore, float $signedDelta): array
    {
        $stockBefore = round($qtyBefore, 3);
        $rawAfter = round($stockBefore + $signedDelta, 3);
        $isFractionalOp = abs($signedDelta - round($signedDelta)) > 0.0001
            || abs($stockBefore - round($stockBefore)) > 0.0001;

        $stockAfter = $rawAfter;
        if ($isFractionalOp && abs($signedDelta) >= 0.030) {
            $nearestInt = round($rawAfter, 0);
            if (abs($rawAfter - $nearestInt) <= 0.010) {
                $candidateDelta = round($nearestInt - $stockBefore, 3);
                if (($signedDelta < 0 && $candidateDelta < 0) || ($signedDelta > 0 && $candidateDelta > 0)) {
                    $stockAfter = (float) $nearestInt;
                }
            }
        }

        if (abs($stockAfter) < 0.0001) {
            $stockAfter = 0.0;
        }

        return [
            'new_stock' => $stockAfter,
            'effective_delta' => round($stockAfter - $stockBefore, 3),
        ];
    }

    /**
     * Si el producto pasa a control por lotes teniendo stock previo sin lotes activos,
     * materializa automáticamente el stock preexistente en un lote migrado.
     */
    public function ensureInitialBatchOnEnable(Product $product, ?int $userId = null): ?ProductBatch
    {
        if (! self::hasLotTrackingSchema() || ! $product->track_batches || $product->is_combo || $product->is_service) {
            return null;
        }

        $hasExistingBatches = ProductBatch::withTrashed()
            ->where('product_id', $product->id)
            ->exists();

        if ($hasExistingBatches) {
            return null;
        }

        $currentStock = round((float) $product->stock, 3);
        if (abs($currentStock) < 0.0005) {
            return null;
        }

        if ($currentStock < 0) {
            return ProductBatch::create([
                'product_id' => $product->id,
                'batch_code' => 'DEFICIT-AUTO',
                'source_type' => ProductBatch::SOURCE_DEFICIT_AUTO,
                'initial_quantity' => 0.0,
                'remaining_quantity' => $currentStock,
                'unit_cost' => $product->cost_price,
                'status' => ProductBatch::STATUS_DEFICIT,
                'notes' => 'Saldo negativo previo convertido al activar control por lotes',
                'created_by_user_id' => $userId,
            ]);
        }

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_code' => 'LOT-INICIAL-'.$product->id,
            'source_type' => ProductBatch::SOURCE_MIGRATION,
            'manufactured_at' => now()->format('Y-m-d'),
            'expiration_date' => $product->expiration_date?->format('Y-m-d'),
            'initial_quantity' => $currentStock,
            'remaining_quantity' => $currentStock,
            'unit_cost' => $product->cost_price,
            'status' => ProductBatch::STATUS_ACTIVE,
            'alert_snoozed_until' => $product->expiry_alert_snoozed_until,
            'alert_last_stage' => $product->expiry_alert_last_stage,
            'notes' => 'Stock inicial migrado al activar control por lotes',
            'created_by_user_id' => $userId,
        ]);

        $this->syncProductMaterializedFields($product);

        return $batch;
    }

    /**
     * Genera un código legible y único de lote cuando no se indicó uno manual.
     */
    public function generateBatchCode(string $prefix, Product $product): string
    {
        $datePart = now()->format('Ymd');
        $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $prefix) ?: 'LOT');
        $base = "{$cleanPrefix}-{$datePart}-{$product->id}";
        $count = ProductBatch::withTrashed()
            ->where('product_id', $product->id)
            ->where('batch_code', 'like', "{$base}%")
            ->count();

        return $count > 0 ? sprintf('%s-%02d', $base, $count + 1) : $base;
    }

    /**
     * Recalcula atómicamente products.stock y products.expiration_date
     * a partir del estado vivo de sus lotes para mantener 100% compatibilidad con v1.8.5/v2.1.0.
     */
    public function syncProductMaterializedFields(Product $product): Product
    {
        if (! $product->track_batches || $product->is_combo || $product->is_service) {
            return $product;
        }

        $totalStock = (float) ProductBatch::where('product_id', $product->id)
            ->whereIn('status', [ProductBatch::STATUS_ACTIVE, ProductBatch::STATUS_DEFICIT])
            ->sum('remaining_quantity');

        $normalizedStock = abs($totalStock) < 0.0005 ? 0.0 : round($totalStock, 3);

        $earliestActiveBatch = ProductBatch::where('product_id', $product->id)
            ->where('status', ProductBatch::STATUS_ACTIVE)
            ->where('remaining_quantity', '>', 0)
            ->whereNotNull('expiration_date')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        $oldExp = $product->expiration_date?->format('Y-m-d');
        $newExp = $earliestActiveBatch?->expiration_date?->format('Y-m-d');

        $product->stock = $normalizedStock;
        $product->expiration_date = $newExp;

        if ($earliestActiveBatch) {
            $product->expiry_alert_snoozed_until = $earliestActiveBatch->alert_snoozed_until;
            $product->expiry_alert_last_stage = $earliestActiveBatch->alert_last_stage;
        } elseif ($oldExp !== null || $newExp === null) {
            $product->expiry_alert_snoozed_until = null;
            $product->expiry_alert_last_stage = null;
        }

        $product->save();

        return $product;
    }

    /**
     * Registra el ingreso de un lote nuevo, concilia déficits abiertos y sincroniza el producto.
     */
    public function receiveStockBatch(Product $product, float $quantity, array $attributes = []): ProductBatch
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('receiveStockBatch debe ejecutarse dentro de una transacción.');
        }

        $qty = round($quantity, 3);
        if ($qty <= 0) {
            throw new \InvalidArgumentException('La cantidad ingresada al lote debe ser mayor a cero.');
        }

        $batchCode = trim((string) ($attributes['batch_code'] ?? ''));
        if ($batchCode === '') {
            $prefix = match ($attributes['source_type'] ?? ProductBatch::SOURCE_ADJUSTMENT) {
                ProductBatch::SOURCE_PURCHASE => 'COMP',
                ProductBatch::SOURCE_PRODUCTION => 'PROD',
                ProductBatch::SOURCE_OPENING => 'INI',
                default => 'LOT',
            };
            $batchCode = $this->generateBatchCode($prefix, $product);
        }

        $expirationDate = $attributes['expiration_date'] ?? null;
        if (empty($expirationDate) && ! empty($product->vencimiento_dias) && (int) $product->vencimiento_dias > 0) {
            $baseDate = ! empty($attributes['manufactured_at'])
                ? Carbon::parse((string) $attributes['manufactured_at'])
                : now();
            $expirationDate = $baseDate->copy()->addDays((int) $product->vencimiento_dias)->format('Y-m-d');
        } elseif ($expirationDate instanceof Carbon) {
            $expirationDate = $expirationDate->format('Y-m-d');
        }

        $unitCost = array_key_exists('unit_cost', $attributes) && $attributes['unit_cost'] !== null
            ? round((float) $attributes['unit_cost'], 2)
            : (float) $product->cost_price;

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_code' => $batchCode,
            'source_type' => $attributes['source_type'] ?? ProductBatch::SOURCE_ADJUSTMENT,
            'supplier_invoice_id' => $attributes['supplier_invoice_id'] ?? null,
            'supplier_id' => $attributes['supplier_id'] ?? null,
            'production_order_id' => $attributes['production_order_id'] ?? null,
            'manufactured_at' => $attributes['manufactured_at'] ?? now()->format('Y-m-d'),
            'expiration_date' => $expirationDate ?: null,
            'initial_quantity' => $qty,
            'remaining_quantity' => $qty,
            'reserved_quantity' => 0,
            'unit_cost' => $unitCost,
            'status' => ProductBatch::STATUS_ACTIVE,
            'notes' => $attributes['notes'] ?? null,
            'created_by_user_id' => $attributes['user_id'] ?? null,
        ]);

        if ($attributes['log_movement'] ?? true) {
            StockMovement::create([
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'user_id' => $attributes['user_id'] ?? null,
                'authorized_by_admin_id' => $attributes['authorized_by_admin_id'] ?? null,
                'supplier_invoice_id' => $attributes['supplier_invoice_id'] ?? null,
                'type' => $attributes['movement_type'] ?? 'in',
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'notes' => $attributes['movement_notes'] ?? "Ingreso a lote {$batch->batch_code}",
            ]);
        }

        $this->absorbOpenDeficits($product, $batch);
        $this->syncProductMaterializedFields($product);

        return $batch->fresh();
    }

    /**
     * Reactiva un lote previamente anulado al restaurar desde Papelera una factura intacta.
     */
    public function reactivateVoidedBatch(Product $product, ProductBatch $batch, float $quantity, array $attributes = []): ProductBatch
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('reactivateVoidedBatch debe ejecutarse dentro de una transacción.');
        }

        $qty = round($quantity, 3);
        $batch->status = ProductBatch::STATUS_ACTIVE;
        $batch->initial_quantity = $qty;
        $batch->remaining_quantity = $qty;
        $batch->save();

        StockMovement::create([
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'user_id' => $attributes['user_id'] ?? null,
            'supplier_invoice_id' => $attributes['supplier_invoice_id'] ?? $batch->supplier_invoice_id,
            'type' => $attributes['movement_type'] ?? 'purchase',
            'quantity' => $qty,
            'unit_cost' => $batch->unit_cost ?? $product->cost_price,
            'notes' => $attributes['movement_notes'] ?? "Reactivación de lote {$batch->batch_code}",
        ]);

        $this->absorbOpenDeficits($product, $batch);
        $this->syncProductMaterializedFields($product);

        return $batch->fresh();
    }

    /**
     * Descuenta unidades siguiendo la política configurada (FEFO o FIFO), soportando
     * selección explícita por lote, fracciones periódicas y contingencia deficit_auto.
     *
     * @return array{deducted_total: float, weighted_unit_cost: float, allocations: array<int, array<string, mixed>>}
     */
    public function deductStockFEFO(Product $product, float $qty, array $options = []): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('deductStockFEFO debe ejecutarse dentro de una transacción.');
        }

        $requestedQty = round($qty, 3);
        if ($requestedQty <= 0) {
            return ['deducted_total' => 0.0, 'weighted_unit_cost' => (float) $product->cost_price, 'allocations' => []];
        }

        $explicitBatchId = isset($options['explicit_batch_id']) ? (int) $options['explicit_batch_id'] : null;
        $authorizedAdminId = $options['authorized_by_admin_id'] ?? null;
        $allowExpired = (bool) ($options['ignore_expired_guard'] ?? false)
            || (bool) $product->allow_sell_expired
            || ! empty($authorizedAdminId);
        $allowDeficit = (bool) ($options['allow_deficit'] ?? true);

        $this->ensureInitialBatchOnEnable($product, $options['user_id'] ?? null);
        $orderedBatches = $this->lockOrderedActiveBatches($product);

        if ($explicitBatchId !== null) {
            $explicit = $orderedBatches->firstWhere('id', $explicitBatchId);
            if (! $explicit) {
                $anyBatch = ProductBatch::find($explicitBatchId);
                if ($anyBatch && $anyBatch->product_id === $product->id && $anyBatch->status === ProductBatch::STATUS_WRITTEN_OFF) {
                    throw new \InvalidArgumentException("El lote {$anyBatch->batch_code} de «{$product->name}» fue dado de baja como merma.");
                }
                throw new \InvalidArgumentException("El lote seleccionado (#{$explicitBatchId}) de «{$product->name}» ya no tiene stock activo.");
            }
            if ($explicit->isExpired() && ! $allowExpired) {
                $expLabel = $explicit->expiration_date?->format('d/m/Y') ?? 'fecha cumplida';
                throw new \InvalidArgumentException(
                    "El lote {$explicit->batch_code} de «{$product->name}» venció el {$expLabel}. Retire la unidad o autorice con PIN de supervisor."
                );
            }
            // Priorizar el lote explícito primero manteniendo el resto detrás en orden FEFO/FIFO
            $orderedBatches = collect([$explicit])
                ->concat($orderedBatches->where('id', '!=', $explicitBatchId)->values())
                ->values();
        }

        $availableBatches = $orderedBatches->filter(fn (ProductBatch $b) => $b->available_to_sell > 0.0005)->values();
        $unexpiredBatches = $availableBatches->filter(fn (ProductBatch $b) => ! $b->isExpired())->values();
        $expiredBatches = $availableBatches->filter(fn (ProductBatch $b) => $b->isExpired())->values();

        if (! $allowExpired && $unexpiredBatches->isEmpty() && $expiredBatches->isNotEmpty()) {
            $firstExp = $expiredBatches->first();
            $expLabel = $firstExp->expiration_date?->format('d/m/Y') ?? 'fecha cumplida';
            throw new \InvalidArgumentException(
                "El producto «{$product->name}» únicamente posee stock en lotes vencidos (ej. {$firstExp->batch_code}, vencido el {$expLabel}). Registre la merma o autorice la venta con PIN de supervisor."
            );
        }

        // Si existen lotes vigentes, FEFO siempre prioriza lo vigente antes que lo vencido.
        // Con autorización (o allow_sell_expired) solo permite consumir vencidos como respaldo o si fueron seleccionados explícitamente.
        if ($explicitBatchId !== null && $allowExpired) {
            $candidateBatches = $availableBatches;
        } elseif ($allowExpired) {
            $candidateBatches = $unexpiredBatches->concat($expiredBatches)->values();
        } else {
            $candidateBatches = $unexpiredBatches;
        }

        $remainingToDeduct = $requestedQty;
        $deductedTotal = 0.0;
        $weightedCostNumerator = 0.0;
        $allocationsCreated = [];
        $batchSlices = [];

        foreach ($candidateBatches as $batch) {
            if ($remainingToDeduct <= 0.0005) {
                break;
            }

            $available = $batch->available_to_sell;
            if ($available <= 0.0005) {
                continue;
            }

            $taken = 0.0;
            if ($remainingToDeduct <= ($available + 0.010)) {
                $snap = self::computeQuantityDeltaWithFractionSnap($available, -$remainingToDeduct);
                if ($snap['new_stock'] >= 0.0) {
                    $taken = abs($snap['effective_delta']);
                    $remainingToDeduct = 0.0;
                } else {
                    $taken = $available;
                    $remainingToDeduct = round($remainingToDeduct - $available, 3);
                }
            } else {
                $taken = $available;
                $remainingToDeduct = round($remainingToDeduct - $available, 3);
            }

            if ($taken <= 0.0005) {
                continue;
            }

            $batch->remaining_quantity = max(0.0, round((float) $batch->remaining_quantity - $taken, 3));
            if ((float) $batch->remaining_quantity <= 0.0005 && (float) $batch->reserved_quantity <= 0.0005) {
                $batch->remaining_quantity = 0.0;
                $batch->status = ProductBatch::STATUS_EXHAUSTED;
            }
            $batch->save();

            $unitCost = $batch->unit_cost !== null ? (float) $batch->unit_cost : (float) $product->cost_price;
            $deductedTotal = round($deductedTotal + $taken, 3);
            $weightedCostNumerator += $taken * $unitCost;

            $allocationsCreated[] = $this->recordDeductionTraceability($product, $batch, $taken, $unitCost, $options);
            $batchSlices[] = [
                'batch_id' => $batch->id,
                'batch_code' => $batch->batch_code,
                'quantity' => $taken,
                'unit_cost' => $unitCost,
            ];
        }

        if ($remainingToDeduct > 0.0005) {
            if (! $allowDeficit) {
                throw new \InvalidArgumentException(
                    "Stock insuficiente en lotes vigentes para «{$product->name}» (faltante: {$remainingToDeduct})."
                );
            }

            $deficitBatch = $this->lockOrCreateDeficitBatch($product, $options['user_id'] ?? null);
            $deficitBefore = (float) $deficitBatch->remaining_quantity;
            $deficitSnap = self::computeQuantityDeltaWithFractionSnap($deficitBefore, -$remainingToDeduct);
            $deficitTaken = abs($deficitSnap['effective_delta']);

            $deficitBatch->remaining_quantity = $deficitSnap['new_stock'];
            $deficitBatch->status = ProductBatch::STATUS_DEFICIT;
            $deficitBatch->save();

            $unitCost = (float) ($deficitBatch->unit_cost ?? $product->cost_price);
            $deductedTotal = round($deductedTotal + $deficitTaken, 3);
            $weightedCostNumerator += $deficitTaken * $unitCost;

            $allocationsCreated[] = $this->recordDeductionTraceability($product, $deficitBatch, $deficitTaken, $unitCost, $options);
            $batchSlices[] = [
                'batch_id' => $deficitBatch->id,
                'batch_code' => $deficitBatch->batch_code,
                'quantity' => $deficitTaken,
                'unit_cost' => $unitCost,
            ];
        }

        $this->syncProductMaterializedFields($product);

        $weightedUnitCost = $deductedTotal > 0.0005
            ? round($weightedCostNumerator / $deductedTotal, 2)
            : (float) $product->cost_price;

        return [
            'deducted_total' => $deductedTotal,
            'weighted_unit_cost' => $weightedUnitCost,
            'allocations' => array_values(array_filter($allocationsCreated)),
            'batch_slices' => $batchSlices,
        ];
    }

    /**
     * Devuelve al lote original las unidades descontadas en una venta (anulación completa
     * o reducción parcial en orden en espera / remito).
     *
     * @return array{restored_total: float}
     */
    public function restoreFromSaleAllocations(Sale $sale, Product $product, ?float $qtyLimit = null, array $options = []): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('restoreFromSaleAllocations debe ejecutarse dentro de una transacción.');
        }

        $allocations = SaleItemBatchAllocation::where('sale_id', $sale->id)
            ->where('product_id', $product->id)
            ->whereColumn('quantity', '>', 'restored_quantity')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->get();

        // Orden canónico por batch_id ASC para evitar deadlocks entre reversiones concurrentes
        $batchIds = $allocations->pluck('batch_id')->unique()->sort()->values()->all();
        $lockedBatches = empty($batchIds)
            ? collect()
            : ProductBatch::whereIn('id', $batchIds)->orderBy('id', 'asc')->lockForUpdate()->get()->keyBy('id');

        $remainingToRestore = $qtyLimit !== null ? round($qtyLimit, 3) : null;
        $restoredTotal = 0.0;

        foreach ($allocations as $alloc) {
            if ($remainingToRestore !== null && $remainingToRestore <= 0.0005) {
                break;
            }

            $unrestored = $alloc->unrestored_quantity;
            if ($unrestored <= 0.0005) {
                continue;
            }

            $restoreHere = $remainingToRestore !== null
                ? min($unrestored, $remainingToRestore)
                : $unrestored;

            // Si quedó dentro del margen de redondeo fraccionario respecto a lo faltante de la asignación
            if ($remainingToRestore !== null && abs($unrestored - $remainingToRestore) <= 0.010 && $unrestored > $remainingToRestore) {
                $restoreHere = $unrestored;
            }

            /** @var ProductBatch|null $batch */
            $batch = $lockedBatches->get($alloc->batch_id);
            if ($batch) {
                $snap = self::computeQuantityDeltaWithFractionSnap((float) $batch->remaining_quantity, +$restoreHere);
                $effectiveDelta = abs($snap['effective_delta']);
                $batch->remaining_quantity = $snap['new_stock'];

                if ($batch->status === ProductBatch::STATUS_DEFICIT) {
                    if ((float) $batch->remaining_quantity >= -0.0005) {
                        $batch->remaining_quantity = max(0.0, (float) $batch->remaining_quantity);
                        $batch->status = (float) $batch->remaining_quantity > 0.0005
                            ? ProductBatch::STATUS_ACTIVE
                            : ProductBatch::STATUS_EXHAUSTED;
                    }
                } elseif ($batch->status === ProductBatch::STATUS_EXHAUSTED && (float) $batch->remaining_quantity > 0.0005) {
                    $batch->status = ProductBatch::STATUS_ACTIVE;
                }

                $batch->save();
            } else {
                $effectiveDelta = round($restoreHere, 3);
            }

            $alloc->restored_quantity = round(min((float) $alloc->quantity, (float) $alloc->restored_quantity + $restoreHere), 3);
            $alloc->save();

            StockMovement::create([
                'product_id' => $product->id,
                'batch_id' => $batch?->id,
                'user_id' => $options['user_id'] ?? null,
                'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
                'cash_shift_id' => $options['cash_shift_id'] ?? null,
                'sale_id' => $sale->id,
                'type' => $options['movement_type'] ?? 'in',
                'quantity' => $effectiveDelta,
                'unit_cost' => $batch?->unit_cost ?? $product->cost_price,
                'notes' => $options['movement_notes'] ?? "Reversión por anulación de Venta #{$sale->id}",
            ]);

            $restoredTotal = round($restoredTotal + $effectiveDelta, 3);
            if ($remainingToRestore !== null) {
                $remainingToRestore = max(0.0, round($remainingToRestore - $restoreHere, 3));
            }
        }

        // Respaldo por si existió alguna venta histórica previa a la activación de lotes
        // (evita crear lotes fantasmas si el residuo <= 0.010 es por redondeo de fracciones 1/6 o 1/12)
        $residualTolerance = $restoredTotal > 0.0005 ? 0.010 : 0.0005;
        if ($remainingToRestore !== null && $remainingToRestore > $residualTolerance) {
            $fallbackBatch = $this->receiveStockBatch($product, $remainingToRestore, [
                'source_type' => ProductBatch::SOURCE_ADJUSTMENT,
                'batch_code' => $this->generateBatchCode('REV', $product),
                'notes' => "Devolución sin asignación previa de lote (Venta #{$sale->id})",
                'user_id' => $options['user_id'] ?? null,
                'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
                'movement_type' => $options['movement_type'] ?? 'in',
                'movement_notes' => $options['movement_notes'] ?? "Reversión por anulación de Venta #{$sale->id}",
            ]);
            StockMovement::where('batch_id', $fallbackBatch->id)->latest('id')->limit(1)->update([
                'sale_id' => $sale->id,
                'cash_shift_id' => $options['cash_shift_id'] ?? null,
            ]);
            $restoredTotal = round($restoredTotal + $remainingToRestore, 3);
        }

        $this->syncProductMaterializedFields($product);

        return ['restored_total' => $restoredTotal];
    }

    /**
     * Registra la baja por merma / vencimiento de un lote específico valorizando el costo perdido.
     */
    public function writeOffBatch(ProductBatch $batch, ?float $quantity = null, array $options = []): ProductBatch
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('writeOffBatch debe ejecutarse dentro de una transacción.');
        }

        $lockedBatch = ProductBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();
        if (! in_array($lockedBatch->status, [ProductBatch::STATUS_ACTIVE, ProductBatch::STATUS_EXHAUSTED], true)) {
            throw new \InvalidArgumentException("El lote {$lockedBatch->batch_code} no admite bajas en su estado actual ({$lockedBatch->status}).");
        }

        $available = round((float) $lockedBatch->remaining_quantity, 3);
        if ($available <= 0.0005) {
            throw new \InvalidArgumentException("El lote {$lockedBatch->batch_code} no posee stock remanente para dar de baja.");
        }

        $qtyToWriteOff = $quantity !== null ? round($quantity, 3) : $available;
        if ($qtyToWriteOff <= 0 || $qtyToWriteOff > $available + 0.0005) {
            throw new \InvalidArgumentException("La cantidad de merma ({$qtyToWriteOff}) supera el remanente del lote ({$available}).");
        }

        $newRemaining = max(0.0, round($available - $qtyToWriteOff, 3));
        $lockedBatch->remaining_quantity = $newRemaining;
        if ($newRemaining <= 0.0005) {
            $lockedBatch->remaining_quantity = 0.0;
            $lockedBatch->status = ProductBatch::STATUS_WRITTEN_OFF;
        }
        $lockedBatch->save();

        $product = Product::where('id', $lockedBatch->product_id)->lockForUpdate()->firstOrFail();
        $unitCost = $lockedBatch->unit_cost !== null ? (float) $lockedBatch->unit_cost : (float) $product->cost_price;

        StockMovement::create([
            'product_id' => $product->id,
            'batch_id' => $lockedBatch->id,
            'user_id' => $options['user_id'] ?? null,
            'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
            'cash_shift_id' => $options['cash_shift_id'] ?? null,
            'type' => 'waste',
            'quantity' => -$qtyToWriteOff,
            'unit_cost' => $unitCost,
            'notes' => $options['notes'] ?? "Merma / baja de lote {$lockedBatch->batch_code}",
        ]);

        $this->syncProductMaterializedFields($product);

        return $lockedBatch->fresh();
    }

    /**
     * Divide una porción de un lote activo hacia un nuevo sub-lote (ej. Freezer -> Vitrina)
     * con fecha de vencimiento corta, manteniendo invariante el stock físico total del producto.
     *
     * @return array{source_batch: ProductBatch, new_batch: ProductBatch, product: Product}
     */
    public function splitBatch(ProductBatch $batch, float $quantityToSplit, array $options = []): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('splitBatch debe ejecutarse dentro de una transacción.');
        }

        $qty = round($quantityToSplit, 3);
        if ($qty <= 0) {
            throw new \InvalidArgumentException('La cantidad a separar hacia vitrina debe ser mayor a cero.');
        }

        // Orden canónico anti-deadlock: Product primero -> ProductBatch después
        $lockedProduct = Product::where('id', $batch->product_id)->lockForUpdate()->firstOrFail();
        $lockedSource = ProductBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

        if ($lockedSource->status !== ProductBatch::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(
                "El lote {$lockedSource->batch_code} no está activo y no puede dividirse ({$lockedSource->status})."
            );
        }

        $availableToSplit = $lockedSource->available_to_sell;
        if ($qty > $availableToSplit + 0.0005) {
            throw new \InvalidArgumentException(
                "La cantidad a retirar ({$qty}) supera el disponible libre del lote {$lockedSource->batch_code} ({$availableToSplit})."
            );
        }

        $actualQty = min($qty, round((float) $lockedSource->remaining_quantity, 3));
        $newSourceRemaining = max(0.0, round((float) $lockedSource->remaining_quantity - $actualQty, 3));
        $lockedSource->remaining_quantity = $newSourceRemaining;
        if ($newSourceRemaining <= 0.0005 && (float) $lockedSource->reserved_quantity <= 0.0005) {
            $lockedSource->remaining_quantity = 0.0;
            $lockedSource->status = ProductBatch::STATUS_EXHAUSTED;
        }
        $lockedSource->save();

        $newBatchCode = trim((string) ($options['batch_code'] ?? ''));
        if ($newBatchCode === '') {
            $newBatchCode = $this->generateSplitBatchCode($lockedSource);
        }

        $expirationDate = $options['expiration_date'] ?? null;
        if (empty($expirationDate) && ! empty($lockedProduct->vencimiento_dias) && (int) $lockedProduct->vencimiento_dias > 0) {
            $expirationDate = now()->addDays((int) $lockedProduct->vencimiento_dias)->format('Y-m-d');
        } elseif ($expirationDate instanceof Carbon) {
            $expirationDate = $expirationDate->format('Y-m-d');
        } elseif (empty($expirationDate)) {
            $expirationDate = $lockedSource->expiration_date?->format('Y-m-d');
        } else {
            $expirationDate = Carbon::parse((string) $expirationDate)->format('Y-m-d');
        }

        $unitCost = $lockedSource->unit_cost !== null
            ? round((float) $lockedSource->unit_cost, 2)
            : (float) $lockedProduct->cost_price;

        $newBatch = ProductBatch::create([
            'product_id' => $lockedProduct->id,
            'batch_code' => $newBatchCode,
            'source_type' => $lockedSource->source_type,
            'supplier_invoice_id' => $lockedSource->supplier_invoice_id,
            'supplier_id' => $lockedSource->supplier_id,
            'production_order_id' => $lockedSource->production_order_id,
            'manufactured_at' => $lockedSource->manufactured_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'expiration_date' => $expirationDate ?: null,
            'initial_quantity' => $actualQty,
            'remaining_quantity' => $actualQty,
            'reserved_quantity' => 0,
            'unit_cost' => $unitCost,
            'status' => ProductBatch::STATUS_ACTIVE,
            'notes' => $options['notes'] ?? "Pase a vitrina / exhibidor desde lote {$lockedSource->batch_code}",
            'created_by_user_id' => $options['user_id'] ?? null,
        ]);

        // Par de movimientos neto cero en Kardex para trazabilidad completa sin alterar stock físico
        StockMovement::create([
            'product_id' => $lockedProduct->id,
            'batch_id' => $lockedSource->id,
            'user_id' => $options['user_id'] ?? null,
            'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
            'type' => 'out',
            'quantity' => -$actualQty,
            'unit_cost' => $unitCost,
            'notes' => "Pase a vitrina hacia lote {$newBatch->batch_code}",
        ]);

        StockMovement::create([
            'product_id' => $lockedProduct->id,
            'batch_id' => $newBatch->id,
            'user_id' => $options['user_id'] ?? null,
            'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
            'type' => 'in',
            'quantity' => $actualQty,
            'unit_cost' => $unitCost,
            'notes' => "Ingreso a vitrina desde lote {$lockedSource->batch_code}",
        ]);

        $this->syncProductMaterializedFields($lockedProduct);

        return [
            'source_batch' => $lockedSource->fresh('supplier:id,name'),
            'new_batch' => $newBatch->fresh('supplier:id,name'),
            'product' => $lockedProduct->fresh(),
        ];
    }

    /**
     * Registra en una sola operación atómica la merma masiva de múltiples lotes vencidos.
     * Bloquea productos y lotes en orden ID ASC para prevenir deadlocks con la caja POS.
     *
     * @param  array<int, array{batch_id: int, quantity?: float|null, notes?: string|null}>  $items
     * @return array{written_off_count: int, total_units_lost: float, total_cost_lost: float, affected_product_ids: array<int, int>, batches: array<int, ProductBatch>}
     */
    public function writeOffExpiredBatches(array $items = [], array $options = []): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('writeOffExpiredBatches debe ejecutarse dentro de una transacción.');
        }

        $includeToday = (bool) ($options['include_today'] ?? true);
        $cutoffDate = $includeToday
            ? now()->format('Y-m-d')
            : now()->subDay()->format('Y-m-d');

        $requestedByBatchId = [];
        if (! empty($items)) {
            foreach ($items as $entry) {
                $bId = (int) ($entry['batch_id'] ?? 0);
                if ($bId > 0) {
                    $requestedByBatchId[$bId] = [
                        'quantity' => isset($entry['quantity']) && is_numeric($entry['quantity'])
                            ? round((float) $entry['quantity'], 3)
                            : null,
                        'notes' => isset($entry['notes']) ? trim((string) $entry['notes']) : null,
                    ];
                }
            }
            $candidateBatchIds = array_keys($requestedByBatchId);
        } else {
            $candidateBatchIds = ProductBatch::query()
                ->join('products', 'products.id', '=', 'product_batches.product_id')
                ->where('product_batches.status', ProductBatch::STATUS_ACTIVE)
                ->where('product_batches.remaining_quantity', '>', 0)
                ->whereNotNull('product_batches.expiration_date')
                ->whereDate('product_batches.expiration_date', '<=', $cutoffDate)
                ->where('products.active', true)
                ->where('products.is_combo', false)
                ->where('products.is_service', false)
                ->whereNull('products.deleted_at')
                ->pluck('product_batches.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (empty($candidateBatchIds)) {
            return [
                'written_off_count' => 0,
                'total_units_lost' => 0.0,
                'total_cost_lost' => 0.0,
                'affected_product_ids' => [],
                'batches' => [],
            ];
        }

        $productIds = ProductBatch::whereIn('id', $candidateBatchIds)
            ->pluck('product_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (! empty($productIds)) {
            Product::whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get();
        }

        sort($candidateBatchIds);
        $lockedBatches = ProductBatch::whereIn('id', $candidateBatchIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $writtenOff = [];
        $affectedProductIds = [];
        $totalUnits = 0.0;
        $totalCost = 0.0;
        $defaultNote = trim((string) ($options['notes'] ?? 'Barrido masivo de lotes vencidos al cierre'));

        foreach ($lockedBatches as $lockedBatch) {
            if ($lockedBatch->status !== ProductBatch::STATUS_ACTIVE || (float) $lockedBatch->remaining_quantity <= 0.0005) {
                continue;
            }

            $override = $requestedByBatchId[$lockedBatch->id] ?? null;
            $qty = $override['quantity'] ?? null;
            $note = ! empty($override['notes'])
                ? $override['notes']
                : "{$defaultNote} ({$lockedBatch->batch_code})";

            $avail = round((float) $lockedBatch->remaining_quantity, 3);
            $effectiveQty = $qty !== null ? min($avail, round((float) $qty, 3)) : $avail;
            if ($effectiveQty <= 0.0005) {
                continue;
            }

            $unitCost = $lockedBatch->unit_cost !== null
                ? (float) $lockedBatch->unit_cost
                : (float) (Product::find($lockedBatch->product_id)?->cost_price ?? 0.0);

            $updated = $this->writeOffBatch($lockedBatch, $effectiveQty, [
                'user_id' => $options['user_id'] ?? null,
                'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
                'cash_shift_id' => $options['cash_shift_id'] ?? null,
                'notes' => $note,
            ]);

            $writtenOff[] = $updated;
            $affectedProductIds[$lockedBatch->product_id] = true;
            $totalUnits = round($totalUnits + $effectiveQty, 3);
            $totalCost = round($totalCost + ($effectiveQty * $unitCost), 2);
        }

        return [
            'written_off_count' => count($writtenOff),
            'total_units_lost' => $totalUnits,
            'total_cost_lost' => $totalCost,
            'affected_product_ids' => array_map('intval', array_keys($affectedProductIds)),
            'batches' => $writtenOff,
        ];
    }

    protected function generateSplitBatchCode(ProductBatch $sourceBatch): string
    {
        $cleanPrefix = mb_substr($sourceBatch->batch_code, 0, 56);
        $seq = 1;
        do {
            $candidate = "{$cleanPrefix}-V{$seq}";
            $exists = ProductBatch::withTrashed()
                ->where('product_id', $sourceBatch->product_id)
                ->where('batch_code', $candidate)
                ->exists();
            $seq++;
        } while ($exists && $seq <= 500);

        return $candidate;
    }

    /**
     * Devuelve una cantidad exacta a un lote específico (ej. al anular una orden de producción).
     */
    public function restoreQuantityToBatch(Product $product, ProductBatch $batch, float $quantity, array $options = []): ProductBatch
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('restoreQuantityToBatch debe ejecutarse dentro de una transacción.');
        }

        $lockedBatch = ProductBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();
        $qty = round($quantity, 3);
        if ($qty <= 0) {
            return $lockedBatch;
        }

        $snap = self::computeQuantityDeltaWithFractionSnap((float) $lockedBatch->remaining_quantity, +$qty);
        $lockedBatch->remaining_quantity = $snap['new_stock'];

        if ($lockedBatch->status === ProductBatch::STATUS_DEFICIT && (float) $lockedBatch->remaining_quantity >= -0.0005) {
            $lockedBatch->remaining_quantity = max(0.0, (float) $lockedBatch->remaining_quantity);
            $lockedBatch->status = (float) $lockedBatch->remaining_quantity > 0.0005
                ? ProductBatch::STATUS_ACTIVE
                : ProductBatch::STATUS_EXHAUSTED;
        } elseif ($lockedBatch->status === ProductBatch::STATUS_EXHAUSTED && (float) $lockedBatch->remaining_quantity > 0.0005) {
            $lockedBatch->status = ProductBatch::STATUS_ACTIVE;
        }

        $lockedBatch->save();

        StockMovement::create([
            'product_id' => $product->id,
            'batch_id' => $lockedBatch->id,
            'user_id' => $options['user_id'] ?? null,
            'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
            'type' => $options['movement_type'] ?? 'in',
            'quantity' => abs($snap['effective_delta']),
            'unit_cost' => $lockedBatch->unit_cost ?? $product->cost_price,
            'notes' => $options['movement_notes'] ?? "Devolución a lote {$lockedBatch->batch_code}",
        ]);

        $this->syncProductMaterializedFields($product);

        return $lockedBatch->fresh();
    }

    /**
     * Bloquea en orden determinista los lotes activos de un producto según su política FEFO o FIFO.
     * Debe invocarse siempre después de bloquear `products` para respetar el orden anti-deadlock.
     */
    protected function lockOrderedActiveBatches(Product $product)
    {
        $query = ProductBatch::where('product_id', $product->id)
            ->where('status', ProductBatch::STATUS_ACTIVE);

        if ($product->lot_deduction_policy === 'fifo') {
            $query->orderByRaw('CASE WHEN manufactured_at IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('manufactured_at', 'asc')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc');
        } else {
            $query->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('expiration_date', 'asc')
                ->orderBy('id', 'asc');
        }

        return $query->lockForUpdate()->get();
    }

    protected function lockOrCreateDeficitBatch(Product $product, ?int $userId): ProductBatch
    {
        $existing = ProductBatch::where('product_id', $product->id)
            ->where('source_type', ProductBatch::SOURCE_DEFICIT_AUTO)
            ->where('status', ProductBatch::STATUS_DEFICIT)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return ProductBatch::create([
            'product_id' => $product->id,
            'batch_code' => 'DEFICIT-AUTO',
            'source_type' => ProductBatch::SOURCE_DEFICIT_AUTO,
            'manufactured_at' => now()->format('Y-m-d'),
            'expiration_date' => null,
            'initial_quantity' => 0.0,
            'remaining_quantity' => 0.0,
            'unit_cost' => $product->cost_price,
            'status' => ProductBatch::STATUS_DEFICIT,
            'notes' => 'Lote automático de contingencia por ventas sin stock cargado',
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Concilia saldos negativos acumulados en `deficit_auto` transfiriendo sus asignaciones al nuevo lote real.
     */
    protected function absorbOpenDeficits(Product $product, ProductBatch $newBatch): void
    {
        $deficitBatches = ProductBatch::where('product_id', $product->id)
            ->where('status', ProductBatch::STATUS_DEFICIT)
            ->where('remaining_quantity', '<', 0)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($deficitBatches->isEmpty() || (float) $newBatch->remaining_quantity <= 0.0005) {
            return;
        }

        $availableToAbsorb = round((float) $newBatch->remaining_quantity, 3);

        foreach ($deficitBatches as $deficitBatch) {
            if ($availableToAbsorb <= 0.0005) {
                break;
            }

            $deficitQty = round(abs((float) $deficitBatch->remaining_quantity), 3);
            $absorbedHere = min($availableToAbsorb, $deficitQty);
            if ($absorbedHere <= 0.0005) {
                continue;
            }

            $this->reassignDeficitAllocations($deficitBatch, $newBatch, $absorbedHere);

            $deficitBatch->remaining_quantity = round((float) $deficitBatch->remaining_quantity + $absorbedHere, 3);
            if ((float) $deficitBatch->remaining_quantity >= -0.0005) {
                $deficitBatch->remaining_quantity = 0.0;
                $deficitBatch->status = ProductBatch::STATUS_EXHAUSTED;
            }
            $deficitBatch->save();

            $newBatch->remaining_quantity = max(0.0, round((float) $newBatch->remaining_quantity - $absorbedHere, 3));
            if ((float) $newBatch->remaining_quantity <= 0.0005) {
                $newBatch->remaining_quantity = 0.0;
                $newBatch->status = ProductBatch::STATUS_EXHAUSTED;
            }
            $newBatch->save();

            $availableToAbsorb = round((float) $newBatch->remaining_quantity, 3);
        }
    }

    protected function reassignDeficitAllocations(ProductBatch $deficitBatch, ProductBatch $targetBatch, float $qtyToReassign): void
    {
        $pending = round($qtyToReassign, 3);
        $allocations = SaleItemBatchAllocation::where('batch_id', $deficitBatch->id)
            ->whereColumn('quantity', '>', 'restored_quantity')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $alloc) {
            if ($pending <= 0.0005) {
                break;
            }

            $unrestored = $alloc->unrestored_quantity;
            if ($unrestored <= 0.0005) {
                continue;
            }

            if ($unrestored <= $pending + 0.0005) {
                // Caso simple: toda la asignación pendiente pasa al nuevo lote real
                if ((float) $alloc->restored_quantity <= 0.0005) {
                    $alloc->batch_id = $targetBatch->id;
                    if ($targetBatch->unit_cost !== null) {
                        $alloc->unit_cost = $targetBatch->unit_cost;
                    }
                    $alloc->save();
                } else {
                    $alloc->quantity = (float) $alloc->restored_quantity;
                    $alloc->save();
                    SaleItemBatchAllocation::create([
                        'sale_id' => $alloc->sale_id,
                        'sale_item_id' => $alloc->sale_item_id,
                        'product_id' => $alloc->product_id,
                        'batch_id' => $targetBatch->id,
                        'quantity' => $unrestored,
                        'restored_quantity' => 0,
                        'unit_cost' => $targetBatch->unit_cost ?? $alloc->unit_cost,
                    ]);
                }
                $pending = max(0.0, round($pending - $unrestored, 3));
            } else {
                // Cobertura parcial: dividir la asignación manteniendo el remanente sin cubrir en déficit
                $alloc->quantity = round((float) $alloc->quantity - $pending, 3);
                $alloc->save();
                SaleItemBatchAllocation::create([
                    'sale_id' => $alloc->sale_id,
                    'sale_item_id' => $alloc->sale_item_id,
                    'product_id' => $alloc->product_id,
                    'batch_id' => $targetBatch->id,
                    'quantity' => $pending,
                    'restored_quantity' => 0,
                    'unit_cost' => $targetBatch->unit_cost ?? $alloc->unit_cost,
                ]);
                $pending = 0.0;
            }
        }
    }

    protected function recordDeductionTraceability(
        Product $product,
        ProductBatch $batch,
        float $taken,
        float $unitCost,
        array $options
    ): ?SaleItemBatchAllocation {
        $movementType = (string) ($options['movement_type'] ?? 'sale');
        $movementQty = $movementType === 'sale_ecommerce' ? abs($taken) : -abs($taken);

        StockMovement::create([
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'user_id' => $options['user_id'] ?? null,
            'authorized_by_admin_id' => $options['authorized_by_admin_id'] ?? null,
            'cash_shift_id' => $options['cash_shift_id'] ?? null,
            'sale_id' => $options['sale_id'] ?? null,
            'supplier_invoice_id' => $options['supplier_invoice_id'] ?? null,
            'type' => $movementType,
            'quantity' => $movementQty,
            'unit_cost' => $unitCost,
            'notes' => $options['movement_notes'] ?? "Egreso de lote {$batch->batch_code}",
        ]);

        if (! empty($options['sale_id']) && ($options['record_sale_allocation'] ?? true)) {
            return SaleItemBatchAllocation::create([
                'sale_id' => (int) $options['sale_id'],
                'sale_item_id' => $options['sale_item_id'] ?? null,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'quantity' => $taken,
                'restored_quantity' => 0,
                'unit_cost' => $unitCost,
            ]);
        }

        return null;
    }
}
