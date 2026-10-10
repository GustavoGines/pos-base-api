<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\EcommerceManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EcommerceWebhookAdversarialStressTest extends TestCase
{
    use RefreshDatabase;

    protected EcommerceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(EcommerceManager::class);
    }

    /**
     * =========================================================================
     * 1. MERCADOLIBRE ADAPTER ADVERSARIAL MATRIX
     * =========================================================================
     */
    public function test_mercadolibre_valid_signature_returns_true(): void
    {
        $secret = 'meli_secret_alpha_99';
        $adapter = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);

        $requestId = 'req_meli_guid_12345';
        $ts = (string) time();
        $manifest = "id:{$requestId};request-id:{$requestId};ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, $secret);

        $request = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/776655',
            'topic' => 'orders_v2',
        ]);
        $request->headers->set('x-request-id', $requestId);
        $request->headers->set('x-signature', "ts={$ts},v1={$signature}");

        $this->assertTrue($adapter->verifyWebhookSignature($request));
    }

    public function test_mercadolibre_forged_missing_signature_returns_false_when_secret_configured(): void
    {
        $secret = 'meli_secret_alpha_99';
        $adapter = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);

        // Attacker sends request with valid structural payload and x-request-id but no signature
        $request = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/776655',
            'topic' => 'orders_v2',
        ]);
        $request->headers->set('x-request-id', 'req_forged_001');

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'MercadoLibre must reject request missing x-signature header when secret is configured'
        );
    }

    public function test_mercadolibre_missing_request_id_returns_false(): void
    {
        $secret = 'meli_secret_alpha_99';
        $adapter = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);

        $ts = (string) time();
        $request = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/776655',
            'topic' => 'orders_v2',
        ]);
        $request->headers->set('x-signature', "ts={$ts},v1=somehash");

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'MercadoLibre must reject request missing x-request-id header when secret is configured'
        );
    }

    public function test_mercadolibre_tampered_signature_returns_false(): void
    {
        $secret = 'meli_secret_alpha_99';
        $adapter = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);

        $requestId = 'req_meli_guid_12345';
        $ts = (string) time();
        $manifest = "id:{$requestId};request-id:{$requestId};ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, $secret);

        // Flip 1 character in the signature
        $tamperedSig = substr($signature, 0, -1).($signature[-1] === 'a' ? 'b' : 'a');

        $request = Request::create('/api/webhook/mercadolibre', 'POST', [
            'resource' => '/orders/776655',
            'topic' => 'orders_v2',
        ]);
        $request->headers->set('x-request-id', $requestId);
        $request->headers->set('x-signature', "ts={$ts},v1={$tamperedSig}");

        $this->assertFalse($adapter->verifyWebhookSignature($request));
    }

    public function test_mercadolibre_malformed_signature_header_formats(): void
    {
        $secret = 'meli_secret_alpha_99';
        $adapter = $this->manager->make('mercadolibre', ['webhook_secret' => $secret]);
        $requestId = 'req_meli_guid_12345';

        $malformedHeaders = [
            '',                                         // Empty string
            'random_garbage_string',                    // No key-values
            'ts=12345678',                              // Missing v1
            'v1=sha256hash',                            // Missing ts
            'ts=,v1=',                                  // Empty values
            'invalid=foo,bar=baz',                      // Wrong keys
            'ts=123,v1=abc,extra=param',                // Extra keys with invalid v1
        ];

        foreach ($malformedHeaders as $header) {
            $request = Request::create('/api/webhook/mercadolibre', 'POST', [
                'resource' => '/orders/776655',
                'topic' => 'orders_v2',
            ]);
            $request->headers->set('x-request-id', $requestId);
            $request->headers->set('x-signature', $header);

            $this->assertFalse(
                $adapter->verifyWebhookSignature($request),
                "MercadoLibre must reject malformed header: [{$header}]"
            );
        }
    }

    public function test_mercadolibre_structural_fallback_only_operates_when_secret_is_empty(): void
    {
        // When secret is null/empty
        $adapterEmptySecret = $this->manager->make('mercadolibre', ['webhook_secret' => '']);
        $adapterNullSecret = $this->manager->make('mercadolibre', ['webhook_secret' => null]);

        $validPayload = ['resource' => '/orders/776655', 'topic' => 'orders_v2'];
        $invalidPayload1 = ['resource' => '/orders/776655']; // missing topic
        $invalidPayload2 = ['topic' => 'orders_v2'];       // missing resource
        $emptyPayload = [];

        // Valid structural -> true
        $reqValid = Request::create('/api/webhook/mercadolibre', 'POST', $validPayload);
        $this->assertTrue($adapterEmptySecret->verifyWebhookSignature($reqValid));
        $this->assertTrue($adapterNullSecret->verifyWebhookSignature($reqValid));

        // Incomplete structural -> false
        $this->assertFalse($adapterEmptySecret->verifyWebhookSignature(Request::create('/api/webhook/mercadolibre', 'POST', $invalidPayload1)));
        $this->assertFalse($adapterEmptySecret->verifyWebhookSignature(Request::create('/api/webhook/mercadolibre', 'POST', $invalidPayload2)));
        $this->assertFalse($adapterEmptySecret->verifyWebhookSignature(Request::create('/api/webhook/mercadolibre', 'POST', $emptyPayload)));

        // NOW CRUCIAL: Configure secret and send valid structural payload without signatures -> MUST BE FALSE
        $adapterWithSecret = $this->manager->make('mercadolibre', ['webhook_secret' => 'configured_secret']);
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature($reqValid),
            'Fallback must NEVER operate when secret is configured!'
        );
    }

    /**
     * =========================================================================
     * 2. TIENDANUBE ADAPTER ADVERSARIAL MATRIX
     * =========================================================================
     */
    public function test_tiendanube_valid_signature_returns_true(): void
    {
        $secret = 'nube_secret_beta_88';
        $adapter = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);

        $body = json_encode(['event' => 'order/created', 'id' => 889900]);
        $signature = hash_hmac('sha256', $body, $secret);

        $request = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $body);
        $request->headers->set('x-linkedstore-hmac-sha256', $signature);

        $this->assertTrue($adapter->verifyWebhookSignature($request));

        // Also test uppercase header variant
        $requestUpper = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $body);
        $requestUpper->headers->set('X-LinkedStore-HMAC-SHA256', $signature);
        $this->assertTrue($adapter->verifyWebhookSignature($requestUpper));
    }

    public function test_tiendanube_forged_missing_signature_returns_false_when_secret_configured(): void
    {
        $secret = 'nube_secret_beta_88';
        $adapter = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);

        // Valid structural body, but NO signature header
        $body = json_encode(['event' => 'order/created', 'id' => 889900]);
        $request = Request::create('/api/webhook/tiendanube', 'POST', ['event' => 'order/created', 'id' => 889900], [], [], [], $body);

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'TiendaNube must reject request missing x-linkedstore-hmac-sha256 header when secret is configured'
        );
    }

    public function test_tiendanube_tampered_request_body_returns_false(): void
    {
        $secret = 'nube_secret_beta_88';
        $adapter = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);

        $originalBody = json_encode(['event' => 'order/paid', 'id' => 100, 'total' => '100.00']);
        $signature = hash_hmac('sha256', $originalBody, $secret);

        // Attacker tampers body to change amount or order ID while replaying old signature
        $tamperedBody = json_encode(['event' => 'order/paid', 'id' => 100, 'total' => '9999.00']);

        $request = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $tamperedBody);
        $request->headers->set('x-linkedstore-hmac-sha256', $signature);

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'TiendaNube must reject tampered body'
        );
    }

    public function test_tiendanube_tampered_signature_returns_false(): void
    {
        $secret = 'nube_secret_beta_88';
        $adapter = $this->manager->make('tiendanube', ['webhook_secret' => $secret]);

        $body = json_encode(['event' => 'order/created', 'id' => 889900]);
        $signature = hash_hmac('sha256', $body, $secret);
        $tamperedSignature = substr($signature, 0, -2).'ff';

        $request = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $body);
        $request->headers->set('x-linkedstore-hmac-sha256', $tamperedSignature);

        $this->assertFalse($adapter->verifyWebhookSignature($request));
    }

    public function test_tiendanube_fallback_to_client_secret_enforces_strict_verification(): void
    {
        // When webhook_secret is null, TiendaNube falls back to client_secret
        $adapter = $this->manager->make('tiendanube', [
            'webhook_secret' => null,
            'client_secret' => 'fallback_client_key_33',
        ]);

        $body = json_encode(['event' => 'order/created', 'id' => 555]);
        $validSig = hash_hmac('sha256', $body, 'fallback_client_key_33');

        // Valid signature with fallback secret -> true
        $reqValid = Request::create('/api/webhook/tiendanube', 'POST', [], [], [], [], $body);
        $reqValid->headers->set('x-linkedstore-hmac-sha256', $validSig);
        $this->assertTrue($adapter->verifyWebhookSignature($reqValid));

        // Missing signature -> must be false (NOT structural fallback!)
        $reqMissingSig = Request::create('/api/webhook/tiendanube', 'POST', ['event' => 'order/created', 'id' => 555], [], [], [], $body);
        $this->assertFalse(
            $adapter->verifyWebhookSignature($reqMissingSig),
            'TiendaNube with fallback client_secret must reject missing signature'
        );
    }

    public function test_tiendanube_structural_fallback_only_operates_when_both_secrets_empty(): void
    {
        $adapterNoSecrets = $this->manager->make('tiendanube', [
            'webhook_secret' => null,
            'client_secret' => null,
        ]);

        $validPayload = ['event' => 'order/created', 'id' => 1234];
        $invalidPayload = ['event' => 'order/created']; // missing id

        $this->assertTrue($adapterNoSecrets->verifyWebhookSignature(Request::create('/api/webhook/tiendanube', 'POST', $validPayload)));
        $this->assertFalse($adapterNoSecrets->verifyWebhookSignature(Request::create('/api/webhook/tiendanube', 'POST', $invalidPayload)));
        $this->assertFalse($adapterNoSecrets->verifyWebhookSignature(Request::create('/api/webhook/tiendanube', 'POST', [])));

        // Configured secret must NOT allow structural fallback
        $adapterWithSecret = $this->manager->make('tiendanube', ['webhook_secret' => 'configured_secret']);
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature(Request::create('/api/webhook/tiendanube', 'POST', $validPayload)),
            'Fallback must NEVER operate when secret is configured!'
        );
    }

    /**
     * =========================================================================
     * 3. WOOCOMMERCE ADAPTER ADVERSARIAL MATRIX
     * =========================================================================
     */
    public function test_woocommerce_valid_signature_returns_true(): void
    {
        $secret = 'woo_secret_gamma_77';
        $adapter = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);

        $body = json_encode(['id' => 4433, 'status' => 'completed', 'total' => '120.50']);
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $request = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $body);
        $request->headers->set('x-wc-webhook-signature', $signature);

        $this->assertTrue($adapter->verifyWebhookSignature($request));

        // Also test uppercase header variant
        $requestUpper = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $body);
        $requestUpper->headers->set('X-WC-Webhook-Signature', $signature);
        $this->assertTrue($adapter->verifyWebhookSignature($requestUpper));
    }

    public function test_woocommerce_forged_missing_signature_returns_false_when_secret_configured(): void
    {
        $secret = 'woo_secret_gamma_77';
        $adapter = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);

        // Attacker provides valid WooCommerce headers (x-wc-webhook-topic) but omits signature
        $body = json_encode(['id' => 4433, 'status' => 'completed']);
        $request = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $body);
        $request->headers->set('x-wc-webhook-topic', 'order.completed');
        $request->headers->set('x-wc-webhook-resource', 'order');

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'WooCommerce must reject request missing x-wc-webhook-signature header when secret is configured'
        );
    }

    public function test_woocommerce_tampered_request_body_returns_false(): void
    {
        $secret = 'woo_secret_gamma_77';
        $adapter = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);

        $originalBody = json_encode(['id' => 4433, 'status' => 'completed', 'total' => '120.50']);
        $signature = base64_encode(hash_hmac('sha256', $originalBody, $secret, true));

        $tamperedBody = json_encode(['id' => 4433, 'status' => 'completed', 'total' => '9999.50']);

        $request = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $tamperedBody);
        $request->headers->set('x-wc-webhook-signature', $signature);

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'WooCommerce must reject tampered body'
        );
    }

    public function test_woocommerce_tampered_signature_returns_false(): void
    {
        $secret = 'woo_secret_gamma_77';
        $adapter = $this->manager->make('woocommerce', ['webhook_secret' => $secret]);

        $body = json_encode(['id' => 4433]);
        $tamperedSignatures = [
            'not_base64_hash_at_all',
            base64_encode('invalid_binary_digest'),
            base64_encode(hash_hmac('sha256', $body, 'wrong_secret', true)),
            '',
        ];

        foreach ($tamperedSignatures as $badSig) {
            $request = Request::create('/api/webhook/woocommerce', 'POST', [], [], [], [], $body);
            if ($badSig !== '') {
                $request->headers->set('x-wc-webhook-signature', $badSig);
            }

            $this->assertFalse(
                $adapter->verifyWebhookSignature($request),
                "WooCommerce must reject bad signature: [{$badSig}]"
            );
        }
    }

    public function test_woocommerce_structural_fallback_only_operates_when_secret_is_empty(): void
    {
        $adapterNoSecret = $this->manager->make('woocommerce', ['webhook_secret' => null]);

        $reqTopic = Request::create('/api/webhook/woocommerce', 'POST', []);
        $reqTopic->headers->set('x-wc-webhook-topic', 'order.created');
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($reqTopic));

        $reqResource = Request::create('/api/webhook/woocommerce', 'POST', []);
        $reqResource->headers->set('x-wc-webhook-resource', 'order');
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($reqResource));

        $reqEmpty = Request::create('/api/webhook/woocommerce', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($reqEmpty));

        // When secret is configured, headers that satisfy fallback MUST NOT grant access!
        $adapterWithSecret = $this->manager->make('woocommerce', ['webhook_secret' => 'configured_secret']);
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature($reqTopic),
            'Fallback must NEVER operate when secret is configured!'
        );
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature($reqResource),
            'Fallback must NEVER operate when secret is configured!'
        );
    }

    /**
     * =========================================================================
     * 4. INTERNAL ECOMMERCE PLATFORM ADAPTER ADVERSARIAL MATRIX
     * =========================================================================
     */
    public function test_internal_platform_valid_signature_returns_true(): void
    {
        $secret = 'internal_secret_delta_66';
        $adapter = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);

        $body = json_encode(['event' => 'stock.sync', 'product_id' => 12]);
        $signature = hash_hmac('sha256', $body, $secret);

        $request = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $body);
        $request->headers->set('x-signature', $signature);

        $this->assertTrue($adapter->verifyWebhookSignature($request));

        // Also test uppercase header variant
        $requestUpper = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $body);
        $requestUpper->headers->set('X-Signature', $signature);
        $this->assertTrue($adapter->verifyWebhookSignature($requestUpper));
    }

    public function test_internal_platform_forged_missing_signature_returns_false_when_secret_configured(): void
    {
        $secret = 'internal_secret_delta_66';
        $adapter = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);

        // Attacker sends request with 'event' payload, satisfying fallback structure, but no signature
        $body = json_encode(['event' => 'stock.sync', 'product_id' => 12]);
        $request = Request::create('/api/webhook/internal', 'POST', ['event' => 'stock.sync'], [], [], [], $body);

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'Internal platform must reject request missing x-signature header when secret is configured'
        );
    }

    public function test_internal_platform_tampered_request_body_returns_false(): void
    {
        $secret = 'internal_secret_delta_66';
        $adapter = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);

        $originalBody = json_encode(['event' => 'stock.sync', 'product_id' => 12]);
        $signature = hash_hmac('sha256', $originalBody, $secret);

        $tamperedBody = json_encode(['event' => 'stock.sync', 'product_id' => 999]);

        $request = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $tamperedBody);
        $request->headers->set('x-signature', $signature);

        $this->assertFalse(
            $adapter->verifyWebhookSignature($request),
            'Internal platform must reject tampered body'
        );
    }

    public function test_internal_platform_tampered_signature_returns_false(): void
    {
        $secret = 'internal_secret_delta_66';
        $adapter = $this->manager->make('ecommerce_platform', ['webhook_secret' => $secret]);

        $body = json_encode(['event' => 'stock.sync', 'product_id' => 12]);
        $badSignatures = [
            'forged_hex_signature',
            hash_hmac('sha256', $body, 'wrong_key'),
            '',
        ];

        foreach ($badSignatures as $badSig) {
            $request = Request::create('/api/webhook/internal', 'POST', [], [], [], [], $body);
            if ($badSig !== '') {
                $request->headers->set('x-signature', $badSig);
            }

            $this->assertFalse(
                $adapter->verifyWebhookSignature($request),
                "Internal platform must reject bad signature: [{$badSig}]"
            );
        }
    }

    public function test_internal_platform_structural_fallback_only_operates_when_secret_is_empty(): void
    {
        $adapterNoSecret = $this->manager->make('ecommerce_platform', ['webhook_secret' => null]);

        $reqEvent = Request::create('/api/webhook/internal', 'POST', ['event' => 'stock.sync']);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($reqEvent));

        $reqEventType = Request::create('/api/webhook/internal', 'POST', ['event_type' => 'stock.sync']);
        $this->assertTrue($adapterNoSecret->verifyWebhookSignature($reqEventType));

        $reqEmpty = Request::create('/api/webhook/internal', 'POST', []);
        $this->assertFalse($adapterNoSecret->verifyWebhookSignature($reqEmpty));

        // When secret is configured, fallback must NEVER pass without valid signature
        $adapterWithSecret = $this->manager->make('ecommerce_platform', ['webhook_secret' => 'configured_secret']);
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature($reqEvent),
            'Fallback must NEVER operate when secret is configured!'
        );
        $this->assertFalse(
            $adapterWithSecret->verifyWebhookSignature($reqEventType),
            'Fallback must NEVER operate when secret is configured!'
        );
    }

    /**
     * =========================================================================
     * 5. ECOMMERCE INTEGRATION MUTATORS & ENCRYPTION ADVERSARIAL INTEGRATION
     * =========================================================================
     */
    public function test_eloquent_mutators_guarantee_encryption_under_all_mass_assignment_methods(): void
    {
        $payload = [
            'platform' => 'mercadolibre',
            'name' => 'Store 1',
            'client_secret' => 'secret_raw_123',
            'access_token' => 'token_raw_456',
            'refresh_token' => 'refresh_raw_789',
            'webhook_secret' => 'whsec_raw_012',
        ];

        // 1. Constructor mass assignment
        $model1 = new EcommerceIntegration($payload);
        $this->assertStringStartsWith('eyJ', $model1->getAttributes()['client_secret']);
        $this->assertNotEquals('secret_raw_123', $model1->getAttributes()['client_secret']);
        $this->assertEquals('secret_raw_123', $model1->decrypted_client_secret);
        $this->assertEquals('token_raw_456', $model1->decrypted_access_token);
        $this->assertEquals('refresh_raw_789', $model1->decrypted_refresh_token);
        $this->assertEquals('whsec_raw_012', $model1->decrypted_webhook_secret);

        // 2. fill() method
        $model2 = new EcommerceIntegration;
        $model2->fill($payload);
        $this->assertStringStartsWith('eyJ', $model2->getAttributes()['client_secret']);
        $this->assertEquals('secret_raw_123', $model2->decrypted_client_secret);

        // 3. Database persistence and retrieval
        $model1->save();
        $fresh = EcommerceIntegration::findOrFail($model1->id);
        $this->assertNotEquals('secret_raw_123', $fresh->getAttributes()['client_secret']);
        $this->assertEquals('secret_raw_123', $fresh->decrypted_client_secret);

        // 4. Update with masked secret should NOT overwrite with asterisks
        $fresh->update([
            'client_secret' => 'secr****_123',
            'access_token' => 'toke****_456',
        ]);
        $fresh->refresh();
        $this->assertEquals('secret_raw_123', $fresh->decrypted_client_secret);
        $this->assertEquals('token_raw_456', $fresh->decrypted_access_token);

        // 5. Update with genuinely new plaintext replaces secret
        $fresh->update([
            'client_secret' => 'brand_new_secret_999',
        ]);
        $fresh->refresh();
        $this->assertEquals('brand_new_secret_999', $fresh->decrypted_client_secret);

        // 6. Update with null clears the secret
        $fresh->update([
            'client_secret' => null,
        ]);
        $fresh->refresh();
        $this->assertNull($fresh->getAttributes()['client_secret']);
        $this->assertNull($fresh->decrypted_client_secret);
    }

    /**
     * =========================================================================
     * 6. CIRCUIT BREAKER IMMUTABILITY STRESS TEST
     * =========================================================================
     */
    public function test_circuit_breaker_date_mutation_stress_test(): void
    {
        $trippedTime = Carbon::parse('2026-10-10 12:00:00');
        $integration = new EcommerceIntegration([
            'platform' => 'mercadolibre',
            'name' => 'Store CB',
            'circuit_breaker_tripped_at' => $trippedTime,
        ]);

        $initialString = (string) $integration->circuit_breaker_tripped_at;

        // Call isCircuitBreakerTripped 50 times in a loop
        for ($i = 0; $i < 50; $i++) {
            $integration->isCircuitBreakerTripped(15);
        }

        // Verify circuit_breaker_tripped_at was NOT mutated in-place
        $this->assertEquals($initialString, (string) $integration->circuit_breaker_tripped_at);
        $this->assertEquals('2026-10-10 12:00:00', $integration->circuit_breaker_tripped_at->format('Y-m-d H:i:s'));
    }
}
