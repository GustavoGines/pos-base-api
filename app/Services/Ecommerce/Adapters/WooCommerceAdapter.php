<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

class WooCommerceAdapter extends AbstractEcommerceAdapter
{
    public const PLATFORM_NAME = 'woocommerce';

    /**
     * Get the canonical platform identifier.
     */
    public function getPlatformName(): string
    {
        return self::PLATFORM_NAME;
    }

    /**
     * Build HTTP client pre-configured with Basic Auth for WooCommerce API.
     */
    protected function http(): PendingRequest
    {
        $ck = (string) ($this->getClientId() ?? '');
        $cs = (string) ($this->getClientSecret() ?? '');

        return parent::http()->withBasicAuth($ck, $cs);
    }

    /**
     * Resolve the normalized WooCommerce REST API endpoint.
     */
    protected function endpoint(string $path): string
    {
        $base = $this->getBaseUrl() ?? '';
        $path = ltrim($path, '/');

        return "{$base}/wp-json/wc/v3/{$path}";
    }

    /**
     * Test API connectivity and credentials validity against WooCommerce.
     *
     * @return array{success: bool, message: string, store_info?: array, error?: string}
     */
    public function testConnection(): array
    {
        $baseUrl = $this->getBaseUrl();
        $ck = $this->getClientId();
        $cs = $this->getClientSecret();

        if (empty($baseUrl)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado la URL base de WooCommerce',
                'error' => 'MISSING_BASE_URL',
            ];
        }

        if (empty($ck) || empty($cs)) {
            return [
                'success' => false,
                'message' => 'No se han configurado el Consumer Key o Consumer Secret de WooCommerce',
                'error' => 'MISSING_CREDENTIALS',
            ];
        }

        try {
            // First attempt system_status, fallback to products index
            $response = $this->http()->get($this->endpoint('system_status'));

            if (! $response->successful()) {
                $response = $this->http()->get($this->endpoint('products?per_page=1'));
            }

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con WooCommerce',
                    'store_info' => [
                        'url' => $baseUrl,
                        'environment' => $data['environment'] ?? [],
                        'currency' => $data['settings']['currency'] ?? 'ARS',
                    ],
                ];
            }

            $errorMsg = $response->json('message') ?? 'Error de autenticación con WooCommerce (HTTP '.$response->status().')';

            return [
                'success' => false,
                'message' => (string) $errorMsg,
                'error' => 'HTTP_'.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión con WooCommerce: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update stock level for a WooCommerce product or variation.
     */
    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        if (empty($this->getBaseUrl()) || empty($this->getClientId())) {
            return false;
        }

        try {
            $qty = (int) $quantity;
            $payload = [
                'manage_stock' => true,
                'stock_quantity' => $qty,
            ];

            if ($externalVariantId !== null && $externalVariantId !== '' && $externalVariantId !== '0') {
                $url = $this->endpoint("products/{$externalProductId}/variations/{$externalVariantId}");
            } else {
                $url = $this->endpoint("products/{$externalProductId}");
            }

            $response = $this->http()->put($url, $payload);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Batch update stock using native WooCommerce batch endpoint when possible.
     *
     * @param  array<int, array{external_product_id: string, quantity: float, external_variant_id?: string|null}>  $items
     * @return array{success: bool, updated_count: int, failed_items: array}
     */
    public function batchUpdateStock(array $items): array
    {
        if (empty($this->getBaseUrl()) || empty($this->getClientId())) {
            return ['success' => false, 'updated_count' => 0, 'failed_items' => $items];
        }

        // Check if any items are variations (which require variation endpoint in WC)
        $hasVariations = false;
        foreach ($items as $item) {
            if (! empty($item['external_variant_id']) && $item['external_variant_id'] !== '0') {
                $hasVariations = true;
                break;
            }
        }

        // If simple products, use native batch endpoint
        if (! $hasVariations) {
            try {
                $updatePayload = [];
                foreach ($items as $item) {
                    $updatePayload[] = [
                        'id' => (int) $item['external_product_id'],
                        'manage_stock' => true,
                        'stock_quantity' => (int) $item['quantity'],
                    ];
                }

                $response = $this->http()->post($this->endpoint('products/batch'), [
                    'update' => $updatePayload,
                ]);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'updated_count' => count($items),
                        'failed_items' => [],
                    ];
                }
            } catch (\Throwable) {
                // Fall back to sequential update
            }
        }

        return parent::batchUpdateStock($items);
    }

    /**
     * Fetch order details by external order ID.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $externalOrderId): array
    {
        if (empty($this->getBaseUrl()) || empty($this->getClientId())) {
            return [];
        }

        try {
            $response = $this->http()->get($this->endpoint("orders/{$externalOrderId}"));

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Cancel an external order on WooCommerce.
     */
    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        if (empty($this->getBaseUrl()) || empty($this->getClientId())) {
            return false;
        }

        try {
            $response = $this->http()->put($this->endpoint("orders/{$externalOrderId}"), [
                'status' => 'cancelled',
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify the authenticity of an incoming WooCommerce webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->getWebhookSecret();
        if (! empty($secret)) {
            $signatureHeader = (string) ($request->header('x-wc-webhook-signature') ?? $request->header('X-WC-Webhook-Signature', ''));

            if ($signatureHeader === '') {
                return false;
            }

            $expected = base64_encode(hash_hmac('sha256', (string) $request->getContent(), $secret, true));

            return hash_equals($expected, $signatureHeader);
        }

        // Structural fallback (only when no secret is configured)
        return $request->hasHeader('x-wc-webhook-topic') || $request->hasHeader('x-wc-webhook-resource');
    }

    /**
     * Parse and normalize an incoming WooCommerce webhook.
     *
     * @return array{
     *     event_type: string,
     *     external_event_id: string,
     *     external_order_id: string|null,
     *     action: string,
     *     payload: array
     * }
     */
    public function parseWebhook(Request $request): array
    {
        $topic = (string) ($request->header('x-wc-webhook-topic') ?? $request->input('topic', 'order.updated'));
        $eventId = (string) ($request->header('x-wc-webhook-id') ?? $request->input('id', ''));
        $orderId = (string) ($request->input('id', ''));

        return [
            'event_type' => $topic,
            'external_event_id' => $eventId !== '' ? $eventId : $orderId,
            'external_order_id' => $orderId !== '' ? $orderId : null,
            'action' => $topic,
            'payload' => $request->all(),
        ];
    }
}
