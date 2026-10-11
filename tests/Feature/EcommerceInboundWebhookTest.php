<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceSyncLog;
use App\Models\EcommerceWebhook;
use App\Models\Product;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EcommerceInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function createIntegration(string $platform, array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => $platform,
            'name' => 'Canal Inbound '.$platform,
            'client_id' => 'CLIENT_123',
            'client_secret' => 'SECRET_XYZ',
            'access_token' => 'TOKEN_ABC',
            'refresh_token' => 'REFRESH_123',
            'token_expires_at' => now()->addHours(6),
            'base_url' => 'https://mystore.test',
            'store_id' => '123456',
            'webhook_secret' => null,
            'is_active' => true,
            'sync_stock_enabled' => true,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONTROLLER & HTTP SIGNATURE / REPLAY TESTS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mercadolibre_webhook_accepts_valid_payload_and_dispatches_inbound_job(): void
    {
        Queue::fake();

        $integration = $this->createIntegration('mercadolibre');

        $payload = [
            'id' => 'EVT_MELI_001',
            'topic' => 'orders_v2',
            'resource' => '/orders/2000001234',
            'user_id' => 123456,
            'application_id' => 987654,
            'sent' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('ecommerce_webhooks', [
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'external_event_id' => 'EVT_MELI_001',
            'event_type' => 'orders_v2',
            'status' => 'received',
        ]);

        Queue::assertPushed(ProcessInboundEcommerceWebhookJob::class, function ($job) {
            return $job->queue === 'ecommerce-inbound';
        });
    }

    public function test_anti_replay_rejects_payload_with_timestamp_older_than_300s(): void
    {
        $this->createIntegration('mercadolibre');

        $payload = [
            'id' => 'EVT_MELI_OLD',
            'topic' => 'orders_v2',
            'resource' => '/orders/2000001234',
            'sent_at' => now()->subMinutes(10)->timestamp, // 600s old!
        ];

        $response = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);
    }

    public function test_inbound_webhook_handles_duplicate_event_id_idempotently(): void
    {
        Queue::fake();

        $this->createIntegration('mercadolibre');

        $payload = [
            'id' => 'EVT_MELI_DUP',
            'topic' => 'orders_v2',
            'resource' => '/orders/2000001234',
        ];

        // First call
        $res1 = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);
        $res1->assertStatus(200);

        // Duplicate call with same external event id
        $res2 = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'ignored');
        $res2->assertJsonPath('reason', 'duplicate_idempotent');

        // Only one record in database
        $this->assertEquals(1, EcommerceWebhook::where('external_event_id', 'EVT_MELI_DUP')->count());
    }

    public function test_tiendanube_validates_hmac_signature_correctly(): void
    {
        Queue::fake();

        $secret = 'whsec_tn_secret';
        $integration = $this->createIntegration('tiendanube', [
            'webhook_secret' => $secret,
        ]);

        $payload = [
            'id' => 'TN_EVT_1',
            'event' => 'order/created',
        ];
        $body = json_encode($payload);
        $validSignature = hash_hmac('sha256', $body, $secret);

        // 1. Invalid signature should return 401
        $resInvalid = $this->withHeaders([
            'X-LinkedStore-HMAC-SHA256' => 'invalid_hash_signature',
        ])->postJson('/api/webhooks/ecommerce/tiendanube', $payload);

        $resInvalid->assertStatus(401);

        // 2. Valid signature should return 200
        $resValid = $this->withHeaders([
            'X-LinkedStore-HMAC-SHA256' => $validSignature,
        ])->call(
            'POST',
            '/api/webhooks/ecommerce/tiendanube',
            [],
            [],
            [],
            [
                'HTTP_X_LINKEDSTORE_HMAC_SHA256' => $validSignature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );

        $resValid->assertStatus(200);
    }

    public function test_woocommerce_validates_base64_hmac_signature_correctly(): void
    {
        Queue::fake();

        $secret = 'wc_whsec_secret_pass';
        $integration = $this->createIntegration('woocommerce', [
            'webhook_secret' => $secret,
        ]);

        $payload = [
            'id' => 888,
            'topic' => 'order.created',
        ];
        $body = json_encode($payload);
        $validSig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        // Invalid signature returns 401
        $resInvalid = $this->withHeaders([
            'X-WC-Webhook-Signature' => 'bad_sig',
        ])->postJson('/api/webhooks/ecommerce/woocommerce', $payload);

        $resInvalid->assertStatus(401);

        // Valid signature returns 200
        $resValid = $this->call(
            'POST',
            '/api/webhooks/ecommerce/woocommerce',
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => $validSig,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );

        $resValid->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WORKER: PROCESS INBOUND WEBHOOK (DEDUCCIÓN, CONCURRENCIA, FAN-OUT)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inbound_job_deducts_stock_atomically_and_propagates_to_other_channels(): void
    {
        Queue::fake([SyncProductStockToPlatformsJob::class]);

        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Martillo Galponero',
            'internal_code' => 'MAR-01',
            'cost_price' => 500,
            'selling_price' => 1200,
            'stock' => 10,
            'active' => true,
        ]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA99999',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_TEST_DEDUCT_1',
            'payload' => [
                'id' => 'ORD-12345',
                'items' => [
                    ['external_product_id' => 'MLA99999', 'quantity' => 3],
                ],
            ],
            'status' => 'received',
        ]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // 1. Stock deducted: 10 - 3 = 7
        $product->refresh();
        $this->assertEquals('7.000', $product->stock);

        // 2. Stock movement created with type = sale_ecommerce
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'sale_ecommerce',
            'quantity' => '3.000',
        ]);

        // 3. Sync log created with action = order_deduct and status = success
        $this->assertDatabaseHas('ecommerce_sync_logs', [
            'integration_id' => $integration->id,
            'mapping_id' => $mapping->id,
            'external_order_id' => 'ORD-12345',
            'direction' => 'inbound',
            'action' => 'order_deduct',
            'status' => 'success',
        ]);

        // 4. Webhook status marked processed
        $webhook->refresh();
        $this->assertEquals('processed', $webhook->status);

        // 5. Cross-platform fan-out dispatched excluding origin integration id
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($product, $integration) {
            return $job->productId === $product->id
                && (float) $job->newStock === 7.0
                && $job->excludedIntegrationId === $integration->id;
        });
    }

    public function test_inbound_job_order_level_idempotency_prevents_double_deduction(): void
    {
        $integration = $this->createIntegration('woocommerce');

        $product = Product::create([
            'name' => 'Cinta Métrica 5m',
            'internal_code' => 'CIN-01',
            'cost_price' => 300,
            'selling_price' => 700,
            'stock' => 10,
            'active' => true,
        ]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'woocommerce',
            'external_product_id' => 'WC_PROD_1',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Pre-existing order deduction log in database
        EcommerceSyncLog::create([
            'integration_id' => $integration->id,
            'mapping_id' => $mapping->id,
            'external_order_id' => 'ORDER_ID_EXISTING_777',
            'direction' => 'inbound',
            'action' => 'order_deduct',
            'stock_before' => 12,
            'stock_after' => 10,
            'status' => 'success',
        ]);

        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'woocommerce',
            'event_type' => 'order.updated',
            'external_event_id' => 'EVT_WC_REPEAT',
            'payload' => [
                'id' => 'ORDER_ID_EXISTING_777',
                'line_items' => [
                    ['product_id' => 'WC_PROD_1', 'quantity' => 2],
                ],
            ],
            'status' => 'received',
        ]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // Stock should REMAIN 10 (no double deduction)
        $product->refresh();
        $this->assertEquals('10.000', $product->stock);

        $webhook->refresh();
        $this->assertEquals('processed', $webhook->status);
    }

    public function test_inbound_job_flags_stock_conflict_review_if_stock_drops_below_zero(): void
    {
        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Última Unidad Crítica',
            'internal_code' => 'ULT-01',
            'cost_price' => 1000,
            'selling_price' => 2000,
            'stock' => 1, // Only 1 left
            'active' => true,
        ]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_CRITICAL',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Order buys 2 units when only 1 is in stock (overselling simultaneous scenario)
        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_OVERSELL_1',
            'payload' => [
                'id' => 'ORD-OVERSELL-99',
                'items' => [
                    ['external_product_id' => 'MLA_CRITICAL', 'quantity' => 2],
                ],
            ],
            'status' => 'received',
        ]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // Stock becomes negative: 1 - 2 = -1
        $product->refresh();
        $this->assertEquals('-1.000', $product->stock);

        // Status must be stock_conflict_review
        $this->assertDatabaseHas('ecommerce_sync_logs', [
            'integration_id' => $integration->id,
            'external_order_id' => 'ORD-OVERSELL-99',
            'direction' => 'inbound',
            'action' => 'order_deduct',
            'status' => 'stock_conflict_review',
        ]);
    }
}
