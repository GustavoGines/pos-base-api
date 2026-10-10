<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\EcommercePlatformInterface;
use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\Adapters\AbstractEcommerceAdapter;
use App\Services\Ecommerce\Adapters\InternalEcommercePlatformAdapter;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\Adapters\TiendaNubeAdapter;
use App\Services\Ecommerce\Adapters\WooCommerceAdapter;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class EcommerceManagerTest extends TestCase
{
    protected EcommerceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new EcommerceManager;
    }

    /**
     * Test EcommerceManager resolves via container as a singleton.
     */
    public function test_ecommerce_manager_is_bound_as_singleton_in_container(): void
    {
        $instance1 = $this->app->make(EcommerceManager::class);
        $instance2 = $this->app->make(EcommerceManager::class);

        $this->assertInstanceOf(EcommerceManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test that all 4 canonical platforms plus aliases resolve to EcommercePlatformInterface.
     */
    public function test_driver_resolves_all_supported_platform_adapters(): void
    {
        $meli = $this->manager->driver('mercadolibre');
        $this->assertInstanceOf(EcommercePlatformInterface::class, $meli);
        $this->assertInstanceOf(MercadoLibreAdapter::class, $meli);
        $this->assertEquals('mercadolibre', $meli->getPlatformName());

        $nube = $this->manager->driver('tiendanube');
        $this->assertInstanceOf(EcommercePlatformInterface::class, $nube);
        $this->assertInstanceOf(TiendaNubeAdapter::class, $nube);
        $this->assertEquals('tiendanube', $nube->getPlatformName());

        $woo = $this->manager->driver('woocommerce');
        $this->assertInstanceOf(EcommercePlatformInterface::class, $woo);
        $this->assertInstanceOf(WooCommerceAdapter::class, $woo);
        $this->assertEquals('woocommerce', $woo->getPlatformName());

        $internal = $this->manager->driver('ecommerce_platform');
        $this->assertInstanceOf(EcommercePlatformInterface::class, $internal);
        $this->assertInstanceOf(InternalEcommercePlatformAdapter::class, $internal);
        $this->assertEquals('ecommerce_platform', $internal->getPlatformName());

        $internalAlias = $this->manager->driver('internal');
        $this->assertInstanceOf(EcommercePlatformInterface::class, $internalAlias);
        $this->assertInstanceOf(InternalEcommercePlatformAdapter::class, $internalAlias);
    }

    /**
     * Test make() returns fresh, uncached instances with custom config.
     */
    public function test_make_instantiates_fresh_uncached_adapter_with_config(): void
    {
        $config = [
            'client_id' => 'woo_ck_12345',
            'client_secret' => 'woo_cs_67890',
            'base_url' => 'https://example.com/store',
            'timeout_seconds' => 45,
        ];

        $adapter1 = $this->manager->make('woocommerce', $config);
        $adapter2 = $this->manager->make('woocommerce', $config);

        $this->assertInstanceOf(WooCommerceAdapter::class, $adapter1);
        $this->assertInstanceOf(WooCommerceAdapter::class, $adapter2);
        $this->assertNotSame($adapter1, $adapter2);

        $this->assertEquals('woo_ck_12345', $adapter1->getClientId());
        $this->assertEquals('woo_cs_67890', $adapter1->getClientSecret());
        $this->assertEquals('https://example.com/store', $adapter1->getBaseUrl());
        $this->assertEquals(45, $adapter1->getTimeout());
    }

    /**
     * Test forIntegration resolves and memoizes adapter instances.
     */
    public function test_for_integration_resolves_and_caches_adapter_instance(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'mercadolibre',
            'name' => 'Official MELI Store',
            'client_id' => 'APP_123',
            'timeout_seconds' => 25,
        ]);
        $integration->id = 101;
        $integration->exists = true;

        $adapter1 = $this->manager->forIntegration($integration);
        $adapter2 = $this->manager->forIntegration($integration);

        $this->assertInstanceOf(MercadoLibreAdapter::class, $adapter1);
        $this->assertSame($adapter1, $adapter2);
        $this->assertSame($integration, $adapter1->getIntegration());
        $this->assertEquals('APP_123', $adapter1->getClientId());
        $this->assertEquals(25, $adapter1->getTimeout());
    }

    /**
     * Test purge invalidates cached adapter instances.
     */
    public function test_purge_clears_cached_integration_adapter(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'tiendanube',
            'name' => 'Nube Store',
            'store_id' => '998877',
        ]);
        $integration->id = 202;
        $integration->exists = true;

        $adapter1 = $this->manager->forIntegration($integration);

        // Purge specific integration
        $this->manager->purge(202);
        $adapter2 = $this->manager->forIntegration($integration);
        $this->assertNotSame($adapter1, $adapter2);

        // Purge all
        $adapter3 = $this->manager->forIntegration($integration);
        $this->assertSame($adapter2, $adapter3);
        $this->manager->purge();
        $adapter4 = $this->manager->forIntegration($integration);
        $this->assertNotSame($adapter3, $adapter4);
    }

    /**
     * Test driver throws InvalidArgumentException for unsupported platform.
     */
    public function test_driver_throws_exception_for_unsupported_platform(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plataforma de e-commerce no soportada [shopify]');

        $this->manager->driver('shopify');
    }

    /**
     * Test extend() allows registering a custom platform driver.
     */
    public function test_extend_registers_custom_driver_successfully(): void
    {
        $customMock = new FakeShopifyAdapter;

        $this->manager->extend('custom_shopify', function ($integration, $config) use ($customMock) {
            return $customMock;
        });

        $this->assertTrue($this->manager->isSupported('custom_shopify'));
        $this->assertContains('custom_shopify', $this->manager->supportedPlatforms());

        $resolved = $this->manager->driver('custom_shopify');
        $this->assertSame($customMock, $resolved);
        $this->assertEquals('custom_shopify', $resolved->getPlatformName());
    }

    /**
     * Test extend throws InvalidArgumentException if custom driver does not implement interface.
     */
    public function test_extend_throws_exception_if_callback_returns_non_interface(): void
    {
        $this->manager->extend('bad_driver', function () {
            return new \stdClass;
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El driver personalizado para [bad_driver] debe implementar EcommercePlatformInterface');

        $this->manager->driver('bad_driver');
    }

    /**
     * Test isSupported and supportedPlatforms return correct platform lists.
     */
    public function test_is_supported_and_supported_platforms(): void
    {
        $this->assertTrue($this->manager->isSupported('mercadolibre'));
        $this->assertTrue($this->manager->isSupported('MERCADOLIBRE'));
        $this->assertTrue($this->manager->isSupported('tiendanube'));
        $this->assertTrue($this->manager->isSupported('woocommerce'));
        $this->assertTrue($this->manager->isSupported('ecommerce_platform'));
        $this->assertTrue($this->manager->isSupported('internal'));
        $this->assertFalse($this->manager->isSupported('magento'));

        $platforms = $this->manager->supportedPlatforms();
        $this->assertContains('mercadolibre', $platforms);
        $this->assertContains('tiendanube', $platforms);
        $this->assertContains('woocommerce', $platforms);
        $this->assertContains('ecommerce_platform', $platforms);
        $this->assertContains('internal', $platforms);
    }

    /**
     * Test model integration credential passing and decrypted getters.
     */
    public function test_model_credential_encryption_and_decrypted_accessors(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'tiendanube',
            'name' => 'Fashion Store',
            'store_id' => '888123',
            'client_id' => 'client_app_id',
        ]);

        // Test encrypted secret setters
        $plainSecret = 'super_secret_token_xyz_123';
        $integration->setEncryptedSecret('access_token', $plainSecret);
        $integration->setEncryptedSecret('client_secret', 'my_client_secret_abc');
        $integration->setEncryptedSecret('refresh_token', 'refresh_token_789');
        $integration->setEncryptedSecret('webhook_secret', 'webhook_secret_hmac');

        // Confirm database raw attribute is encrypted
        $this->assertNotEquals($plainSecret, $integration->getAttributes()['access_token']);
        $this->assertEquals($plainSecret, Crypt::decryptString($integration->getAttributes()['access_token']));

        // Confirm decrypted accessors
        $this->assertEquals($plainSecret, $integration->decrypted_access_token);
        $this->assertEquals('my_client_secret_abc', $integration->decrypted_client_secret);
        $this->assertEquals('refresh_token_789', $integration->decrypted_refresh_token);
        $this->assertEquals('webhook_secret_hmac', $integration->decrypted_webhook_secret);

        // Confirm adapter correctly reads the decrypted secrets
        $adapter = $this->manager->driver('tiendanube', $integration);
        $this->assertEquals($plainSecret, $adapter->getAccessToken());
        $this->assertEquals('my_client_secret_abc', $adapter->getClientSecret());
        $this->assertEquals('refresh_token_789', $adapter->getRefreshToken());
        $this->assertEquals('webhook_secret_hmac', $adapter->getWebhookSecret());
        $this->assertEquals('888123', $adapter->getStoreId());
        $this->assertEquals('client_app_id', $adapter->getClientId());
    }

    /**
     * Test setEncryptedSecret protects against masked '****' token overwrites.
     */
    public function test_set_encrypted_secret_preserves_existing_secret_when_masked(): void
    {
        $integration = new EcommerceIntegration;
        $integration->setEncryptedSecret('access_token', 'initial_real_token');
        $encryptedFirst = $integration->getAttributes()['access_token'];

        // Attempt overwrite with masked string
        $integration->setEncryptedSecret('access_token', 'init****oken');
        $this->assertEquals($encryptedFirst, $integration->getAttributes()['access_token']);
        $this->assertEquals('initial_real_token', $integration->decrypted_access_token);
    }

    /**
     * Test maskSecret static utility method.
     */
    public function test_mask_secret_masks_appropriately(): void
    {
        $this->assertNull(EcommerceIntegration::maskSecret(null));
        $this->assertNull(EcommerceIntegration::maskSecret(''));
        $this->assertEquals('****', EcommerceIntegration::maskSecret('short'));
        $this->assertEquals('1234****7890', EcommerceIntegration::maskSecret('1234567890'));
        $this->assertEquals('APP_****a1b2', EcommerceIntegration::maskSecret('APP_USR_secret_a1b2'));
    }

    /**
     * Test circuit breaker helper methods on EcommerceIntegration model.
     */
    public function test_circuit_breaker_helper_methods(): void
    {
        $integration = new EcommerceIntegration([
            'consecutive_failures' => 0,
            'circuit_breaker_tripped_at' => null,
        ]);

        $this->assertFalse($integration->isCircuitBreakerTripped());

        $integration->circuit_breaker_tripped_at = now()->subMinutes(5);
        $this->assertTrue($integration->isCircuitBreakerTripped(15));
        $this->assertFalse($integration->isCircuitBreakerTripped(3));
    }

    /**
     * Test reflection check to confirm all concrete adapters implement all contract methods.
     */
    public function test_all_concrete_adapters_implement_contract_methods(): void
    {
        $contractClass = new ReflectionClass(EcommercePlatformInterface::class);
        $contractMethods = $contractClass->getMethods();

        $adapterClasses = [
            MercadoLibreAdapter::class,
            TiendaNubeAdapter::class,
            WooCommerceAdapter::class,
            InternalEcommercePlatformAdapter::class,
        ];

        foreach ($adapterClasses as $adapterClass) {
            $reflector = new ReflectionClass($adapterClass);
            $this->assertTrue(
                $reflector->implementsInterface(EcommercePlatformInterface::class),
                "{$adapterClass} must implement EcommercePlatformInterface"
            );

            foreach ($contractMethods as $contractMethod) {
                $methodName = $contractMethod->getName();
                $this->assertTrue(
                    $reflector->hasMethod($methodName),
                    "{$adapterClass} is missing method {$methodName}"
                );

                $concreteMethod = $reflector->getMethod($methodName);
                $this->assertFalse(
                    $concreteMethod->isAbstract(),
                    "Method {$methodName} in {$adapterClass} cannot be abstract"
                );
            }
        }
    }

    /**
     * Test fallback batchUpdateStock in AbstractEcommerceAdapter.
     */
    public function test_abstract_adapter_batch_update_stock_handles_success_and_failures(): void
    {
        $mock = new FakeBatchAdapter;

        $items = [
            ['external_product_id' => 'PROD_1', 'quantity' => 10.0],
            ['external_product_id' => 'FAIL_ITEM', 'quantity' => 5.0],
            ['external_product_id' => 'PROD_2', 'quantity' => 20.0, 'external_variant_id' => 'VAR_9'],
        ];

        $result = $mock->batchUpdateStock($items);

        $this->assertFalse($result['success']);
        $this->assertEquals(2, $result['updated_count']);
        $this->assertCount(1, $result['failed_items']);
        $this->assertEquals('FAIL_ITEM', $result['failed_items'][0]['external_product_id']);
    }

    /**
     * Test verifyWebhookSignature on MercadoLibreAdapter under valid, invalid, missing header, and fallback states.
     */
    public function test_mercadolibre_adapter_verify_webhook_signature(): void
    {
        $secret = 'meli_webhook_secret_key_123';
        $adapterWithSecret = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);

        $requestId = 'req_meli_test_9988';
        $ts = (string) time();
        $manifest = "id:{$requestId};request-id:{$requestId};ts:{$ts};";
        $validSignature = hash_hmac('sha256', $manifest, $secret);

        // 1. Valid HMAC signature with configured secret -> true
        $validRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
            'topic' => 'orders_v2',
        ]);
        $validRequest->headers->set('x-request-id', $requestId);
        $validRequest->headers->set('x-signature', "ts={$ts},v1={$validSignature}");
        $this->assertTrue($adapterWithSecret->verifyWebhookSignature($validRequest));

        // 2. Invalid HMAC signature with configured secret -> false
        $invalidRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
            'topic' => 'orders_v2',
        ]);
        $invalidRequest->headers->set('x-request-id', $requestId);
        $invalidRequest->headers->set('x-signature', "ts={$ts},v1=invalid_hmac_hex_code");
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($invalidRequest));

        // 3. Missing signature header with configured secret -> false (verifying fix against bypass)
        $missingHeaderRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
            'topic' => 'orders_v2',
        ]);
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($missingHeaderRequest));

        // 4. Missing request-id header with configured secret -> false
        $missingReqIdRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
            'topic' => 'orders_v2',
        ]);
        $missingReqIdRequest->headers->set('x-signature', "ts={$ts},v1={$validSignature}");
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($missingReqIdRequest));

        // 5. Structural validation fallback when secret is null/empty -> assert true if valid params, false if missing
        $adapterNoSecret = $this->manager->make('mercadolibre', ['webhook_secret' => null]);

        $validStructuralRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
            'topic' => 'orders_v2',
        ]);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($validStructuralRequest));

        $missingTopicRequest = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/987654321',
        ]);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($missingTopicRequest));

        $emptyRequest = Request::create('/api/webhook/mercadolibre', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($emptyRequest));
    }

    /**
     * Test verifyWebhookSignature on TiendaNubeAdapter under valid, invalid, missing header, and fallback states.
     */
    public function test_tiendanube_adapter_verify_webhook_signature(): void
    {
        $secret = 'nube_hmac_secret_456';
        $adapterWithSecret = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);

        $payload = json_encode(['event' => 'order/created', 'id' => 1234567]);
        $validHmac = hash_hmac('sha256', $payload, $secret);

        // 1. Valid HMAC signature with configured secret -> true
        $validRequest = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $payload);
        $validRequest->headers->set('x-linkedstore-hmac-sha256', $validHmac);
        $this->assertTrue($adapterWithSecret->verifyWebhookSignature($validRequest));

        // 2. Invalid HMAC signature with configured secret -> false
        $invalidRequest = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $payload);
        $invalidRequest->headers->set('x-linkedstore-hmac-sha256', 'forged_hmac_signature_value');
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($invalidRequest));

        // 3. Missing signature header with configured secret -> false (verifying fix against bypass)
        $missingHeaderRequest = Request::create('/api/webhook/tiendanube', 'POST', [
            'event' => 'order/created',
            'id' => 1234567,
        ], [], [], [], $payload);
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($missingHeaderRequest));

        // 4. Secret fallback to client_secret if webhook_secret is empty -> true with valid HMAC
        $adapterClientSecret = $this->manager->make('tiendanube', [
            'webhook_secret' => null,
            'client_secret' => 'fallback_client_sec_789',
        ]);
        $clientSecretHmac = hash_hmac('sha256', $payload, 'fallback_client_sec_789');
        $validClientSecRequest = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $payload);
        $validClientSecRequest->headers->set('x-linkedstore-hmac-sha256', $clientSecretHmac);
        $this->assertTrue($adapterClientSecret->verifyWebhookSignature($validClientSecRequest));

        // 5. Structural validation fallback when secret is null/empty -> assert true if valid params, false if missing
        $adapterNoSecret = $this->manager->make('tiendanube', [
            'webhook_secret' => null,
            'client_secret' => null,
        ]);

        $validStructuralRequest = Request::create('/api/webhook/tiendanube', 'POST', [
            'event' => 'order/created',
            'id' => 1234567,
        ]);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($validStructuralRequest));

        $missingIdRequest = Request::create('/api/webhook/tiendanube', 'POST', [
            'event' => 'order/created',
        ]);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($missingIdRequest));

        $emptyRequest = Request::create('/api/webhook/tiendanube', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($emptyRequest));
    }

    /**
     * Test verifyWebhookSignature on WooCommerceAdapter under valid, invalid, missing header, and fallback states.
     */
    public function test_woocommerce_adapter_verify_webhook_signature(): void
    {
        $secret = 'woo_hmac_secret_789';
        $adapterWithSecret = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);

        $payload = json_encode(['id' => 54321, 'status' => 'processing', 'total' => '2500.00']);
        $validSignature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        // 1. Valid HMAC signature with configured secret -> true
        $validRequest = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $payload);
        $validRequest->headers->set('x-wc-webhook-signature', $validSignature);
        $this->assertTrue($adapterWithSecret->verifyWebhookSignature($validRequest));

        // 2. Invalid HMAC signature with configured secret -> false
        $invalidRequest = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $payload);
        $invalidRequest->headers->set('x-wc-webhook-signature', base64_encode('invalid_hmac_data'));
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($invalidRequest));

        // 3. Missing signature header with configured secret -> false (verifying fix against bypass)
        $missingHeaderRequest = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $payload);
        $missingHeaderRequest->headers->set('x-wc-webhook-topic', 'order.updated');
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($missingHeaderRequest));

        // 4. Structural validation fallback when secret is null/empty -> assert true if valid headers, false if missing
        $adapterNoSecret = $this->manager->make('woocommerce', ['webhook_secret' => null]);

        $topicRequest = Request::create('/api/webhook/woocommerce', 'POST', []);
        $topicRequest->headers->set('x-wc-webhook-topic', 'order.created');
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($topicRequest));

        $resourceRequest = Request::create('/api/webhook/woocommerce', 'POST', []);
        $resourceRequest->headers->set('x-wc-webhook-resource', 'order');
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($resourceRequest));

        $emptyHeadersRequest = Request::create('/api/webhook/woocommerce', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($emptyHeadersRequest));
    }

    /**
     * Test verifyWebhookSignature on InternalEcommercePlatformAdapter under valid, invalid, missing header, and fallback states.
     */
    public function test_internal_ecommerce_platform_adapter_verify_webhook_signature(): void
    {
        $secret = 'internal_hmac_secret_321';
        $adapterWithSecret = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);

        $payload = json_encode(['event' => 'stock.sync', 'items' => [['id' => 1, 'stock' => 10]]]);
        $validSignature = hash_hmac('sha256', $payload, $secret);

        // 1. Valid HMAC signature with configured secret -> true
        $validRequest = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $payload);
        $validRequest->headers->set('x-signature', $validSignature);
        $this->assertTrue($adapterWithSecret->verifyWebhookSignature($validRequest));

        // 2. Invalid HMAC signature with configured secret -> false
        $invalidRequest = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $payload);
        $invalidRequest->headers->set('x-signature', 'forged_internal_signature_hash');
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($invalidRequest));

        // 3. Missing signature header with configured secret -> false (verifying fix against bypass)
        $missingHeaderRequest = Request::create('/api/webhook/internal', 'POST', [
            'event' => 'stock.sync',
        ], [], [], [], $payload);
        $this->assertFalse($adapterWithSecret->verifyWebhookSignature($missingHeaderRequest));

        // 4. Structural validation fallback when secret is null/empty -> assert true if valid params, false if missing
        $adapterNoSecret = $this->manager->make('ecommerce_platform', ['webhook_secret' => null]);

        $eventRequest = Request::create('/api/webhook/internal', 'POST', ['event' => 'order.created']);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($eventRequest));

        $eventTypeRequest = Request::create('/api/webhook/internal', 'POST', ['event_type' => 'order.created']);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($eventTypeRequest));

        $missingEventRequest = Request::create('/api/webhook/internal', 'POST', ['other_attribute' => 'value']);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($missingEventRequest));

        $emptyRequest = Request::create('/api/webhook/internal', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($emptyRequest));
    }

    /**
     * Test EcommerceIntegration Eloquent mutators encrypt sensitive credentials upon mass assignment.
     */
    public function test_ecommerce_integration_mutators_mass_assignment_encryption(): void
    {
        $plainAccessToken = 'oauth_access_token_plain_123';
        $plainClientSecret = 'oauth_client_secret_plain_456';
        $plainRefreshToken = 'oauth_refresh_token_plain_789';
        $plainWebhookSecret = 'webhook_signing_secret_plain_012';

        $integration = new EcommerceIntegration([
            'platform' => 'mercadolibre',
            'name' => 'MELI Store Argentina',
            'access_token' => $plainAccessToken,
            'client_secret' => $plainClientSecret,
            'refresh_token' => $plainRefreshToken,
            'webhook_secret' => $plainWebhookSecret,
        ]);

        $rawAttributes = $integration->getAttributes();

        // 1. Raw database attributes must NOT store plaintext credentials
        $this->assertNotEquals($plainAccessToken, $rawAttributes['access_token']);
        $this->assertNotEquals($plainClientSecret, $rawAttributes['client_secret']);
        $this->assertNotEquals($plainRefreshToken, $rawAttributes['refresh_token']);
        $this->assertNotEquals($plainWebhookSecret, $rawAttributes['webhook_secret']);

        // 2. Raw database attributes must be valid encrypted strings decryptable via Crypt
        $this->assertEquals($plainAccessToken, Crypt::decryptString($rawAttributes['access_token']));
        $this->assertEquals($plainClientSecret, Crypt::decryptString($rawAttributes['client_secret']));
        $this->assertEquals($plainRefreshToken, Crypt::decryptString($rawAttributes['refresh_token']));
        $this->assertEquals($plainWebhookSecret, Crypt::decryptString($rawAttributes['webhook_secret']));

        // 3. Decrypted model accessors must return the original plaintext values
        $this->assertEquals($plainAccessToken, $integration->decrypted_access_token);
        $this->assertEquals($plainClientSecret, $integration->decrypted_client_secret);
        $this->assertEquals($plainRefreshToken, $integration->decrypted_refresh_token);
        $this->assertEquals($plainWebhookSecret, $integration->decrypted_webhook_secret);

        // 4. Direct property assignment ($model->access_token = '...') also routes through mutator
        $directAssignmentModel = new EcommerceIntegration;
        $directAssignmentModel->access_token = 'direct_assigned_secret_value';
        $this->assertNotEquals('direct_assigned_secret_value', $directAssignmentModel->getAttributes()['access_token']);
        $this->assertEquals('direct_assigned_secret_value', Crypt::decryptString($directAssignmentModel->getAttributes()['access_token']));
        $this->assertEquals('direct_assigned_secret_value', $directAssignmentModel->decrypted_access_token);
    }

    /**
     * Test EcommerceIntegration Eloquent mutators preserve existing encrypted secrets when updated with masked values.
     */
    public function test_ecommerce_integration_mutators_preserve_existing_secret_when_masked(): void
    {
        $integration = new EcommerceIntegration([
            'access_token' => 'original_live_access_token_111',
            'client_secret' => 'original_live_client_secret_222',
            'refresh_token' => 'original_live_refresh_token_333',
            'webhook_secret' => 'original_live_webhook_secret_444',
        ]);

        $initialEncryptedAccess = $integration->getAttributes()['access_token'];
        $initialEncryptedClient = $integration->getAttributes()['client_secret'];
        $initialEncryptedRefresh = $integration->getAttributes()['refresh_token'];
        $initialEncryptedWebhook = $integration->getAttributes()['webhook_secret'];

        // Simulate an update payload where sensitive secrets are returned masked (e.g. 'orig****111' or '****')
        $integration->fill([
            'access_token' => 'orig****111',
            'client_secret' => '****',
            'refresh_token' => 'orig****333',
            'webhook_secret' => '****',
        ]);

        // 1. Raw stored ciphertext must remain completely unchanged
        $this->assertSame($initialEncryptedAccess, $integration->getAttributes()['access_token']);
        $this->assertSame($initialEncryptedClient, $integration->getAttributes()['client_secret']);
        $this->assertSame($initialEncryptedRefresh, $integration->getAttributes()['refresh_token']);
        $this->assertSame($initialEncryptedWebhook, $integration->getAttributes()['webhook_secret']);

        // 2. Decrypted accessors must still yield original plaintext values
        $this->assertEquals('original_live_access_token_111', $integration->decrypted_access_token);
        $this->assertEquals('original_live_client_secret_222', $integration->decrypted_client_secret);
        $this->assertEquals('original_live_refresh_token_333', $integration->decrypted_refresh_token);
        $this->assertEquals('original_live_webhook_secret_444', $integration->decrypted_webhook_secret);

        // 3. Passing null or empty string should allow explicitly clearing the secret
        $integration->fill([
            'access_token' => null,
            'client_secret' => '',
        ]);
        $this->assertNull($integration->getAttributes()['access_token']);
        $this->assertNull($integration->getAttributes()['client_secret']);
        $this->assertNull($integration->decrypted_access_token);
        $this->assertNull($integration->decrypted_client_secret);
        // Untouched attributes remain preserved
        $this->assertSame($initialEncryptedRefresh, $integration->getAttributes()['refresh_token']);
        $this->assertSame($initialEncryptedWebhook, $integration->getAttributes()['webhook_secret']);
    }
}

/**
 * Mock adapter for testing custom platform extensions.
 */
class FakeShopifyAdapter extends AbstractEcommerceAdapter
{
    public function getPlatformName(): string
    {
        return 'custom_shopify';
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'Connected'];
    }

    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        return true;
    }

    public function getOrder(string $externalOrderId): array
    {
        return ['id' => $externalOrderId];
    }

    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'event_type' => 'order',
            'external_event_id' => '1',
            'external_order_id' => '1',
            'action' => 'test',
            'payload' => [],
        ];
    }
}

/**
 * Mock adapter for testing AbstractEcommerceAdapter batchUpdateStock fallback.
 */
class FakeBatchAdapter extends AbstractEcommerceAdapter
{
    public function getPlatformName(): string
    {
        return 'test_batch';
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function updateStock(string $externalProductId, float $quantity, ?string $externalVariantId = null): bool
    {
        return $externalProductId !== 'FAIL_ITEM';
    }

    public function getOrder(string $externalOrderId): array
    {
        return [];
    }

    public function cancelOrder(string $externalOrderId, ?string $reason = null): bool
    {
        return true;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'event_type' => '',
            'external_event_id' => '',
            'external_order_id' => null,
            'action' => '',
            'payload' => [],
        ];
    }
}
