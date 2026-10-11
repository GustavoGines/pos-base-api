<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Adapters;

use App\Models\EcommerceIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MercadoLibreAdapter extends AbstractEcommerceAdapter
{
    public const PLATFORM_NAME = 'mercadolibre';

    public const API_BASE_URL = 'https://api.mercadolibre.com';

    /**
     * Get the canonical platform identifier.
     */
    public function getPlatformName(): string
    {
        return self::PLATFORM_NAME;
    }

    /**
     * Proactively refresh OAuth2 token if close to expiration (< 10 minutes) with distributed lock.
     */
    public function refreshTokenIfNeeded(?EcommerceIntegration $integration = null): void
    {
        $integration ??= $this->integration;
        if (! $integration || ! $integration->refresh_token) {
            return;
        }

        // Si faltan más de 10 minutos para expirar, el token aún es válido
        if ($integration->token_expires_at && $integration->token_expires_at->isAfter(now()->addMinutes(10))) {
            return;
        }

        Cache::lock("oauth_refresh:{$integration->id}", 20)->block(10, function () use ($integration) {
            $fresh = $integration->fresh();
            if (! $fresh || ! $fresh->refresh_token) {
                return;
            }

            if ($fresh->token_expires_at && $fresh->token_expires_at->isAfter(now()->addMinutes(10))) {
                return;
            }

            $response = $this->http()->asForm()->post(self::API_BASE_URL.'/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'refresh_token' => $fresh->decrypted_refresh_token ?? $fresh->refresh_token,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $fresh->update([
                    'access_token' => $data['access_token'] ?? $fresh->access_token,
                    'refresh_token' => $data['refresh_token'] ?? $fresh->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 21600)),
                ]);
            }
        });
    }

    /**
     * Test API connectivity and credentials validity against Mercado Libre.
     *
     * @return array{success: bool, message: string, store_info?: array, error?: string}
     */
    public function testConnection(): array
    {
        $this->refreshTokenIfNeeded();

        $token = $this->getAccessToken();

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'No se ha configurado el Access Token de Mercado Libre',
                'error' => 'MISSING_ACCESS_TOKEN',
            ];
        }

        try {
            $response = $this->http()
                ->withToken($token)
                ->get(self::API_BASE_URL.'/users/me');

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con Mercado Libre',
                    'store_info' => [
                        'id' => $data['id'] ?? null,
                        'nickname' => $data['nickname'] ?? '',
                        'site_id' => $data['site_id'] ?? 'MLA',
                        'email' => $data['email'] ?? null,
                    ],
                ];
            }

            $errorMsg = $response->json('message') ?? 'Error de autenticación con Mercado Libre (HTTP '.$response->status().')';

            return [
                'success' => false,
                'message' => (string) $errorMsg,
                'error' => 'HTTP_'.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión con Mercado Libre: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update stock level for a Mercado Libre item or variation.
     */
    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        $this->refreshTokenIfNeeded();

        $token = $this->getAccessToken();
        if (empty($token)) {
            return false;
        }

        try {
            $qty = (int) floor($quantity);
            $client = $this->http()->withToken($token);

            if ($externalVariantId !== null && $externalVariantId !== '' && $externalVariantId !== '0') {
                $url = self::API_BASE_URL."/items/{$externalProductId}/variations/{$externalVariantId}";
                $response = $client->put($url, ['available_quantity' => $qty]);

                if ($response->successful()) {
                    if ($qty > 0) {
                        $client->put(self::API_BASE_URL."/items/{$externalProductId}", [
                            'status' => 'active',
                        ]);
                    } elseif ($qty === 0 && $this->isAutoPauseOnZeroStock()) {
                        $client->put(self::API_BASE_URL."/items/{$externalProductId}", [
                            'status' => 'paused',
                            'available_quantity' => 0,
                        ]);
                    }

                    return true;
                }

                return false;
            }

            $url = self::API_BASE_URL."/items/{$externalProductId}";
            $payload = $qty > 0
                ? ['status' => 'active', 'available_quantity' => $qty]
                : ['status' => 'paused', 'available_quantity' => 0];

            $response = $client->put($url, $payload);

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
        $token = $this->getAccessToken();
        if (empty($token)) {
            return [];
        }

        try {
            $response = $this->http()
                ->withToken($token)
                ->get(self::API_BASE_URL."/orders/{$externalOrderId}");

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Cancel an external order on Mercado Libre.
     */
    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        $token = $this->getAccessToken();
        if (empty($token)) {
            return false;
        }

        try {
            $response = $this->http()
                ->withToken($token)
                ->post(self::API_BASE_URL."/orders/{$externalOrderId}/feedback", [
                    'fulfilled' => false,
                    'rating' => 'neutral',
                    'message' => $reason ?? 'Cancelled via POS',
                ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify the authenticity of an incoming Mercado Libre webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->getWebhookSecret();
        if (! empty($secret)) {
            $signatureHeader = (string) $request->header('x-signature', '');
            $requestId = (string) $request->header('x-request-id', '');

            if ($signatureHeader === '' || $requestId === '') {
                return false;
            }

            $parts = [];
            foreach (explode(',', $signatureHeader) as $part) {
                $segments = explode('=', trim($part), 2);
                if (count($segments) === 2) {
                    $parts[$segments[0]] = $segments[1];
                }
            }

            if (! isset($parts['ts'], $parts['v1'])) {
                return false;
            }

            $manifest = "id:{$requestId};request-id:{$requestId};ts:{$parts['ts']};";
            $expected = hash_hmac('sha256', $manifest, $secret);

            return hash_equals($expected, $parts['v1']);
        }

        // Structural validation fallback (only when no secret is configured)
        return $request->has('resource') && $request->has('topic');
    }

    /**
     * Parse and normalize an incoming Mercado Libre webhook.
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
        $resource = (string) $request->input('resource', '');
        $externalOrderId = null;

        if (preg_match('#/orders/(\d+)#', $resource, $matches)) {
            $externalOrderId = $matches[1];
        }

        return [
            'event_type' => (string) $request->input('topic', 'order'),
            'external_event_id' => (string) ($request->input('id', $request->input('_id', ''))),
            'external_order_id' => $externalOrderId,
            'action' => (string) $request->input('action', 'updated'),
            'payload' => $request->all(),
        ];
    }
}
