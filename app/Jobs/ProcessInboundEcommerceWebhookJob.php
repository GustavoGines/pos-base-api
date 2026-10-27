<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EcommerceProductMapping;
use App\Models\EcommerceSyncLog;
use App\Models\EcommerceWebhook;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessInboundEcommerceWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max retries.
     */
    public int $tries = 3;

    /**
     * Backoff delays in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 30, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $webhookId
    ) {
        $this->onQueue('ecommerce-inbound');
    }

    /**
     * Execute the job.
     */
    public function handle(EcommerceManager $ecommerceManager, StockService $stockService): void
    {
        $webhook = EcommerceWebhook::with('integration')->find($this->webhookId);
        if (! $webhook || $webhook->status === 'processed') {
            return;
        }

        $webhook->update(['status' => 'processing']);

        $integration = $webhook->integration;
        if (! $integration) {
            $webhook->update([
                'status' => 'failed',
                'error_message' => 'Integration record not found for webhook',
            ]);

            return;
        }

        try {
            $adapter = $ecommerceManager->forIntegration($integration);
            $payload = is_array($webhook->payload) ? $webhook->payload : (json_decode((string) $webhook->payload, true) ?: []);
            $parsed = $adapter->parseWebhook(new Request(
                query: [],
                request: $payload,
                attributes: [],
                cookies: [],
                files: [],
                server: [
                    'REQUEST_METHOD' => 'POST',
                    'CONTENT_TYPE' => 'application/json',
                ],
                content: json_encode($payload)
            ));

            $externalOrderId = $parsed['external_order_id'] 
                ?? $payload['order_id'] 
                ?? $payload['order_number'] 
                ?? $payload['id'] 
                ?? null;

            if ($externalOrderId !== null) {
                $externalOrderId = (string) $externalOrderId;
            }

            // 1. Order-level Idempotency Check
            if ($externalOrderId !== null) {
                $alreadyDeducted = EcommerceSyncLog::where('integration_id', $integration->id)
                    ->where('external_order_id', $externalOrderId)
                    ->where('action', 'order_deduct')
                    ->where('status', 'success')
                    ->exists();

                if ($alreadyDeducted) {
                    $webhook->update([
                        'status' => 'processed',
                        'processed_at' => now(),
                    ]);

                    return;
                }
            }

            // 2. Extract line items from webhook payload or remote order API (Zero-Trust pattern)
            $orderItems = $this->extractOrderItems($webhook, $adapter, $externalOrderId);
            if (empty($orderItems)) {
                $webhook->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                return;
            }

            // 3. Resolve local product mappings
            $resolvedItems = [];
            $productIds = [];

            foreach ($orderItems as $item) {
                $extProdId = (string) ($item['external_product_id'] ?? '');
                $extVarId = ! empty($item['external_variant_id']) ? (string) $item['external_variant_id'] : '0';
                $sku = isset($item['sku']) ? (string) $item['sku'] : null;
                $name = isset($item['name']) ? (string) $item['name'] : null;
                $quantity = (float) ($item['quantity'] ?? 1);

                if ($extProdId === '' && empty($sku) && empty($name)) {
                    continue;
                }

                $mapping = EcommerceProductMapping::where('integration_id', $integration->id)
                    ->where('external_product_id', $extProdId)
                    ->where(function ($q) use ($extVarId) {
                        $q->where('external_variant_id', $extVarId)
                            ->orWhere('external_variant_id', '0')
                            ->orWhereNull('external_variant_id');
                    })
                    ->first();

                if (! $mapping && ! empty($sku)) {
                    $mapping = EcommerceProductMapping::where('integration_id', $integration->id)
                        ->where('external_sku', $sku)
                        ->first();
                }

                // Auto-mapping inteligente en cascada si no existe vinculación previa
                if (! $mapping) {
                    $localProduct = null;

                    // 1. Coincidencia por SKU local (internal_code)
                    if (! empty($sku)) {
                        $localProduct = Product::where('internal_code', $sku)->first();
                    }

                    // 2. Coincidencia por Código de barras
                    if (! $localProduct && ! empty($sku)) {
                        $localProduct = Product::where('barcode', $sku)->first();
                    }
                    if (! $localProduct && $extProdId !== '') {
                        $localProduct = Product::where('barcode', $extProdId)->first();
                    }

                    // 3. Coincidencia por Nombre exacto (case-insensitive)
                    if (! $localProduct && ! empty($name)) {
                        $cleanName = mb_strtolower(trim($name));
                        $localProduct = Product::whereRaw('LOWER(TRIM(name)) = ?', [$cleanName])->first();
                    }

                    // 4. Coincidencia por ID numérico directo si external_product_id es entero
                    if (! $localProduct && $extProdId !== '' && ctype_digit($extProdId)) {
                        $localProduct = Product::find((int) $extProdId);
                    }

                    if ($localProduct) {
                        $mapping = EcommerceProductMapping::create([
                            'integration_id' => $integration->id,
                            'product_id' => $localProduct->id,
                            'platform' => $integration->platform,
                            'external_product_id' => $extProdId !== '' ? $extProdId : (string) $localProduct->id,
                            'external_variant_id' => $extVarId,
                            'external_sku' => $sku ?? $localProduct->internal_code,
                            'external_title' => $name ?? $localProduct->name,
                            'sync_stock' => true,
                            'last_known_local_stock' => (float) $localProduct->stock,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                    }
                }

                if ($mapping && $mapping->product_id) {
                    $resolvedItems[] = [
                        'mapping' => $mapping,
                        'product_id' => $mapping->product_id,
                        'quantity' => $quantity,
                    ];
                    $productIds[] = $mapping->product_id;
                }
            }

            if (empty($resolvedItems)) {
                $webhook->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                return;
            }

            // 4. Strict Ascending Order Pessimistic Locking via StockService
            $sortedProductIds = collect($productIds)->unique()->sort()->values()->all();

            DB::transaction(function () use (
                $stockService,
                $sortedProductIds,
                $resolvedItems,
                $integration,
                $webhook,
                $externalOrderId
            ) {
                $lockedProducts = $stockService->lockProducts($sortedProductIds);
                $affectedProductsToPropagate = [];

                foreach ($resolvedItems as $item) {
                    $productId = $item['product_id'];
                    $qty = (float) $item['quantity'];
                    /** @var EcommerceProductMapping $mapping */
                    $mapping = $item['mapping'];

                    $product = $lockedProducts->get($productId) ?? Product::find($productId);
                    if (! $product) {
                        continue;
                    }

                    $stockBefore = $product->is_combo
                        ? $product->calculateComboAvailableStock()
                        : (float) $product->stock;

                    if ($product->is_combo) {
                        // Combo sale: deduct from component children
                        foreach ($product->children as $child) {
                            $lockedChild = $lockedProducts->get($child->id) ?? Product::find($child->id);
                            if ($lockedChild) {
                                $pivotQty = (float) ($child->pivot->quantity ?? 1);
                                $childDeduct = $qty * $pivotQty;
                                $lockedChild->stock = (float) $lockedChild->stock - $childDeduct;
                                $lockedChild->save();

                                StockMovement::create([
                                    'product_id' => $lockedChild->id,
                                    'type' => 'sale_ecommerce',
                                    'quantity' => $childDeduct,
                                    'notes' => "Venta Online {$webhook->platform} Orden #{$externalOrderId} (Hijo de Combo #{$product->id})",
                                ]);

                                $affectedProductsToPropagate[$lockedChild->id] = (float) $lockedChild->stock;
                            }
                        }

                        $product->unsetRelation('children');
                        $stockAfter = $product->calculateComboAvailableStock();
                        $affectedProductsToPropagate[$product->id] = $stockAfter;
                        $status = 'success';
                    } else {
                        // Single product sale
                        $product->stock = (float) $product->stock - $qty;
                        $product->save();

                        $stockAfter = (float) $product->stock;
                        $status = $stockAfter < 0 ? 'stock_conflict_review' : 'success';

                        StockMovement::create([
                            'product_id' => $product->id,
                            'type' => 'sale_ecommerce',
                            'quantity' => $qty,
                            'notes' => "Venta Online {$webhook->platform} Orden #{$externalOrderId}",
                        ]);

                        $affectedProductsToPropagate[$product->id] = $stockAfter;
                    }

                    // Record sync log for audit and order-level idempotency
                    EcommerceSyncLog::create([
                        'integration_id' => $integration->id,
                        'mapping_id' => $mapping->id,
                        'external_order_id' => $externalOrderId,
                        'direction' => 'inbound',
                        'action' => 'order_deduct',
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockAfter,
                        'request_payload' => $webhook->payload,
                        'response_payload' => ['deducted_quantity' => $qty],
                        'http_status' => 200,
                        'status' => $status,
                        'error_message' => $status === 'stock_conflict_review' ? 'Stock dropped below 0 (overselling detected)' : null,
                    ]);
                }

                // FINDING-STOCK-02: Propagar recálculo de combos virtuales que contienen a los productos deducidos
                $affectedCombos = app(\App\Services\StockService::class)->recalculateAndGetAffectedParentCombos(array_keys($affectedProductsToPropagate));
                foreach ($affectedCombos as $comboId) {
                    $comboProd = Product::with('children')->find($comboId);
                    if ($comboProd) {
                        $affectedProductsToPropagate[$comboId] = $comboProd->calculateComboAvailableStock();
                    }
                }

                // 5. Cross-Platform Propagation (excluding origin integration to prevent loops)
                foreach ($affectedProductsToPropagate as $prodId => $newStock) {
                    SyncProductStockToPlatformsJob::dispatch(
                        $prodId,
                        $newStock,
                        $integration->id
                    )->afterCommit();
                }
            });

            $webhook->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to process inbound webhook {$webhook->id}: ".$e->getMessage());

            $webhook->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Helper to extract standardized order items from webhook payload or remote API.
     *
     * @return array<int, array{external_product_id: string, external_variant_id: string, quantity: float, sku?: string|null, name?: string|null}>
     */
    protected function extractOrderItems(EcommerceWebhook $webhook, mixed $adapter, ?string $externalOrderId): array
    {
        $payload = $webhook->payload;

        // If items are directly embedded in payload (e.g. WooCommerce line_items, TiendaNube products, or mock items)
        if (! empty($payload['line_items']) && is_array($payload['line_items'])) {
            $items = [];
            foreach ($payload['line_items'] as $li) {
                $items[] = [
                    'external_product_id' => (string) ($li['product_id'] ?? $li['id'] ?? ''),
                    'external_variant_id' => (string) ($li['variation_id'] ?? '0'),
                    'quantity' => (float) ($li['quantity'] ?? 1),
                    'sku' => $li['sku'] ?? null,
                    'name' => $li['name'] ?? $li['title'] ?? null,
                ];
            }

            return $items;
        }

        if (! empty($payload['products']) && is_array($payload['products'])) {
            $items = [];
            foreach ($payload['products'] as $p) {
                $items[] = [
                    'external_product_id' => (string) ($p['product_id'] ?? $p['id'] ?? ''),
                    'external_variant_id' => (string) ($p['variant_id'] ?? '0'),
                    'quantity' => (float) ($p['quantity'] ?? 1),
                    'sku' => $p['sku'] ?? null,
                    'name' => $p['name'] ?? $p['title'] ?? null,
                ];
            }

            return $items;
        }

        if (! empty($payload['order_items']) && is_array($payload['order_items'])) {
            $items = [];
            foreach ($payload['order_items'] as $oi) {
                $items[] = [
                    'external_product_id' => (string) ($oi['item']['id'] ?? $oi['item_id'] ?? $oi['product_id'] ?? ''),
                    'external_variant_id' => (string) ($oi['item']['variation_id'] ?? $oi['variation_id'] ?? '0'),
                    'quantity' => (float) ($oi['quantity'] ?? 1),
                    'sku' => $oi['item']['seller_custom_field'] ?? $oi['sku'] ?? null,
                    'name' => $oi['item']['title'] ?? $oi['name'] ?? null,
                ];
            }

            return $items;
        }

        if (! empty($payload['items']) && is_array($payload['items'])) {
            $items = [];
            foreach ($payload['items'] as $item) {
                $items[] = [
                    'external_product_id' => (string) ($item['external_product_id'] ?? $item['product_id'] ?? $item['id'] ?? ''),
                    'external_variant_id' => (string) ($item['external_variant_id'] ?? $item['variant_id'] ?? '0'),
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'sku' => $item['sku'] ?? null,
                    'name' => $item['name'] ?? $item['title'] ?? null,
                ];
            }

            return $items;
        }

        // If items not in payload, fetch from remote order API if externalOrderId is present (Zero-Trust pattern)
        if ($externalOrderId !== null && method_exists($adapter, 'getOrder')) {
            try {
                $orderData = $adapter->getOrder($externalOrderId);
                if (! empty($orderData)) {
                    // Check order status: only process paid/confirmed orders
                    $orderStatus = strtolower((string) ($orderData['status'] ?? 'paid'));
                    if (in_array($orderStatus, ['cancelled', 'closed', 'refunded'], true)) {
                        return [];
                    }

                    if (! empty($orderData['order_items'])) {
                        $items = [];
                        foreach ($orderData['order_items'] as $oi) {
                            $items[] = [
                                'external_product_id' => (string) ($oi['item']['id'] ?? $oi['item_id'] ?? ''),
                                'external_variant_id' => (string) ($oi['item']['variation_id'] ?? $oi['variation_id'] ?? '0'),
                                'quantity' => (float) ($oi['quantity'] ?? 1),
                                'sku' => $oi['item']['seller_custom_field'] ?? $oi['sku'] ?? null,
                                'name' => $oi['item']['title'] ?? $oi['name'] ?? null,
                            ];
                        }

                        return $items;
                    }

                    if (! empty($orderData['line_items'])) {
                        $items = [];
                        foreach ($orderData['line_items'] as $li) {
                            $items[] = [
                                'external_product_id' => (string) ($li['product_id'] ?? $li['id'] ?? ''),
                                'external_variant_id' => (string) ($li['variation_id'] ?? '0'),
                                'quantity' => (float) ($li['quantity'] ?? 1),
                                'sku' => $li['sku'] ?? null,
                                'name' => $li['name'] ?? $li['title'] ?? null,
                            ];
                        }

                        return $items;
                    }

                    if (! empty($orderData['products'])) {
                        $items = [];
                        foreach ($orderData['products'] as $p) {
                            $items[] = [
                                'external_product_id' => (string) ($p['product_id'] ?? $p['id'] ?? ''),
                                'external_variant_id' => (string) ($p['variant_id'] ?? '0'),
                                'quantity' => (float) ($p['quantity'] ?? 1),
                                'sku' => $p['sku'] ?? null,
                                'name' => $p['name'] ?? $p['title'] ?? null,
                            ];
                        }

                        return $items;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Could not fetch remote order {$externalOrderId}: ".$e->getMessage());
            }
        }

        return [];
    }
}
