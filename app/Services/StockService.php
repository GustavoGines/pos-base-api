<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Sale;
use App\Models\DeliveryNote;
use App\DTOs\SaleContextDTO;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Pre-carga y bloquea todos los productos (padres e hijos) en una sola query para evitar N+1 y Deadlocks.
     */
    public function lockProducts(array $productIds): \Illuminate\Database\Eloquent\Collection
    {
        $uniqueIds = array_unique($productIds);
        
        $childIds = DB::table('product_combos')
            ->whereIn('parent_product_id', $uniqueIds)
            ->pluck('child_product_id')
            ->toArray();
            
        $allIds = array_unique(array_merge($uniqueIds, $childIds));
        sort($allIds); // Anti-Deadlock

        return Product::whereIn('id', $allIds)
            ->with('children')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Deduct stock for a brand new sale or checkout.
     */
    public function processCartStock(array $items, \Illuminate\Database\Eloquent\Collection $products, Sale $sale, SaleContextDTO $context, bool $requiresDispatch, string $fulfillmentStatus): void
    {
        $shouldDeductStock = (!$requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');

        if (!$shouldDeductStock) {
            return; // Stock will be deducted upon delivery
        }

        foreach ($items as $itemData) {
            if (!isset($products[$itemData['product_id']])) continue;
            
            $product = $products[$itemData['product_id']];
            $qty = (float) $itemData['quantity'];
            
            $this->deductProductStock($product, $qty, $sale, $context, false);
        }
    }

    /**
     * Deduct stock mathematically for a specific product (Combo-aware)
     */
    protected function deductProductStock(Product $product, float $qty, Sale $sale, SaleContextDTO $context, bool $isAdjustment): void
    {
        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyDeducted = $qty * $child->pivot->quantity;
                $child->stock -= $qtyDeducted;
                $child->save(); // Dispara Observers

                $this->logMovement($child->id, -$qtyDeducted, 'sale', 
                    ($isAdjustment ? "Ajuste Recall " : "Venta ") . "Ticket #{$sale->id} (Hijo de: {$product->name})", 
                    $context, $sale);
            }
        } else {
            $product->stock -= $qty;
            $this->logMovement($product->id, -$qty, 'sale', 
                ($isAdjustment ? "Ajuste Recall " : "Venta ") . "Ticket #{$sale->id}", 
                $context, $sale);
        }

        if (!$context->isInternalAccount) {
            if ($qty > 0) {
                $product->sales_count += $qty;
            } else {
                // Si es un ajuste negativo (quitar del carrito), decrementamos seguro
                $product->sales_count = max(0, $product->sales_count + $qty); // $qty is negative here
            }
        }
        $product->save(); // Dispara Observers
    }

    /**
     * Exact math for Order Recall (modifying pending tickets).
     */
    public function reconcileStockDiff(array $newItems, Sale $sale, SaleContextDTO $context): void
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
            $oldQty = $originalQuantities[$productId] ?? 0;
            $newQty = $newQuantities[$productId] ?? 0;
            $diff = $newQty - $oldQty; // Positivo = agregó más al carrito, Negativo = quitó
            
            if ($diff != 0 && isset($products[$productId])) {
                $product = $products[$productId];
                $this->deductProductStock($product, $diff, $sale, $context, true);
            }
        }
    }

    /**
     * Devuelve el stock al anular una venta (Void). Sensible a Remitos.
     */
    public function restoreStockForVoid(Sale $sale, SaleContextDTO $context, ?DeliveryNote $deliveryNote): void
    {
        $productIds = $sale->items->pluck('product_id')->unique()->toArray();
        
        $products = $this->lockProducts($productIds);

        foreach ($sale->items as $item) {
            if (!isset($products[$item->product_id])) continue;
            
            $product = $products[$item->product_id];
            $qtyToRestore = (float) $item->quantity;

            if ($deliveryNote) {
                // Si hay remito, solo devolvemos lo que ya fue entregado
                $dnItem = $deliveryNote->items->where('product_id', $item->product_id)->first();
                $qtyToRestore = $dnItem ? (float) $dnItem->quantity_delivered : 0.0;
            }

            if ($qtyToRestore > 0) {
                $this->addStockBack($product, $qtyToRestore, $sale, $context);
            }

            // Restauración segura de popularidad (GREATEST logic equivalent in memory since we locked)
            if (!$context->isInternalAccount) {
                $product->sales_count = max(0, $product->sales_count - (float)$item->quantity);
                $product->save();
            }
        }
    }

    protected function addStockBack(Product $product, float $qtyToRestore, Sale $sale, SaleContextDTO $context): void
    {
        if ($product->is_combo) {
            foreach ($product->children as $child) {
                $qtyRestored = $qtyToRestore * $child->pivot->quantity;
                $child->stock += $qtyRestored;
                $child->save();

                $this->logMovement($child->id, $qtyRestored, 'in', "Reversión (Combo Hijo) Venta #{$sale->id}", $context, $sale);
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
                $currentCostPrice += ((float)$child->cost_price * $child->pivot->quantity);
            }
            return $currentCostPrice;
        }
        
        return (float) $product->cost_price;
    }

    protected function logMovement(int $productId, float $quantity, string $type, string $notes, SaleContextDTO $context, ?Sale $sale = null): void
    {
        StockMovement::create([
            'product_id'    => $productId,
            'user_id'       => $context->userId ?? 1,
            'cash_shift_id' => $context->cashShiftId,
            'sale_id'       => $sale?->id,
            'type'          => $type,
            'quantity'      => $quantity,
            'notes'         => $notes,
        ]);
    }
}
