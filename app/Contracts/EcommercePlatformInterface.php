<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\Request;

interface EcommercePlatformInterface
{
    /**
     * Get the unique canonical platform identifier.
     *
     * @return string e.g. 'mercadolibre', 'tiendanube', 'woocommerce', 'ecommerce_platform'
     */
    public function getPlatformName(): string;

    /**
     * Test API connectivity and credentials validity against the external platform.
     *
     * @return array{success: bool, message: string, store_info?: array, error?: string}
     */
    public function testConnection(): array;

    /**
     * Update stock level for a single remote product or variant.
     *
     * @param  string  $externalProductId  Remote product identifier
     * @param  float  $quantity  New stock quantity available
     * @param  string|null  $externalVariantId  Remote variant identifier if applicable
     * @return bool True if the platform acknowledged the update, false otherwise
     */
    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool;

    /**
     * Update stock levels in batch to respect rate limits and reduce HTTP overhead.
     *
     * @param  array<int, array{external_product_id: string, quantity: float, external_variant_id?: string|null}>  $items
     * @return array{success: bool, updated_count: int, failed_items: array}
     */
    public function batchUpdateStock(array $items): array;

    /**
     * Fetch order details by external order ID.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $externalOrderId): array;

    /**
     * Cancel an external order on the remote platform.
     *
     * @param  string|null  $reason  Optional cancellation reason
     */
    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool;

    /**
     * Verify the authenticity and cryptographic signature of an incoming webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse and normalize an incoming webhook request into a unified internal format.
     *
     * @return array{
     *     event_type: string,
     *     external_event_id: string,
     *     external_order_id: string|null,
     *     action: string,
     *     payload: array
     * }
     */
    public function parseWebhook(Request $request): array;
}
