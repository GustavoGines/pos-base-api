<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

class InternalEcommercePlatformAdapter extends AbstractEcommerceAdapter
{
    public const PLATFORM_NAME = 'ecommerce_platform';

    /**
     * Get the canonical platform identifier.
     */
    public function getPlatformName(): string
    {
        return self::PLATFORM_NAME;
    }

    /**
     * Build HTTP client pre-configured for Internal Ecommerce Platform API.
     */
    protected function http(): PendingRequest
    {
        $token = (string) ($this->getAccessToken() ?? '');
        $tenantId = (string) ($this->getStoreId() ?? '');

        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        if ($tenantId !== '') {
            $headers['X-Tenant-ID'] = $tenantId;
        }

        return parent::http()->withHeaders($headers);
    }

    /**
     * Resolve the normalized internal API endpoint.
     */
    protected function endpoint(string $path): string
    {
        $base = $this->getBaseUrl() ?? '';
        $path = ltrim($path, '/');

        return "{$base}/api/v1/{$path}";
    }

    /**
     * Test API connectivity and credentials validity against Internal Platform.
     *
     * @return array{success: bool, message: string, store_info?: array, error?: string}
     */
    public function testConnection(): array
    {
        $baseUrl = $this->getBaseUrl();
        $token = $this->getAccessToken();

        if (empty($baseUrl)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado la URL base de Ecommerce Platform',
                'error' => 'MISSING_BASE_URL',
            ];
        }

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado el API Token de Ecommerce Platform',
                'error' => 'MISSING_ACCESS_TOKEN',
            ];
        }

        try {
            $response = $this->http()->get($this->endpoint('health'));

            if (! $response->successful()) {
                $response = $this->http()->get($this->endpoint('store/status'));
            }

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con Ecommerce Platform',
                    'store_info' => [
                        'tenant_id' => $this->getStoreId(),
                        'url' => $baseUrl,
                        'status' => $data['status'] ?? 'ok',
                        'version' => $data['version'] ?? '1.0',
                    ],
                ];
            }

            $errorMsg = $response->json('message') ?? 'Error de conexión con Ecommerce Platform (HTTP '.$response->status().')';

            return [
                'success' => false,
                'message' => (string) $errorMsg,
                'error' => 'HTTP_'.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión con Ecommerce Platform: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update stock level for a product or variant on the internal platform.
     */
    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        if (empty($this->getBaseUrl()) || empty($this->getAccessToken())) {
            return false;
        }

        try {
            $response = $this->http()->post($this->endpoint('inventory/update'), [
                'product_id' => $externalProductId,
                'variant_id' => $externalVariantId,
                'quantity' => $quantity,
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fetch order details by external order ID.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $externalOrderId): array
    {
        if (empty($this->getBaseUrl()) || empty($this->getAccessToken())) {
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
     * Cancel an external order on the internal platform.
     */
    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        if (empty($this->getBaseUrl()) || empty($this->getAccessToken())) {
            return false;
        }

        try {
            $response = $this->http()->post($this->endpoint("orders/{$externalOrderId}/cancel"), [
                'reason' => $reason ?? 'Cancelled via POS',
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify the authenticity of an incoming webhook request from the internal platform.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->getWebhookSecret();
        if (! empty($secret)) {
            $signatureHeader = (string) ($request->header('x-signature') ?? $request->header('X-Signature', ''));

            if ($signatureHeader === '') {
                return false;
            }

            $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

            return hash_equals($expected, $signatureHeader);
        }

        // Structural fallback (only when no secret is configured)
        return $request->has('event') || $request->has('event_type');
    }

    /**
     * Parse and normalize an incoming internal platform webhook.
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
        $eventType = (string) ($request->input('event_type') ?? $request->input('event', 'order.updated'));
        $eventId = (string) ($request->input('event_id') ?? $request->input('id', ''));
        $orderId = (string) ($request->input('order_id') ?? $request->input('external_order_id', ''));

        return [
            'event_type' => $eventType,
            'external_event_id' => $eventId !== '' ? $eventId : $orderId,
            'external_order_id' => $orderId !== '' ? $orderId : null,
            'action' => $eventType,
            'payload' => $request->all(),
        ];
    }

    /**
     * Fetch the complete catalog from the internal ecommerce platform.
     *
     * @return array{success: bool, message: string, products: array, total: int}
     */
    public function fetchCatalog(): array
    {
        if (empty($this->getBaseUrl()) || empty($this->getAccessToken())) {
            return [
                'success' => false,
                'message' => 'No se ha configurado la URL o el Token de la plataforma web',
                'products' => [],
                'total' => 0,
            ];
        }

        try {
            $response = $this->http()->get($this->endpoint('catalog/export'));

            if ($response->successful()) {
                $data = $response->json();
                $products = $data['products'] ?? [];

                return [
                    'success' => true,
                    'message' => 'Catálogo obtenido exitosamente',
                    'products' => $products,
                    'total' => (int) ($data['total'] ?? count($products)),
                ];
            }

            return [
                'success' => false,
                'message' => (string) ($response->json('message') ?? 'Error al obtener catálogo web (HTTP '.$response->status().')'),
                'products' => [],
                'total' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al conectar con la plataforma web: '.$e->getMessage(),
                'products' => [],
                'total' => 0,
            ];
        }
    }

    /**
     * Bulk synchronize products from the POS to the internal ecommerce platform.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array{success: bool, message: string, synced_count: int, data?: array}
     */
    public function bulkSyncCatalog(array $products): array
    {
        if (empty($this->getBaseUrl()) || empty($this->getAccessToken())) {
            return [
                'success' => false,
                'message' => 'No se ha configurado la URL o el Token de la plataforma web',
                'synced_count' => 0,
            ];
        }

        try {
            $response = $this->http()->post($this->endpoint('catalog/bulk-sync'), [
                'products' => $products,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => (string) ($data['message'] ?? 'Catálogo sembrado exitosamente en la tienda web'),
                    'synced_count' => (int) ($data['synced_count'] ?? count($products)),
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => (string) ($response->json('message') ?? 'Error al sincronizar catálogo con la tienda web (HTTP '.$response->status().')'),
                'synced_count' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al enviar catálogo a la tienda web: '.$e->getMessage(),
                'synced_count' => 0,
            ];
        }
    }
}
