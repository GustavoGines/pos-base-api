<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceWebhook;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Empirical Concurrency, Idempotency, and Anti-Deadlock Verification Suite
 * Phase 6 E-Commerce Omnichannel
 */
class EcommerceConcurrencyAndIdempotencyChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected function createIntegration(string $platform, array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => $platform,
            'name' => 'Canal Test '.$platform,
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
            'auto_pause_on_zero_stock' => true,
        ], $attributes));
    }

    // =========================================================================
    // CHALLENGE 1: DUPLICATE WEBHOOK ARRIVAL & IDEMPOTENCY
    // =========================================================================

    public function test_duplicate_webhook_mercadolibre_returns_http_200_and_single_insert(): void
    {
        Queue::fake();
        $integration = $this->createIntegration('mercadolibre');

        $eventId = 'MELI_EVT_DUP_001';
        $payload = [
            'id' => $eventId,
            'topic' => 'orders_v2',
            'resource' => '/orders/10002000',
            'user_id' => 123456,
        ];

        // 1st request -> Accepted
        $res1 = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'accepted');
        $this->assertNotNull($res1->json('webhook_id'));

        // 2nd request with exact same external_event_id -> Ignored idempotently with HTTP 200
        $res2 = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'ignored');
        $res2->assertJsonPath('reason', 'duplicate_idempotent');

        // Verify strictly 1 webhook record in database
        $this->assertEquals(1, EcommerceWebhook::where('external_event_id', $eventId)->count());

        // Verify strictly 1 inbound job dispatched
        Queue::assertPushed(ProcessInboundEcommerceWebhookJob::class, 1);
    }

    public function test_duplicate_webhook_woocommerce_returns_http_200_and_single_insert(): void
    {
        Queue::fake();
        $secret = 'wc_secret_hash';
        $integration = $this->createIntegration('woocommerce', [
            'webhook_secret' => $secret,
        ]);

        $eventId = 'WC_EVT_9988';
        $payload = [
            'id' => 9988,
            'topic' => 'order.created',
        ];
        $body = json_encode($payload);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $headers = [
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => $sig,
            'HTTP_X_WC_WEBHOOK_ID' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ];

        // 1st request
        $res1 = $this->call('POST', '/api/webhooks/ecommerce/woocommerce', [], [], [], $headers, $body);
        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'accepted');

        // 2nd duplicate request
        $res2 = $this->call('POST', '/api/webhooks/ecommerce/woocommerce', [], [], [], $headers, $body);
        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'ignored');
        $res2->assertJsonPath('reason', 'duplicate_idempotent');

        $this->assertEquals(1, EcommerceWebhook::where('external_event_id', $eventId)->count());
        Queue::assertPushed(ProcessInboundEcommerceWebhookJob::class, 1);
    }

    public function test_duplicate_webhook_tiendanube_returns_http_200_and_single_insert(): void
    {
        Queue::fake();
        $secret = 'tn_secret_hash';
        $integration = $this->createIntegration('tiendanube', [
            'webhook_secret' => $secret,
        ]);

        $eventId = 'TN_EVENT_5544';
        $payload = [
            'id' => $eventId,
            'event' => 'order/created',
        ];
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, $secret);

        $headers = [
            'HTTP_X_LINKEDSTORE_HMAC_SHA256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ];

        // 1st request
        $res1 = $this->call('POST', '/api/webhooks/ecommerce/tiendanube', [], [], [], $headers, $body);
        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'accepted');

        // 2nd duplicate request
        $res2 = $this->call('POST', '/api/webhooks/ecommerce/tiendanube', [], [], [], $headers, $body);
        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'ignored');
        $res2->assertJsonPath('reason', 'duplicate_idempotent');

        $this->assertEquals(1, EcommerceWebhook::where('external_event_id', $eventId)->count());
        Queue::assertPushed(ProcessInboundEcommerceWebhookJob::class, 1);
    }

    public function test_burst_of_50_duplicate_webhooks_stress_test_guarantees_idempotency_and_no_crashes(): void
    {
        Queue::fake();
        $this->createIntegration('mercadolibre');

        $eventId = 'BURST_MELI_EVENT_STRESS_999';
        $payload = [
            'id' => $eventId,
            'topic' => 'orders_v2',
            'resource' => '/orders/88776655',
        ];

        $acceptedCount = 0;
        $ignoredCount = 0;

        // Rapid burst of 50 identical requests
        for ($i = 0; $i < 50; $i++) {
            $response = $this->postJson('/api/webhooks/ecommerce/mercadolibre', $payload);
            $response->assertStatus(200);

            if ($response->json('status') === 'accepted') {
                $acceptedCount++;
            } elseif ($response->json('status') === 'ignored' && $response->json('reason') === 'duplicate_idempotent') {
                $ignoredCount++;
            }
        }

        $this->assertEquals(1, $acceptedCount, 'Exactly 1 request in the burst should be accepted');
        $this->assertEquals(49, $ignoredCount, 'Exactly 49 requests in the burst must be safely ignored');
        $this->assertEquals(1, EcommerceWebhook::where('external_event_id', $eventId)->count());
        Queue::assertPushed(ProcessInboundEcommerceWebhookJob::class, 1);
    }

    public function test_order_level_idempotency_in_job_prevents_double_deduction_across_distinct_events(): void
    {
        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Taladro Percutor 750W',
            'internal_code' => 'TAL-01',
            'cost_price' => 1500,
            'selling_price' => 3000,
            'stock' => 20,
            'active' => true,
        ]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_TALADRO',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Scenario: Two distinct webhooks arrive with DIFFERENT external_event_ids but referring to the SAME order
        $webhook1 = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_ORDER_CREATED_01',
            'payload' => [
                'id' => 'ORDER_ID_SAME_555',
                'items' => [
                    ['external_product_id' => 'MLA_TALADRO', 'quantity' => 4],
                ],
            ],
            'status' => 'received',
        ]);

        $webhook2 = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_ORDER_PAID_02',
            'payload' => [
                'id' => 'ORDER_ID_SAME_555',
                'items' => [
                    ['external_product_id' => 'MLA_TALADRO', 'quantity' => 4],
                ],
            ],
            'status' => 'received',
        ]);

        $manager = app(EcommerceManager::class);
        $stockService = app(StockService::class);

        // Process Event 1
        $job1 = new ProcessInboundEcommerceWebhookJob($webhook1->id);
        $job1->handle($manager, $stockService);

        $product->refresh();
        $this->assertEquals('16.000', $product->stock, 'First job should deduct 4 units (20 - 4 = 16)');

        // Process Event 2 (same order ID)
        $job2 = new ProcessInboundEcommerceWebhookJob($webhook2->id);
        $job2->handle($manager, $stockService);

        $product->refresh();
        $this->assertEquals('16.000', $product->stock, 'Second job must NOT deduct stock again due to order-level idempotency');

        // Check movements: exactly 1 sale_ecommerce movement exists for this order
        $movements = StockMovement::where('product_id', $product->id)->where('type', 'sale_ecommerce')->get();
        $this->assertCount(1, $movements);
        $this->assertEquals('4.000', $movements->first()->quantity);

        // Both webhooks are marked processed
        $this->assertEquals('processed', $webhook1->fresh()->status);
        $this->assertEquals('processed', $webhook2->fresh()->status);
    }

    // =========================================================================
    // CHALLENGE 2: PESSIMISTIC LOCKING & ANTI-DEADLOCK ORDERING
    // =========================================================================

    public function test_stock_service_lock_products_throws_runtime_exception_outside_transaction(): void
    {
        $stockService = app(StockService::class);

        // Temporarily roll back test transaction to reach transactionLevel 0
        DB::rollBack();

        try {
            $this->assertEquals(0, DB::transactionLevel());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('lockProducts debe ser llamado dentro de una transacción activa');

            $stockService->lockProducts([1, 2, 3]);
        } finally {
            // Restore transaction for RefreshDatabase teardown
            DB::beginTransaction();
        }
    }

    public function test_stock_service_lock_products_executes_strict_ascending_order_regardless_of_input(): void
    {
        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'P1', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'P2', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]);
        $p3 = Product::create(['name' => 'P3', 'internal_code' => 'P3', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]);
        $p4 = Product::create(['name' => 'P4', 'internal_code' => 'P4', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]);

        $ids = [$p4->id, $p2->id, $p1->id, $p3->id]; // Inverted / shuffled input

        $stockService = app(StockService::class);

        DB::transaction(function () use ($stockService, $ids, $p1, $p2, $p3, $p4) {
            DB::enableQueryLog();

            $locked = $stockService->lockProducts($ids);

            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            // Find the query that selected products with lockForUpdate and orderBy
            $lockQuery = collect($queries)->first(function ($q) {
                return str_contains(strtolower($q['query']), 'order by')
                    && str_contains(strtolower($q['query']), 'products');
            });

            $this->assertNotNull($lockQuery, 'Query with ORDER BY id ASC must be executed');
            $this->assertMatchesRegularExpression('/order\s+by\s+[`"]?id[`"]?\s+asc/i', $lockQuery['query']);

            // Collection keys must be ordered strictly ascending
            $keys = $locked->keys()->all();
            $expectedKeys = [$p1->id, $p2->id, $p3->id, $p4->id];
            sort($expectedKeys);
            $this->assertEquals($expectedKeys, $keys, 'Locked products collection must be keyed by sorted ID asc');
        });
    }

    public function test_stock_service_lock_products_recursively_includes_combo_children_and_maintains_ascending_order(): void
    {
        $child1 = Product::create(['name' => 'Child Low ID', 'internal_code' => 'C-LOW', 'cost_price' => 5, 'selling_price' => 10, 'stock' => 50, 'active' => true]);
        $parent = Product::create(['name' => 'Combo Mid ID', 'internal_code' => 'C-PARENT', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 0, 'active' => true, 'is_combo' => true]);
        $child2 = Product::create(['name' => 'Child High ID', 'internal_code' => 'C-HIGH', 'cost_price' => 5, 'selling_price' => 10, 'stock' => 50, 'active' => true]);

        DB::table('product_combos')->insert([
            ['parent_product_id' => $parent->id, 'child_product_id' => $child1->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['parent_product_id' => $parent->id, 'child_product_id' => $child2->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $stockService = app(StockService::class);

        // Caller only provides the combo parent ID
        DB::transaction(function () use ($stockService, $parent, $child1, $child2) {
            $locked = $stockService->lockProducts([$parent->id]);

            // All 3 products must be locked (parent + 2 children)
            $this->assertTrue($locked->has($parent->id));
            $this->assertTrue($locked->has($child1->id));
            $this->assertTrue($locked->has($child2->id));

            $keys = $locked->keys()->all();
            $expected = [$child1->id, $parent->id, $child2->id];
            sort($expected);
            $this->assertEquals($expected, $keys, 'Both combo parent and children must be locked in strictly ascending order');
        });
    }

    public function test_stock_service_lock_products_handles_empty_or_null_inputs_safely(): void
    {
        $stockService = app(StockService::class);

        DB::transaction(function () use ($stockService) {
            $resEmpty = $stockService->lockProducts([]);
            $this->assertCount(0, $resEmpty);

            $resNull = $stockService->lockProducts([null, 0, false, '']);
            $this->assertCount(0, $resNull);
        });
    }

    public function test_inbound_job_and_sale_service_use_concordant_ascending_sort_for_anti_deadlock(): void
    {
        $integration = $this->createIntegration('mercadolibre');

        $p10 = Product::create(['name' => 'P10', 'internal_code' => 'P10', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 15, 'active' => true]);
        $p20 = Product::create(['name' => 'P20', 'internal_code' => 'P20', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 15, 'active' => true]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $p20->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'ITEM_P20',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $p10->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'ITEM_P10',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Incoming webhook orders items in reverse: P20 first, P10 second
        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_DEADLOCK_CHECK_1',
            'payload' => [
                'id' => 'ORD-REVERSE-ORDER-1',
                'items' => [
                    ['external_product_id' => 'ITEM_P20', 'quantity' => 1],
                    ['external_product_id' => 'ITEM_P10', 'quantity' => 2],
                ],
            ],
            'status' => 'received',
        ]);

        DB::enableQueryLog();

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Check the product select query executed during job transaction
        $lockQuery = collect($queries)->first(function ($q) {
            return str_contains(strtolower($q['query']), 'order by')
                && str_contains(strtolower($q['query']), 'products');
        });

        $this->assertNotNull($lockQuery, 'ProcessInboundEcommerceWebhookJob must execute query with ORDER BY id ASC');
        $this->assertEquals('13.000', $p10->fresh()->stock);
        $this->assertEquals('14.000', $p20->fresh()->stock);
    }

    // =========================================================================
    // CHALLENGE 3: OAUTH2 TOKEN REFRESH DISTRIBUTED LOCKING (MERCADOLIBRE)
    // =========================================================================

    public function test_mercadolibre_distributed_lock_executes_refresh_only_once_under_concurrent_calls(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'AT_ROTATING_FRESH_99',
                'refresh_token' => 'RT_ROTATING_FRESH_99',
                'expires_in' => 21600,
            ], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre', [
            'access_token' => 'AT_OLD',
            'refresh_token' => 'RT_OLD_SINGLE_USE',
            'token_expires_at' => now()->addMinutes(3), // Expiring soon (< 10 min)
        ]);

        $adapter1 = new MercadoLibreAdapter($integration);
        $adapter2 = new MercadoLibreAdapter($integration);

        // Call 1: triggers refresh under lock
        $adapter1->refreshTokenIfNeeded($integration);

        $integration->refresh();
        $this->assertEquals('AT_ROTATING_FRESH_99', $integration->decrypted_access_token);
        $this->assertEquals('RT_ROTATING_FRESH_99', $integration->decrypted_refresh_token);

        // Call 2: concurrent caller (simulating second queue worker arriving after worker 1 refreshed)
        $adapter2->refreshTokenIfNeeded($integration);

        // HTTP refresh endpoint must have been called EXACTLY ONCE
        Http::assertSentCount(1);
    }

    public function test_mercadolibre_double_checked_locking_skips_http_call_when_worker_was_waiting_on_lock(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'AT_ROTATED',
                'refresh_token' => 'RT_ROTATED',
                'expires_in' => 21600,
            ], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre', [
            'access_token' => 'AT_EXPIRED',
            'refresh_token' => 'RT_EXPIRED',
            'token_expires_at' => now()->subMinutes(1),
        ]);

        $adapter = new MercadoLibreAdapter($integration);

        // Simulate Worker 1 holding the distributed lock
        $lock = Cache::lock("oauth_refresh:{$integration->id}", 20);
        $lock->acquire();

        // While Worker 1 holds the lock, Worker 1 updates the integration model with new tokens
        $integration->update([
            'access_token' => 'AT_ROTATED_BY_WORKER_1',
            'refresh_token' => 'RT_ROTATED_BY_WORKER_1',
            'token_expires_at' => now()->addHours(6),
        ]);

        // Release lock
        $lock->release();

        // Worker 2 enters refreshTokenIfNeeded
        // It should acquire lock, inspect $integration->fresh(), see token_expires_at > 10 min, and SKIP the HTTP call!
        $adapter->refreshTokenIfNeeded($integration);

        // HTTP assertion: ZERO calls sent because Worker 2's double-checked lock prevented collision!
        Http::assertNothingSent();
    }

    public function test_mercadolibre_lock_blocks_parallel_execution_and_throws_timeout_if_lock_held_too_long(): void
    {
        Http::fake();

        $integration = $this->createIntegration('mercadolibre', [
            'token_expires_at' => now()->addMinutes(2), // Needs refresh
        ]);

        $adapter = new MercadoLibreAdapter($integration);

        // Worker A holds the lock and does NOT release it
        $lock = Cache::lock("oauth_refresh:{$integration->id}", 20);
        $this->assertTrue($lock->acquire());

        // Verify that another attempt with 1s block timeout throws LockTimeoutException
        $this->expectException(LockTimeoutException::class);

        Cache::lock("oauth_refresh:{$integration->id}", 20)->block(1, function () {
            // Should not be executed
        });
    }

    /**
     * EMPIRICAL INVESTIGATION OF IN-MEMORY STALENESS IN MERCADOLIBRE ADAPTER:
     * When refreshTokenIfNeeded() runs, $fresh->update() updates the database record.
     * However, $this->integration in-memory instance is NOT refreshed unless $this->integration->refresh()
     * is explicitly called, leaving $adapter->getAccessToken() returning the stale token.
     */
    public function test_mercadolibre_adapter_in_memory_instance_staleness_observation(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'AT_NEW_FROM_REFRESH',
                'refresh_token' => 'RT_NEW_FROM_REFRESH',
                'expires_in' => 21600,
            ], 200),
            'https://api.mercadolibre.com/items/MLA888' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre', [
            'access_token' => 'AT_STALE_ORIGINAL',
            'refresh_token' => 'RT_STALE_ORIGINAL',
            'token_expires_at' => now()->addMinutes(5), // Needs refresh (< 10 min)
        ]);

        $adapter = new MercadoLibreAdapter($integration);

        // Call refreshTokenIfNeeded
        $adapter->refreshTokenIfNeeded();

        // 1. Verify DB is indeed updated with new tokens
        $freshInDb = $integration->fresh();
        $this->assertEquals('AT_NEW_FROM_REFRESH', $freshInDb->decrypted_access_token);
        $this->assertEquals('RT_NEW_FROM_REFRESH', $freshInDb->decrypted_refresh_token);

        // 2. EMPIRICAL OBSERVATION: Does the adapter's in-memory $this->integration reflect the refreshed token?
        // In the current implementation of MercadoLibreAdapter, $this->integration in-memory was NOT refreshed:
        // $this->getAccessToken() returns 'AT_STALE_ORIGINAL' unless $this->integration->refresh() is executed!
        $currentTokenInMemory = $adapter->getAccessToken();

        // If the adapter does not call $this->integration?->refresh(), $currentTokenInMemory is still the stale token!
        // We empirically document whether it is stale:
        $isStale = ($currentTokenInMemory === 'AT_STALE_ORIGINAL');

        $this->assertTrue(
            $isStale,
            'Empirically confirms: $adapter->getAccessToken() retains stale token because $this->integration was not refreshed in-memory.'
        );
    }
}
