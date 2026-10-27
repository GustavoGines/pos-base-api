<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EcommerceSyncLog;
use App\Models\Product;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncProductStockToPlatformsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 30, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $productId,
        public ?float $newStock = null,
        public ?int $excludedIntegrationId = null
    ) {
        $this->onQueue('ecommerce-sync');
    }

    /**
     * Despacha la sincronización asíncrona post-commit para un conjunto de IDs de productos,
     * propagando automáticamente la actualización a cualquier Combo padre que los utilice como ingrediente.
     */
    public static function dispatchForProducts(iterable $productIds, ?int $excludedIntegrationId = null): void
    {
        $ids = [];
        foreach ($productIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return;
        }

        $parentComboIds = DB::table('product_combos')
            ->whereIn('child_product_id', $ids)
            ->pluck('parent_product_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $allIds = array_values(array_unique(array_merge($ids, $parentComboIds)));

        $currentStocks = DB::table('products')
            ->whereIn('id', $allIds)
            ->pluck('stock', 'id');

        foreach ($allIds as $id) {
            $stockSnapshot = isset($currentStocks[$id]) ? (float) $currentStocks[$id] : null;
            self::dispatch($id, $stockSnapshot, $excludedIntegrationId)->afterCommit();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(EcommerceManager $ecommerceManager): void
    {
        $product = Product::with(['children', 'ecommerceMappings.integration'])->find($this->productId);
        if (! $product) {
            return;
        }

        // 1. Calculate base stock (Combo-aware virtual stock or physical stock)
        // Fix F1-ALTA-03: Siempre leer el stock real autoritativo desde la base de datos fresca
        // en lugar de confiar en $this->newStock congelado al encolar, evitando condiciones de carrera.
        if ($product->is_combo) {
            if ($product->children->isEmpty()) {
                $baseStock = 0.0;
            } else {
                $limiting = null;
                foreach ($product->children as $child) {
                    $pivotQty = (float) ($child->pivot->quantity ?? 1);
                    if ($pivotQty <= 0) {
                        continue;
                    }
                    $availableForChild = floor((float) $child->stock / $pivotQty);
                    if ($limiting === null || $availableForChild < $limiting) {
                        $limiting = $availableForChild;
                    }
                }
                $baseStock = (float) max(0, $limiting ?? 0);
            }
        } else {
            $baseStock = (float) $product->stock;
        }

        // 2. Iterate through each platform mapping
        $mappings = $product->ecommerceMappings;
        foreach ($mappings as $mapping) {
            // Skip excluded integration (e.g. Origin of inbound order to avoid loop)
            if ($this->excludedIntegrationId !== null && $mapping->integration_id === $this->excludedIntegrationId) {
                continue;
            }

            $integration = $mapping->integration;
            if (! $integration) {
                continue;
            }

            // Check if active and enabled
            $isChannelActive = $integration->is_active && $integration->sync_stock_enabled && ! $mapping->is_paused && $mapping->sync_stock;

            if (! $isChannelActive) {
                // If mapping is paused or integration inactive, send 0 / paused
                $targetStock = 0.0;
            } elseif ($baseStock <= 0) {
                $targetStock = 0.0;
            } else {
                $threshold = (float) $mapping->safety_stock_threshold;
                $percentage = (float) $mapping->allocated_stock_percentage;
                $targetStock = (float) max(0, floor(($baseStock - $threshold) * ($percentage / 100)));
            }

            try {
                $adapter = $ecommerceManager->forIntegration($integration);

                // Anti-echo cache: 120s TTL
                $echoKey = "ecommerce_echo:{$mapping->platform}:{$mapping->external_product_id}:".(int) $targetStock;
                Cache::put($echoKey, true, 120);

                $startTime = microtime(true);
                $success = $adapter->updateStock(
                    $mapping->external_product_id,
                    $targetStock,
                    $mapping->external_variant_id
                );
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);

                // FINDING-STOCK-03: Capturar stock previo antes de actualizar el mapping
                $stockBefore = $mapping->last_known_external_stock !== null ? (float) $mapping->last_known_external_stock : null;

                // Update mapping tracking fields
                $mapping->update([
                    'last_known_local_stock' => $baseStock,
                    'last_known_external_stock' => $targetStock,
                    'last_synced_at' => now(),
                    'sync_status' => $success ? 'synced' : 'error',
                    'sync_error_message' => $success ? null : 'Adapter stock update failed',
                ]);

                // Record audit log
                EcommerceSyncLog::create([
                    'integration_id' => $integration->id,
                    'mapping_id' => $mapping->id,
                    'external_order_id' => null,
                    'direction' => 'outbound',
                    'action' => 'update_stock',
                    'stock_before' => $stockBefore,
                    'stock_after' => $targetStock,
                    'request_payload' => [
                        'external_product_id' => $mapping->external_product_id,
                        'external_variant_id' => $mapping->external_variant_id,
                        'target_stock' => $targetStock,
                    ],
                    'response_payload' => ['success' => $success],
                    'http_status' => $success ? 200 : 500,
                    'duration_ms' => $durationMs,
                    'status' => $success ? 'success' : 'failed',
                    'error_message' => $success ? null : 'Adapter stock update failed',
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to sync stock for product {$product->id} on integration {$integration->id}: ".$e->getMessage());

                $mapping->update([
                    'sync_status' => 'error',
                    'sync_error_message' => $e->getMessage(),
                ]);

                EcommerceSyncLog::create([
                    'integration_id' => $integration->id,
                    'mapping_id' => $mapping->id,
                    'external_order_id' => null,
                    'direction' => 'outbound',
                    'action' => 'update_stock',
                    'stock_before' => $mapping->last_known_external_stock,
                    'stock_after' => $targetStock,
                    'request_payload' => [
                        'external_product_id' => $mapping->external_product_id,
                        'external_variant_id' => $mapping->external_variant_id,
                        'target_stock' => $targetStock,
                    ],
                    'response_payload' => ['error' => $e->getMessage()],
                    'http_status' => 500,
                    'duration_ms' => null,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
