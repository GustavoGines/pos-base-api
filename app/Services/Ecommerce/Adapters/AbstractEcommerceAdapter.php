<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Adapters;

use App\Contracts\EcommercePlatformInterface;
use App\Models\EcommerceIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

abstract class AbstractEcommerceAdapter implements EcommercePlatformInterface
{
    /**
     * @param  EcommerceIntegration|null  $integration  Persisted integration model
     * @param  array<string, mixed>  $config  Ad-hoc credentials/options
     */
    public function __construct(
        protected ?EcommerceIntegration $integration = null,
        protected array $config = []
    ) {}

    /**
     * Get the attached integration model if any.
     */
    public function getIntegration(): ?EcommerceIntegration
    {
        return $this->integration;
    }

    /**
     * Resolve the client identifier (App ID, Consumer Key, etc.).
     */
    public function getClientId(): ?string
    {
        return $this->integration?->client_id ?? $this->config['client_id'] ?? null;
    }

    /**
     * Resolve the decrypted client secret.
     */
    public function getClientSecret(): ?string
    {
        return $this->integration?->decrypted_client_secret
            ?? $this->integration?->client_secret
            ?? $this->config['client_secret']
            ?? null;
    }

    /**
     * Resolve the decrypted access token.
     */
    public function getAccessToken(): ?string
    {
        return $this->integration?->decrypted_access_token
            ?? $this->integration?->access_token
            ?? $this->config['access_token']
            ?? null;
    }

    /**
     * Resolve the decrypted refresh token.
     */
    public function getRefreshToken(): ?string
    {
        return $this->integration?->decrypted_refresh_token
            ?? $this->integration?->refresh_token
            ?? $this->config['refresh_token']
            ?? null;
    }

    /**
     * Resolve the remote store identifier.
     */
    public function getStoreId(): ?string
    {
        return $this->integration?->store_id ?? $this->config['store_id'] ?? null;
    }

    /**
     * Resolve the normalized base URL.
     */
    public function getBaseUrl(): ?string
    {
        $url = $this->integration?->base_url ?? $this->config['base_url'] ?? null;

        return $url ? rtrim((string) $url, '/') : null;
    }

    /**
     * Resolve the decrypted webhook secret.
     */
    public function getWebhookSecret(): ?string
    {
        return $this->integration?->decrypted_webhook_secret
            ?? $this->integration?->webhook_secret
            ?? $this->config['webhook_secret']
            ?? null;
    }

    /**
     * Resolve the HTTP request timeout in seconds.
     */
    public function getTimeout(): int
    {
        return (int) ($this->integration?->timeout_seconds ?? $this->config['timeout_seconds'] ?? 30);
    }

    /**
     * Check if auto-pause on zero stock is enabled.
     */
    public function isAutoPauseOnZeroStock(): bool
    {
        return (bool) ($this->integration?->auto_pause_on_zero_stock ?? $this->config['auto_pause_on_zero_stock'] ?? true);
    }

    /**
     * Instantiate pre-configured HTTP client for outbound requests.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout($this->getTimeout())
            ->withHeaders([
                'User-Agent' => 'SistemaPOS-EcommerceSync/1.0',
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Sequential fallback implementation of batch stock update.
     *
     * @param  array<int, array{external_product_id: string, quantity: float, external_variant_id?: string|null}>  $items
     * @return array{success: bool, updated_count: int, failed_items: array}
     */
    public function batchUpdateStock(array $items): array
    {
        $updatedCount = 0;
        $failedItems = [];

        foreach ($items as $item) {
            $extId = (string) ($item['external_product_id'] ?? '');
            $qty = (float) ($item['quantity'] ?? 0);
            $varId = isset($item['external_variant_id']) ? (string) $item['external_variant_id'] : null;

            if ($extId !== '' && $this->updateStock($extId, $qty, $varId)) {
                $updatedCount++;
            } else {
                $failedItems[] = $item;
            }
        }

        return [
            'success' => empty($failedItems),
            'updated_count' => $updatedCount,
            'failed_items' => $failedItems,
        ];
    }
}
