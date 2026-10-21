<?php

namespace App\Services;

use App\DTOs\SaleContextDTO;
use App\Models\BusinessSetting;
use App\Models\DeliveryNote;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
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
            ->with('children')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Deduct stock for a brand new sale or checkout.
     */
    public function processCartStock(array $items, Collection $products, Sale $sale, SaleContextDTO $context, bool $requiresDispatch, string $fulfillmentStatus): void
    {
        $shouldDeductStock = (! $requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');

        if (! $shouldDeductStock) {
            return; // Stock will be deducted upon delivery
        }

        $metric = $this->getPopularityMetric();
        $countedInTicket = [];

        foreach ($items as $itemData) {
            if (! isset($products[$itemData['product_id']])) {
                continue;
            }

            $product = $products[$itemData['product_id']];
            $qty = (float) $itemData['quantity'];

            // En modo 'tickets' cada producto suma como máximo 1 vez por ticket
            $trackPopularity = true;
            if ($metric === 'tickets' && $qty > 0) {
                if (isset($countedInTicket[$product->id])) {
                    $trackPopularity = false;
                } else {
                    $countedInTicket[$product->id] = true;
                }
            }

            $this->deductProductStock($product, $qty, $sale, $context, false, $products, $trackPopularity);
        }
    }

    /**
     * Deduct stock mathematically for a specific product (Combo-aware)
     */
    protected function deductProductStock(Product $product, float $qty, Sale $sale, SaleContextDTO $context, bool $isAdjustment, Collection $products, bool $trackPopularity = true): void
    {
        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyDeducted = $qty * $child->pivot->quantity;

                // CRITICAL FIX: Use canonical instance from the root $products collection
                // to prevent in-memory race conditions if the same product is processed twice.
                $canonicalChild = $products[$child->id] ?? $child;
                $canonicalChild->stock -= $qtyDeducted;
                $canonicalChild->save(); // Dispara Observers

                $this->logMovement($canonicalChild->id, -$qtyDeducted, 'sale',
                    ($isAdjustment ? 'Ajuste Recall ' : 'Venta ')."Ticket #{$sale->id} (Hijo de: {$product->name})",
                    $context, $sale);
            }
        } else {
            // Update the canonical product (which $product already is, as it comes from $products)
            $product->stock -= $qty;
            $this->logMovement($product->id, -$qty, 'sale',
                ($isAdjustment ? 'Ajuste Recall ' : 'Venta ')."Ticket #{$sale->id}",
                $context, $sale);
        }

        if ($trackPopularity && ! $context->isInternalAccount && $qty > 0) {
            $product->sales_count += $this->popularityPoints($qty);
        }
        $product->save(); // Dispara Observers
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
            $originalQuantities[$oldItem->product_id] = ($originalQuantities[$oldItem->product_id] ?? 0) + $oldItem->quantity;
        }

        $newQuantities = [];
        foreach ($newItems as $newItem) {
            $newQuantities[$newItem['product_id']] = ($newQuantities[$newItem['product_id']] ?? 0) + $newItem['quantity'];
        }

        $allProductIds = array_unique(array_merge(array_keys($originalQuantities), array_keys($newQuantities)));

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
    public function restoreStockForVoid(Sale $sale, SaleContextDTO $context, ?DeliveryNote $deliveryNote): void
    {
        $productIds = $sale->items->pluck('product_id')->unique()->toArray();

        $products = $this->lockProducts($productIds);

        // Con remito diferido el mostrador nunca sumó popularidad (ni stock), por lo que no hay nada que revertir.
        $deferredDelivery = $deliveryNote && ! $sale->hasDeductedStock();
        $metric = $this->getPopularityMetric();
        $restoredPopularityProductIds = [];

        foreach ($sale->items as $item) {
            if (! isset($products[$item->product_id])) {
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
                $this->addStockBack($product, $qtyToRestore, $sale, $context, $products);
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

    protected function addStockBack(Product $product, float $qtyToRestore, Sale $sale, SaleContextDTO $context, Collection $products): void
    {
        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyRestored = $qtyToRestore * $child->pivot->quantity;
                $canonicalChild = $products[$child->id] ?? $child;
                $canonicalChild->stock += $qtyRestored;
                $canonicalChild->save();

                $this->logMovement($canonicalChild->id, $qtyRestored, 'in', "Reversión (Combo Hijo) Venta #{$sale->id}", $context, $sale);
            }
        } else {
            $product->stock += $qtyToRestore;
            $product->save();

            $this->logMovement($product->id, $qtyToRestore, 'in', "Reversión por anulación de Venta #{$sale->id}", $context, $sale);
        }
    }

    /**
     * Calculate historical cost for combos or normal products
     */
    public function calculateCostPrice(Product $product): float
    {
        if ($product->is_combo) {
            $currentCostPrice = 0.0;
            foreach ($product->children as $child) {
                $currentCostPrice += ((float) $child->cost_price * $child->pivot->quantity);
            }

            return $currentCostPrice;
        }

        return (float) $product->cost_price;
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
}
