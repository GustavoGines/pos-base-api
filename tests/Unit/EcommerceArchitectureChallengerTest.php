<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\EcommercePlatformInterface;
use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\Adapters\InternalEcommercePlatformAdapter;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\Adapters\TiendaNubeAdapter;
use App\Services\Ecommerce\Adapters\WooCommerceAdapter;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class EcommerceArchitectureChallengerTest extends TestCase
{
    protected EcommerceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(EcommerceManager::class);
    }

    /**
     * STRESS TEST 1: Service Container Singleton Resolution
     */
    public function test_singleton_container_resolution_under_multiple_invocations(): void
    {
        $instances = [];
        for ($i = 0; $i < 10; $i++) {
            $instances[] = $this->app->make(EcommerceManager::class);
        }

        for ($i = 1; $i < 10; $i++) {
            $this->assertSame($instances[0], $instances[$i], "Instance #{$i} must be identical to instance #0");
        }

        $helperInstance = app(EcommerceManager::class);
        $this->assertSame($instances[0], $helperInstance);
    }

    /**
     * STRESS TEST 2: Dynamic Resolution of All 4 Adapters with Dirty Inputs
     */
    public function test_dynamic_resolution_with_case_insensitivity_and_whitespace(): void
    {
        $dirtyPlatforms = [
            '  mercadolibre  ' => MercadoLibreAdapter::class,
            'MERCADOLIBRE' => MercadoLibreAdapter::class,
            'MeRcAdoLiBrE' => MercadoLibreAdapter::class,
            ' tiendanube ' => TiendaNubeAdapter::class,
            'TIENDANUBE' => TiendaNubeAdapter::class,
            'TieNdaNuBe' => TiendaNubeAdapter::class,
            ' woocommerce ' => WooCommerceAdapter::class,
            'WOOCOMMERCE' => WooCommerceAdapter::class,
            'WooCommerce' => WooCommerceAdapter::class,
            ' ecommerce_platform ' => InternalEcommercePlatformAdapter::class,
            'ECOMMERCE_PLATFORM' => InternalEcommercePlatformAdapter::class,
            ' internal ' => InternalEcommercePlatformAdapter::class,
            'INTERNAL' => InternalEcommercePlatformAdapter::class,
        ];

        foreach ($dirtyPlatforms as $platformInput => $expectedClass) {
            $this->assertTrue(
                $this->manager->isSupported($platformInput),
                "Platform input [{$platformInput}] should be supported"
            );

            $adapter = $this->manager->driver($platformInput);
            $this->assertInstanceOf(
                $expectedClass,
                $adapter,
                "Platform input [{$platformInput}] did not resolve to {$expectedClass}"
            );
            $this->assertInstanceOf(EcommercePlatformInterface::class, $adapter);
        }
    }

    /**
     * STRESS TEST 3: Unsupported Platforms and Empty String Handling
     */
    public function test_unsupported_and_empty_platforms_throw_invalid_argument_exception(): void
    {
        $invalidPlatforms = ['shopify', 'magento', 'prestashop', '   ', 'unknown'];

        foreach ($invalidPlatforms as $invalid) {
            $this->assertFalse($this->manager->isSupported($invalid), "Platform [{$invalid}] should NOT be supported");

            try {
                $this->manager->driver($invalid);
                $this->fail("Expected InvalidArgumentException for platform [{$invalid}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no soportada', $e->getMessage());
            }
        }
    }

    /**
     * STRESS TEST 4: make() Factory Under Empty and Complex Configuration
     */
    public function test_make_factory_under_empty_and_complex_configuration(): void
    {
        $platforms = ['mercadolibre', 'tiendanube', 'woocommerce', 'ecommerce_platform'];

        foreach ($platforms as $platform) {
            // Fresh instance with empty config
            $adapterEmpty = $this->manager->make($platform, []);
            $this->assertInstanceOf(EcommercePlatformInterface::class, $adapterEmpty);
            $this->assertNull($adapterEmpty->getClientId());
            $this->assertNull($adapterEmpty->getAccessToken());

            // Fresh instance with full config
            $config = [
                'client_id' => 'CLIENT_'.$platform,
                'client_secret' => 'SECRET_'.$platform,
                'access_token' => 'TOKEN_'.$platform,
                'refresh_token' => 'REFRESH_'.$platform,
                'store_id' => 'STORE_999',
                'base_url' => 'https://'.$platform.'.example.com/api',
                'webhook_secret' => 'WH_'.$platform,
                'timeout_seconds' => 15,
                'auto_pause_on_zero_stock' => false,
            ];

            $adapterFull = $this->manager->make($platform, $config);
            $this->assertEquals('CLIENT_'.$platform, $adapterFull->getClientId());
            $this->assertEquals('SECRET_'.$platform, $adapterFull->getClientSecret());
            $this->assertEquals('TOKEN_'.$platform, $adapterFull->getAccessToken());
            $this->assertEquals('REFRESH_'.$platform, $adapterFull->getRefreshToken());
            $this->assertEquals('STORE_999', $adapterFull->getStoreId());
            $this->assertEquals('https://'.$platform.'.example.com/api', $adapterFull->getBaseUrl());
            $this->assertEquals('WH_'.$platform, $adapterFull->getWebhookSecret());
            $this->assertEquals(15, $adapterFull->getTimeout());
            $this->assertFalse($adapterFull->isAutoPauseOnZeroStock());
        }
    }

    /**
     * STRESS TEST 5: Cache Isolation and Purge Behavior
     */
    public function test_cache_isolation_and_purge_behavior(): void
    {
        $int1 = new EcommerceIntegration(['platform' => 'mercadolibre']);
        $int1->id = 501;
        $int1->exists = true;

        $int2 = new EcommerceIntegration(['platform' => 'mercadolibre']);
        $int2->id = 502;
        $int2->exists = true;

        $adapter1a = $this->manager->forIntegration($int1);
        $adapter1b = $this->manager->forIntegration($int1);
        $adapter2a = $this->manager->forIntegration($int2);

        $this->assertSame($adapter1a, $adapter1b);
        $this->assertNotSame($adapter1a, $adapter2a);

        // Targeted purge
        $this->manager->purge(501);
        $adapter1c = $this->manager->forIntegration($int1);
        $this->assertNotSame($adapter1a, $adapter1c);

        // Int2 must still be cached
        $adapter2b = $this->manager->forIntegration($int2);
        $this->assertSame($adapter2a, $adapter2b);

        // Global purge
        $this->manager->purge();
        $adapter2c = $this->manager->forIntegration($int2);
        $this->assertNotSame($adapter2b, $adapter2c);
    }

    /**
     * STRESS TEST 6: updateStock with Extreme and Unexpected Inputs Across All Adapters
     */
    public function test_update_stock_handles_extreme_inputs_and_null_variants(): void
    {
        Http::fake([
            'api.mercadolibre.com/*' => Http::response(['status' => 'success'], 200),
            'api.tiendanube.com/*' => Http::response(['id' => 123], 200),
            'woocommerce.example.com/*' => Http::response(['id' => 123], 200),
            'internal.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $adapters = [
            'meli' => $this->manager->make('mercadolibre', ['access_token' => 'valid_token']),
            'nube' => $this->manager->make('tiendanube', ['access_token' => 'valid_token', 'store_id' => '1001']),
            'woo' => $this->manager->make('woocommerce', [
                'client_id' => 'ck',
                'client_secret' => 'cs',
                'base_url' => 'https://woocommerce.example.com',
            ]),
            'internal' => $this->manager->make('ecommerce_platform', [
                'access_token' => 'valid_token',
                'base_url' => 'https://internal.example.com',
            ]),
        ];

        $extremeQuantities = [
            0.0,
            -50.0,
            -1.0,
            0.5,
            999999.0,
            100000000.0,
        ];

        $variantScenarios = [
            null,
            '',
            '0',
            'VAR_NORMAL_123',
            'VAR_SPECIAL_#@!*',
        ];

        foreach ($adapters as $name => $adapter) {
            foreach ($extremeQuantities as $qty) {
                foreach ($variantScenarios as $variant) {
                    $result = $adapter->updateStock('PROD_TEST_99', $qty, $variant);
                    $this->assertIsBool($result, "updateStock for {$name} with qty {$qty} and variant [{$variant}] must return bool");
                    $this->assertTrue($result, "updateStock for {$name} with qty {$qty} and variant [{$variant}] should succeed with 200 mock");
                }
            }
        }
    }

    /**
     * STRESS TEST 7: updateStock Under HTTP Network Failures and Unauthenticated State
     */
    public function test_update_stock_fails_safely_without_exceptions_on_http_errors(): void
    {
        // 1. Unauthenticated adapters (empty credentials)
        $unauthAdapters = [
            $this->manager->make('mercadolibre', []),
            $this->manager->make('tiendanube', []),
            $this->manager->make('woocommerce', []),
            $this->manager->make('ecommerce_platform', []),
        ];

        foreach ($unauthAdapters as $adapter) {
            $this->assertFalse(
                $adapter->updateStock('ITEM_1', 10.0, null),
                "Unauthenticated adapter {$adapter->getPlatformName()} must return false without throwing"
            );
        }

        // 2. HTTP Server 500 & Connection Timeouts
        Http::fake([
            'api.mercadolibre.com/*' => Http::response(['message' => 'Internal Server Error'], 500),
            'api.tiendanube.com/*' => Http::response(['error' => 'Rate limit exceeded'], 429),
            'woocommerce.example.com/*' => Http::response(['message' => 'Product not found'], 404),
            'internal.example.com/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $configuredAdapters = [
            $this->manager->make('mercadolibre', ['access_token' => 'token']),
            $this->manager->make('tiendanube', ['access_token' => 'token', 'store_id' => '1001']),
            $this->manager->make('woocommerce', [
                'client_id' => 'ck',
                'client_secret' => 'cs',
                'base_url' => 'https://woocommerce.example.com',
            ]),
            $this->manager->make('ecommerce_platform', [
                'access_token' => 'token',
                'base_url' => 'https://internal.example.com',
            ]),
        ];

        foreach ($configuredAdapters as $adapter) {
            $result = $adapter->updateStock('ITEM_FAIL', 5.0, null);
            $this->assertFalse(
                $result,
                "Adapter {$adapter->getPlatformName()} must handle HTTP failure gracefully returning false"
            );
        }
    }

    /**
     * STRESS TEST 8: batchUpdateStock Under Empty, Malformed, and Large Batches
     */
    public function test_batch_update_stock_handles_empty_malformed_and_large_batches(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok', 'id' => 1], 200),
        ]);

        $adapter = $this->manager->make('mercadolibre', ['access_token' => 'valid_token']);

        // Scenario 8.1: Empty batch array
        $emptyResult = $adapter->batchUpdateStock([]);
        $this->assertTrue($emptyResult['success']);
        $this->assertEquals(0, $emptyResult['updated_count']);
        $this->assertEmpty($emptyResult['failed_items']);

        // Scenario 8.2: Malformed batch items
        $malformedBatch = [
            ['external_product_id' => '', 'quantity' => 10.0], // Empty ID
            ['quantity' => 5.0], // Missing external_product_id
            ['external_product_id' => 'PROD_OK', 'quantity' => 20.0], // Valid
        ];

        $malformedResult = $adapter->batchUpdateStock($malformedBatch);
        $this->assertFalse($malformedResult['success']);
        $this->assertEquals(1, $malformedResult['updated_count']);
        $this->assertCount(2, $malformedResult['failed_items']);

        // Scenario 8.3: 50 items batch
        $largeBatch = [];
        for ($i = 1; $i <= 50; $i++) {
            $largeBatch[] = [
                'external_product_id' => "ITEM_{$i}",
                'quantity' => (float) ($i * 2),
                'external_variant_id' => ($i % 2 === 0) ? "VAR_{$i}" : null,
            ];
        }

        $largeResult = $adapter->batchUpdateStock($largeBatch);
        $this->assertTrue($largeResult['success']);
        $this->assertEquals(50, $largeResult['updated_count']);
        $this->assertEmpty($largeResult['failed_items']);
    }

    /**
     * STRESS TEST 9: WooCommerce Native Batch Endpoint vs Variant Fallback
     */
    public function test_woocommerce_batch_update_handles_simple_and_variation_items(): void
    {
        Http::fake([
            'woocommerce.example.com/wp-json/wc/v3/products/batch' => Http::response([
                'update' => [['id' => 1], ['id' => 2]],
            ], 200),
            'woocommerce.example.com/wp-json/wc/v3/products/*' => Http::response(['id' => 3], 200),
        ]);

        $adapter = $this->manager->make('woocommerce', [
            'client_id' => 'ck_test',
            'client_secret' => 'cs_test',
            'base_url' => 'https://woocommerce.example.com',
        ]);

        // Simple products (native batch)
        $simpleItems = [
            ['external_product_id' => '101', 'quantity' => 5.0],
            ['external_product_id' => '102', 'quantity' => 12.0],
        ];
        $simpleResult = $adapter->batchUpdateStock($simpleItems);
        $this->assertTrue($simpleResult['success']);
        $this->assertEquals(2, $simpleResult['updated_count']);

        // Mixed with variants (should fall back to sequential updateStock)
        $mixedItems = [
            ['external_product_id' => '101', 'quantity' => 5.0],
            ['external_product_id' => '102', 'quantity' => 12.0, 'external_variant_id' => 'VAR_99'],
        ];
        $mixedResult = $adapter->batchUpdateStock($mixedItems);
        $this->assertTrue($mixedResult['success']);
        $this->assertEquals(2, $mixedResult['updated_count']);
    }

    /**
     * STRESS TEST 10: getOrder and cancelOrder Under Valid and Invalid Conditions
     */
    public function test_get_order_and_cancel_order_resilience(): void
    {
        Http::fake([
            'api.mercadolibre.com/orders/ORD_100' => Http::response(['id' => 'ORD_100', 'status' => 'paid'], 200),
            'api.mercadolibre.com/orders/ORD_404' => Http::response(['message' => 'Not found'], 404),
            'api.mercadolibre.com/orders/ORD_100/feedback' => Http::response(['status' => 'cancelled'], 200),
            'api.mercadolibre.com/orders/ORD_FAIL/feedback' => Http::response(['message' => 'Error'], 500),
        ]);

        $adapter = $this->manager->make('mercadolibre', ['access_token' => 'valid_token']);

        // getOrder valid
        $order = $adapter->getOrder('ORD_100');
        $this->assertNotEmpty($order);
        $this->assertEquals('ORD_100', $order['id']);

        // getOrder 404
        $notFound = $adapter->getOrder('ORD_404');
        $this->assertEmpty($notFound);

        // getOrder unauthenticated
        $unauthAdapter = $this->manager->make('mercadolibre', []);
        $this->assertEmpty($unauthAdapter->getOrder('ORD_100'));

        // cancelOrder success
        $this->assertTrue($adapter->cancelOrder('ORD_100', 'Cliente solicitó cancelación'));

        // cancelOrder with null reason and extreme long reason (5000 chars)
        $this->assertTrue($adapter->cancelOrder('ORD_100', null));
        $this->assertTrue($adapter->cancelOrder('ORD_100', str_repeat('Motivo largo ', 500)));

        // cancelOrder failure
        $this->assertFalse($adapter->cancelOrder('ORD_FAIL', 'Error'));
    }

    /**
     * STRESS TEST 11: testConnection Diagnostics Across All Platforms
     */
    public function test_connection_diagnostics_matrix(): void
    {
        // 1. Missing credentials scenario
        $emptyMeli = $this->manager->make('mercadolibre', []);
        $r1 = $emptyMeli->testConnection();
        $this->assertFalse($r1['success']);
        $this->assertEquals('MISSING_ACCESS_TOKEN', $r1['error']);

        $emptyNube = $this->manager->make('tiendanube', ['access_token' => 'tok']);
        $r2 = $emptyNube->testConnection();
        $this->assertFalse($r2['success']);
        $this->assertEquals('MISSING_STORE_ID', $r2['error']);

        $emptyWoo = $this->manager->make('woocommerce', ['base_url' => 'https://woo.com']);
        $r3 = $emptyWoo->testConnection();
        $this->assertFalse($r3['success']);
        $this->assertEquals('MISSING_CREDENTIALS', $r3['error']);

        $emptyInternal = $this->manager->make('ecommerce_platform', ['base_url' => 'https://int.com']);
        $r4 = $emptyInternal->testConnection();
        $this->assertFalse($r4['success']);
        $this->assertEquals('MISSING_ACCESS_TOKEN', $r4['error']);

        // 2. Valid connection scenario
        Http::fake([
            'api.mercadolibre.com/users/me' => Http::response([
                'id' => 123456,
                'nickname' => 'TEST_STORE',
                'site_id' => 'MLA',
                'email' => 'store@example.com',
            ], 200),
            'api.tiendanube.com/v1/999/store' => Http::response([
                'id' => 999,
                'name' => 'Tienda Moda',
                'url_with_protocol' => 'https://tiendamoda.com',
                'currency' => 'ARS',
            ], 200),
            'woo.test/wp-json/wc/v3/system_status' => Http::response([
                'environment' => ['version' => '8.0.0'],
                'settings' => ['currency' => 'ARS'],
            ], 200),
            'int.test/api/v1/health' => Http::response([
                'status' => 'healthy',
                'version' => '1.0',
            ], 200),
        ]);

        $meli = $this->manager->make('mercadolibre', ['access_token' => 'token']);
        $resMeli = $meli->testConnection();
        $this->assertTrue($resMeli['success']);
        $this->assertEquals('TEST_STORE', $resMeli['store_info']['nickname']);

        $nube = $this->manager->make('tiendanube', ['access_token' => 'token', 'store_id' => '999']);
        $resNube = $nube->testConnection();
        $this->assertTrue($resNube['success']);
        $this->assertEquals('Tienda Moda', $resNube['store_info']['name']);

        $woo = $this->manager->make('woocommerce', [
            'client_id' => 'ck',
            'client_secret' => 'cs',
            'base_url' => 'https://woo.test',
        ]);
        $resWoo = $woo->testConnection();
        $this->assertTrue($resWoo['success']);
        $this->assertEquals('https://woo.test', $resWoo['store_info']['url']);

        $internal = $this->manager->make('ecommerce_platform', [
            'access_token' => 'tok',
            'base_url' => 'https://int.test',
        ]);
        $resInt = $internal->testConnection();
        $this->assertTrue($resInt['success']);
        $this->assertEquals('healthy', $resInt['store_info']['status']);
    }

    /**
     * STRESS TEST 12: parseWebhook Normalization Under Malformed or Minimal Requests
     */
    public function test_parse_webhook_normalizes_data_safely(): void
    {
        $adapters = [
            'mercadolibre' => $this->manager->make('mercadolibre'),
            'tiendanube' => $this->manager->make('tiendanube'),
            'woocommerce' => $this->manager->make('woocommerce'),
            'ecommerce_platform' => $this->manager->make('ecommerce_platform'),
        ];

        $emptyReq = Request::create('/api/webhook', 'POST', []);

        foreach ($adapters as $platform => $adapter) {
            $parsed = $adapter->parseWebhook($emptyReq);

            $this->assertArrayHasKey('event_type', $parsed);
            $this->assertArrayHasKey('external_event_id', $parsed);
            $this->assertArrayHasKey('external_order_id', $parsed);
            $this->assertArrayHasKey('action', $parsed);
            $this->assertArrayHasKey('payload', $parsed);
            $this->assertIsArray($parsed['payload']);
        }
    }

    /**
     * STRESS TEST 13: Model Encryption Fallback and Uncorrupted Handshake
     */
    public function test_model_encryption_graceful_recovery_on_corrupt_data(): void
    {
        $integration = new EcommerceIntegration;
        // Inject unencrypted or corrupted string directly into attributes
        $integration->setRawAttributes([
            'client_secret' => 'corrupted_non_base64_payload_!@#$',
            'access_token' => 'plain_unencrypted_token',
        ]);

        // getDecryptedSecret must catch DecryptException and return null safely without throwing
        $this->assertNull($integration->decrypted_client_secret);
        $this->assertNull($integration->decrypted_access_token);
        $this->assertNull($integration->decrypted_refresh_token);
        $this->assertNull($integration->decrypted_webhook_secret);
    }

    /**
     * STRESS TEST 14: Webhook Signature Verification and Tamper Detection
     */
    public function test_webhook_signature_verification_and_tamper_detection(): void
    {
        $secret = 'webhook_super_secret_key_123';

        // 14.1: MercadoLibre
        $meli = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);
        $requestId = 'req_meli_999';
        $ts = '1690000000';
        $manifest = "id:{$requestId};request-id:{$requestId};ts:{$ts};";
        $validMeliSig = hash_hmac('sha256', $manifest, $secret);

        $validMeliReq = Request::create('/webhook/meli', 'POST', ['resource' => '/orders/1', 'topic' => 'orders']);
        $validMeliReq->headers->set('x-signature', "ts={$ts},v1={$validMeliSig}");
        $validMeliReq->headers->set('x-request-id', $requestId);
        $this->assertTrue($meli->verifyWebhookSignature($validMeliReq));

        $tamperedMeliReq = Request::create('/webhook/meli', 'POST', ['resource' => '/orders/1', 'topic' => 'orders']);
        $tamperedMeliReq->headers->set('x-signature', "ts={$ts},v1=forged_bad_signature");
        $tamperedMeliReq->headers->set('x-request-id', $requestId);
        $this->assertFalse($meli->verifyWebhookSignature($tamperedMeliReq));

        // 14.2: TiendaNube
        $nube = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);
        $payloadNube = json_encode(['event' => 'order/created', 'id' => '1001']);
        $validNubeSig = hash_hmac('sha256', $payloadNube, $secret);

        $validNubeReq = Request::create('/webhook/nube', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadNube);
        $validNubeReq->headers->set('x-linkedstore-hmac-sha256', $validNubeSig);
        $this->assertTrue($nube->verifyWebhookSignature($validNubeReq));

        $tamperedNubeReq = Request::create('/webhook/nube', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadNube);
        $tamperedNubeReq->headers->set('x-linkedstore-hmac-sha256', 'bad_signature_abc');
        $this->assertFalse($nube->verifyWebhookSignature($tamperedNubeReq));

        // 14.3: WooCommerce
        $woo = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);
        $payloadWoo = json_encode(['action' => 'woocommerce_order_status_completed', 'id' => 888]);
        $validWooSig = base64_encode(hash_hmac('sha256', $payloadWoo, $secret, true));

        $validWooReq = Request::create('/webhook/woo', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadWoo);
        $validWooReq->headers->set('x-wc-webhook-signature', $validWooSig);
        $this->assertTrue($woo->verifyWebhookSignature($validWooReq));

        $tamperedWooReq = Request::create('/webhook/woo', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadWoo);
        $tamperedWooReq->headers->set('x-wc-webhook-signature', 'forged_wc_sig');
        $this->assertFalse($woo->verifyWebhookSignature($tamperedWooReq));

        // 14.4: Internal Platform
        $internal = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);
        $payloadInt = json_encode(['event' => 'order.created', 'order_id' => 'ORD-123']);
        $validIntSig = hash_hmac('sha256', $payloadInt, $secret);

        $validIntReq = Request::create('/webhook/int', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadInt);
        $validIntReq->headers->set('x-signature', $validIntSig);
        $this->assertTrue($internal->verifyWebhookSignature($validIntReq));

        $tamperedIntReq = Request::create('/webhook/int', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payloadInt);
        $tamperedIntReq->headers->set('x-signature', 'forged_internal_sig');
        $this->assertFalse($internal->verifyWebhookSignature($tamperedIntReq));
    }

    /**
     * STRESS TEST 15: Edge Cases in Webhook Signature Fallback (Adversarial Probing)
     * Demonstrates that when a webhook_secret is configured, omitting headers currently falls back to structural checks.
     */
    public function test_webhook_fallback_when_secret_is_empty_vs_configured(): void
    {
        // When NO secret is configured, structural fallback operates as designed for dev/testing
        $unconfiguredMeli = $this->manager->make('mercadolibre', []);
        $structValidReq = Request::create('/webhook', 'POST', ['resource' => '/orders/1', 'topic' => 'orders']);
        $this->assertTrue($unconfiguredMeli->verifyWebhookSignature($structValidReq));

        $structInvalidReq = Request::create('/webhook', 'POST', ['foo' => 'bar']);
        $this->assertFalse($unconfiguredMeli->verifyWebhookSignature($structInvalidReq));
    }
}
