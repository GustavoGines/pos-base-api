<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

class TiendaNubeAdapter extends AbstractEcommerceAdapter
{
    public const PLATFORM_NAME = 'tiendanube';

    public const API_BASE_URL = 'https://api.tiendanube.com/v1';

    /**
     * Get the canonical platform identifier.
     */
    public function getPlatformName(): string
    {
        return self::PLATFORM_NAME;
    }

    /**
     * Build HTTP client with required TiendaNube headers.
     */
    protected function http(): PendingRequest
    {
        $token = (string) ($this->getAccessToken() ?? '');

        return parent::http()
            ->withHeaders([
                'Authentication' => 'bearer '.$token,
                'User-Agent' => 'Sistema POS (soporte@sistemapos.com)',
            ]);
    }

    /**
     * Test API connectivity and credentials validity against Tienda Nube.
     *
     * @return array{success: bool, message: string, store_info?: array, error?: string}
     */
    public function testConnection(): array
    {
        $token = $this->getAccessToken();
        $storeId = $this->getStoreId();

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado el Access Token de Tienda Nube',
                'error' => 'MISSING_ACCESS_TOKEN',
            ];
        }

        if (empty($storeId)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado el Store ID de Tienda Nube',
                'error' => 'MISSING_STORE_ID',
            ];
        }

        try {
            $response = $this->http()->get(self::API_BASE_URL."/{$storeId}/store");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con Tienda Nube',
                    'store_info' => [
                        'id' => $data['id'] ?? $storeId,
                        'name' => is_array($data['name'] ?? null) ? ($data['name']['es'] ?? reset($data['name'])) : ($data['name'] ?? ''),
                        'url' => $data['url_with_protocol'] ?? ($data['url'] ?? null),
                        'email' => $data['email'] ?? null,
                        'currency' => $data['currency'] ?? 'ARS',
                    ],
                ];
            }

            $errorMsg = $response->json('description') ?? $response->json('message') ?? 'Error de autenticación con Tienda Nube (HTTP '.$response->status().')';

            return [
                'success' => false,
                'message' => (string) $errorMsg,
                'error' => 'HTTP_'.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión con Tienda Nube: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update stock level for a Tienda Nube product or variant.
     */
    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        $storeId = $this->getStoreId();
        if (empty($storeId) || empty($this->getAccessToken())) {
            return false;
        }

        try {
            $qty = (int) floor($quantity);

            $isVariant = $externalVariantId !== null && $externalVariantId !== '' && $externalVariantId !== '0';
            if ($isVariant) {
                $url = self::API_BASE_URL."/{$storeId}/products/{$externalProductId}/variants/{$externalVariantId}";
                $payload = ['stock' => $qty];
            } else {
                $url = self::API_BASE_URL."/{$storeId}/products/{$externalProductId}";
                $payload = [
                    'variants' => [
                        ['stock' => $qty],
                    ],
                ];
            }

            $response = $this->http()->put($url, $payload);

            // Handle Leaky-Bucket 429 rate limit with Retry-After
            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 1);
                if ($retryAfter > 0 && $retryAfter <= 3) {
                    sleep($retryAfter);
                    $response = $this->http()->put($url, $payload);
                }
            }

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
        $storeId = $this->getStoreId();
        if (empty($storeId) || empty($this->getAccessToken())) {
            return [];
        }

        try {
            $response = $this->http()->get(self::API_BASE_URL."/{$storeId}/orders/{$externalOrderId}");

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Cancel an external order on Tienda Nube.
     */
    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        $storeId = $this->getStoreId();
        if (empty($storeId) || empty($this->getAccessToken())) {
            return false;
        }

        try {
            $response = $this->http()->post(self::API_BASE_URL."/{$storeId}/orders/{$externalOrderId}/close", [
                'reason' => $reason ?? 'Cancelled via POS',
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify the authenticity of an incoming Tienda Nube webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->getWebhookSecret() ?: $this->getClientSecret();
        if (! empty($secret)) {
            $signatureHeader = (string) ($request->header('x-linkedstore-hmac-sha256') ?? $request->header('X-LinkedStore-HMAC-SHA256', ''));

            if ($signatureHeader === '') {
                return false;
            }

            $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

            return hash_equals($expected, $signatureHeader);
        }

        // Structural fallback (only when no secret is configured)
        return $request->has('event') && $request->has('id');
    }

    /**
     * Parse and normalize an incoming Tienda Nube webhook.
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
        $event = (string) $request->input('event', 'order/created');
        $id = (string) $request->input('id', '');

        $orderId = null;
        if (str_starts_with($event, 'order/')) {
            $orderId = $id;
        }

        return [
            'event_type' => $event,
            'external_event_id' => $id,
            'external_order_id' => $orderId,
            'action' => $event,
            'payload' => $request->all(),
        ];
    }
}
