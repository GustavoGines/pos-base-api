<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use App\Models\User;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceIntegrationChallengerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a base integration for testing.
     */
    protected function createBaseIntegration(array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => 'mercadolibre',
            'name' => 'Base Mercado Libre Shop',
            'client_id' => 'APP_CHALLENGER_01',
            'client_secret' => 'challenge_secret_key_999',
            'access_token' => 'APP_USR-challenger-token-111',
            'refresh_token' => 'TG-challenger-refresh-222',
            'webhook_secret' => 'whsec_challenger_secret_333',
            'is_active' => true,
            'sync_stock_enabled' => true,
            'sync_price_enabled' => false,
            'rate_limit_per_minute' => 60,
            'batch_size' => 50,
            'timeout_seconds' => 30,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. ADVERSARIAL STRESS: DUPLICATE ['platform', 'store_id'] HANDLING
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_create_duplicate_platform_and_store_id(): void
    {
        $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => 'STORE-ALPHA-100',
            'name' => 'Tienda Nube Original',
        ]);

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'store_id' => 'STORE-ALPHA-100',
            'name' => 'Tienda Nube Colisionante',
            'access_token' => 'plain_tn_token_999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_same_store_id_is_permitted_across_distinct_platforms(): void
    {
        $sharedStoreId = 'CROSS-PLATFORM-STORE-999';

        // 1. Tienda Nube
        $r1 = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'store_id' => $sharedStoreId,
            'name' => 'Canal Tienda Nube',
        ]);
        $r1->assertStatus(201);

        // 2. WooCommerce
        $r2 = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'woocommerce',
            'store_id' => $sharedStoreId,
            'name' => 'Canal WooCommerce',
            'base_url' => 'https://shop.example.com',
        ]);
        $r2->assertStatus(201);

        // 3. Plataforma Propia
        $r3 = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'ecommerce_platform',
            'store_id' => $sharedStoreId,
            'name' => 'Canal Interno',
        ]);
        $r3->assertStatus(201);

        $this->assertEquals(
            3,
            DB::table('ecommerce_integrations')->where('store_id', $sharedStoreId)->count()
        );
    }

    public function test_update_cannot_collide_with_another_store_id_on_same_platform(): void
    {
        $storeA = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => 'TN-STORE-001',
            'name' => 'Tienda A',
        ]);

        $storeB = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => 'TN-STORE-002',
            'name' => 'Tienda B',
        ]);

        // Attempt to change store B's store_id to match store A
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$storeB->id}", [
            'store_id' => 'TN-STORE-001',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_update_can_retain_its_own_store_id_without_unique_conflict(): void
    {
        $store = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => 'TN-STORE-SELF',
            'name' => 'Nombre Previo',
        ]);

        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$store->id}", [
            'name' => 'Nombre Actualizado Exitosamente',
            'store_id' => 'TN-STORE-SELF',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('integration.name', 'Nombre Actualizado Exitosamente');
        $response->assertJsonPath('integration.store_id', 'TN-STORE-SELF');
    }

    public function test_update_changing_platform_triggers_unique_conflict_if_target_platform_has_same_store_id(): void
    {
        // WooCommerce with STORE-SHARED
        $this->createBaseIntegration([
            'platform' => 'woocommerce',
            'store_id' => 'STORE-SHARED',
            'base_url' => 'https://woo-shop.com',
            'name' => 'Woo Existente',
        ]);

        // TiendaNube with STORE-SHARED
        $tn = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => 'STORE-SHARED',
            'name' => 'TN Existente',
        ]);

        // Attempt to change TN platform to woocommerce, keeping same store_id
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$tn->id}", [
            'platform' => 'woocommerce',
            'store_id' => 'STORE-SHARED',
            'base_url' => 'https://new-woo.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_multiple_integrations_with_null_store_id_are_permitted(): void
    {
        $r1 = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Tienda MELI 1',
            'store_id' => null,
        ]);
        $r1->assertStatus(201);

        $r2 = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Tienda MELI 2',
            'store_id' => null,
        ]);
        $r2->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. ADVERSARIAL STRESS: PLATFORM CONDITIONAL RULES
    // ─────────────────────────────────────────────────────────────────────────

    public function test_woocommerce_missing_base_url_fails_validation(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'woocommerce',
            'name' => 'WooCommerce Without URL',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['base_url']);
    }

    public function test_woocommerce_invalid_base_url_fails_validation(): void
    {
        $invalidUrls = [
            'not-a-valid-url',
            'htp://broken-scheme',
            'javascript:alert(1)',
            'www.missing-protocol.com',
        ];

        foreach ($invalidUrls as $badUrl) {
            $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
                'platform' => 'woocommerce',
                'name' => 'WooCommerce Bad URL Test',
                'base_url' => $badUrl,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['base_url']);
        }
    }

    public function test_woocommerce_valid_base_url_passes_validation(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'woocommerce',
            'name' => 'WooCommerce Good URL',
            'base_url' => 'https://secure-shop.example.com/store',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.base_url', 'https://secure-shop.example.com/store');
    }

    public function test_tiendanube_missing_store_id_fails_validation(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'name' => 'TiendaNube Without store_id',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_tiendanube_empty_or_whitespace_store_id_fails_validation(): void
    {
        $responseEmpty = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'name' => 'TiendaNube Empty store_id',
            'store_id' => '',
        ]);
        $responseEmpty->assertStatus(422);
        $responseEmpty->assertJsonValidationErrors(['store_id']);

        $responseNull = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'name' => 'TiendaNube Null store_id',
            'store_id' => null,
        ]);
        $responseNull->assertStatus(422);
        $responseNull->assertJsonValidationErrors(['store_id']);
    }

    public function test_mercadolibre_does_not_require_base_url_or_store_id(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'MercadoLibre Simple',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.platform', 'mercadolibre');
        $response->assertJsonPath('integration.base_url', null);
        $response->assertJsonPath('integration.store_id', null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. ADVERSARIAL STRESS: BOUNDARY VALUES FOR NUMERIC SETTINGS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_boundary_rate_limit_per_minute(): void
    {
        // Exact lower bound: 1 -> PASS
        $rMin = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Min Rate Limit Test',
            'rate_limit_per_minute' => 1,
        ]);
        $rMin->assertStatus(201);
        $rMin->assertJsonPath('integration.rate_limit_per_minute', 1);

        // Below lower bound: 0 -> 422
        $rBelow = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Below Min Rate Limit Test',
            'rate_limit_per_minute' => 0,
        ]);
        $rBelow->assertStatus(422);
        $rBelow->assertJsonValidationErrors(['rate_limit_per_minute']);

        // Negative value: -50 -> 422
        $rNeg = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Negative Rate Limit Test',
            'rate_limit_per_minute' => -50,
        ]);
        $rNeg->assertStatus(422);
        $rNeg->assertJsonValidationErrors(['rate_limit_per_minute']);

        // Exact upper bound: 600 -> PASS
        $rMax = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Max Rate Limit Test',
            'rate_limit_per_minute' => 600,
        ]);
        $rMax->assertStatus(201);
        $rMax->assertJsonPath('integration.rate_limit_per_minute', 600);

        // Above upper bound: 601 -> 422
        $rAbove = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Above Max Rate Limit Test',
            'rate_limit_per_minute' => 601,
        ]);
        $rAbove->assertStatus(422);
        $rAbove->assertJsonValidationErrors(['rate_limit_per_minute']);
    }

    public function test_boundary_batch_size(): void
    {
        // Exact lower bound: 1 -> PASS
        $rMin = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Min Batch Size Test',
            'batch_size' => 1,
        ]);
        $rMin->assertStatus(201);
        $rMin->assertJsonPath('integration.batch_size', 1);

        // Below lower bound: 0 -> 422
        $rBelow = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Below Min Batch Size Test',
            'batch_size' => 0,
        ]);
        $rBelow->assertStatus(422);
        $rBelow->assertJsonValidationErrors(['batch_size']);

        // Exact upper bound: 200 -> PASS
        $rMax = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Max Batch Size Test',
            'batch_size' => 200,
        ]);
        $rMax->assertStatus(201);
        $rMax->assertJsonPath('integration.batch_size', 200);

        // Above upper bound: 201 -> 422
        $rAbove = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Above Max Batch Size Test',
            'batch_size' => 201,
        ]);
        $rAbove->assertStatus(422);
        $rAbove->assertJsonValidationErrors(['batch_size']);
    }

    public function test_boundary_timeout_seconds(): void
    {
        // Exact lower bound: 5 -> PASS
        $rMin = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Min Timeout Test',
            'timeout_seconds' => 5,
        ]);
        $rMin->assertStatus(201);
        $rMin->assertJsonPath('integration.timeout_seconds', 5);

        // Below lower bound: 4 -> 422
        $rBelow = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Below Min Timeout Test',
            'timeout_seconds' => 4,
        ]);
        $rBelow->assertStatus(422);
        $rBelow->assertJsonValidationErrors(['timeout_seconds']);

        // Zero: 0 -> 422
        $rZero = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Zero Timeout Test',
            'timeout_seconds' => 0,
        ]);
        $rZero->assertStatus(422);
        $rZero->assertJsonValidationErrors(['timeout_seconds']);

        // Exact upper bound: 120 -> PASS
        $rMax = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Max Timeout Test',
            'timeout_seconds' => 120,
        ]);
        $rMax->assertStatus(201);
        $rMax->assertJsonPath('integration.timeout_seconds', 120);

        // Above upper bound: 121 -> 422
        $rAbove = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Above Max Timeout Test',
            'timeout_seconds' => 121,
        ]);
        $rAbove->assertStatus(422);
        $rAbove->assertJsonValidationErrors(['timeout_seconds']);
    }

    public function test_boundary_values_enforced_on_update(): void
    {
        $integration = $this->createBaseIntegration();

        $responseInvalid = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'rate_limit_per_minute' => 601,
            'batch_size' => 0,
            'timeout_seconds' => 4,
        ]);

        $responseInvalid->assertStatus(422);
        $responseInvalid->assertJsonValidationErrors([
            'rate_limit_per_minute',
            'batch_size',
            'timeout_seconds',
        ]);

        $responseValid = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'rate_limit_per_minute' => 600,
            'batch_size' => 200,
            'timeout_seconds' => 120,
        ]);

        $responseValid->assertStatus(200);
        $responseValid->assertJsonPath('integration.rate_limit_per_minute', 600);
        $responseValid->assertJsonPath('integration.batch_size', 200);
        $responseValid->assertJsonPath('integration.timeout_seconds', 120);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. ADVERSARIAL STRESS: TEST-CONNECTION ENDPOINT BEHAVIOR
    // ─────────────────────────────────────────────────────────────────────────

    public function test_test_connection_succeeds_when_remote_api_responds_ok(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-valid-meli-credentials',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 12345678,
                'nickname' => 'MELI_TEST_SELLER',
                'site_id' => 'MLA',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Conexión exitosa con Mercado Libre');
        $response->assertJsonPath('store_info.nickname', 'MELI_TEST_SELLER');

        $integration->refresh();
        $this->assertEquals('connected', $integration->last_health_status);
        $this->assertNotNull($integration->last_health_check_at);
    }

    public function test_test_connection_fails_cleanly_when_remote_api_returns_401(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-expired-or-revoked-token',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'message' => 'Token has expired or is invalid',
                'error' => 'invalid_token',
                'status' => 401,
            ], 401),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
        $this->assertNotNull($integration->last_health_check_at);
    }

    public function test_test_connection_catches_network_timeout_and_returns_400(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-timeout-test-token',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/*' => function () {
                throw new ConnectionException('cURL error 28: Connection timed out after 30000ms');
            },
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('Connection timed out', $response->json('message'));

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
        $this->assertNotNull($integration->last_health_check_at);
    }

    public function test_test_connection_fails_gracefully_when_credentials_are_absent(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => null,
            'client_secret' => null,
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
    }

    public function test_test_connection_returns_404_for_non_existent_id(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations/888888/test-connection');

        $response->assertStatus(404);
    }

    public function test_test_connection_never_leaks_plaintext_credentials(): void
    {
        $rawAccessToken = 'APP_USR-secret-cleartext-string-4444';
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => $rawAccessToken,
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 998877,
                'nickname' => 'TEST_STORE',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(200);
        $this->assertStringNotContainsString($rawAccessToken, $response->getContent());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. ADVERSARIAL STRESS: TOGGLE-ACTIVE ENDPOINT BEHAVIOR
    // ─────────────────────────────────────────────────────────────────────────

    public function test_toggle_active_flips_between_true_and_false_persistently(): void
    {
        $integration = $this->createBaseIntegration(['is_active' => true]);
        $this->assertTrue((bool) $integration->fresh()->is_active);

        // Turn OFF
        $r1 = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $r1->assertStatus(200);
        $r1->assertJsonPath('integration.is_active', false);
        $this->assertFalse((bool) $integration->fresh()->is_active);

        // Turn ON
        $r2 = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $r2->assertStatus(200);
        $r2->assertJsonPath('integration.is_active', true);
        $this->assertTrue((bool) $integration->fresh()->is_active);

        // Turn OFF again
        $r3 = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $r3->assertStatus(200);
        $r3->assertJsonPath('integration.is_active', false);
        $this->assertFalse((bool) $integration->fresh()->is_active);
    }

    public function test_toggle_active_on_non_existent_integration_returns_404(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations/777777/toggle-active');

        $response->assertStatus(404);
    }

    public function test_toggle_active_requires_authentication_and_authorization(): void
    {
        $integration = $this->createBaseIntegration(['is_active' => true]);

        // Unauthenticated -> 401
        $rUnauth = $this->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $rUnauth->assertStatus(401);

        // Cashier without permission -> 403
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        $rForbidden = $this->withHeader('X-Session-Token', $cashierToken)
            ->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $rForbidden->assertStatus(403);
    }

    public function test_toggle_active_response_does_not_leak_plaintext_secrets(): void
    {
        $plainSecret = 'super_secret_master_key_999999';
        $integration = $this->createBaseIntegration([
            'client_secret' => $plainSecret,
            'is_active' => true,
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");

        $response->assertStatus(200);
        $response->assertJsonPath('integration.has_client_secret', true);
        $this->assertStringNotContainsString($plainSecret, $response->getContent());
        $this->assertStringContainsString('****', $response->json('integration.client_secret'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. ADVERSARIAL STRESS: SQL INJECTION & MALICIOUS INPUTS IN FIELDS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_malicious_strings_and_sql_injection_payloads_are_safely_handled(): void
    {
        $maliciousStoreId = "'; DROP TABLE ecommerce_integrations; --";
        $maliciousName = '<script>alert("XSS")</script>';

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'ecommerce_platform',
            'name' => $maliciousName,
            'store_id' => $maliciousStoreId,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_integrations', [
            'store_id' => $maliciousStoreId,
            'name' => $maliciousName,
        ]);

        // Verify table still exists and is completely intact
        $this->assertGreaterThan(0, DB::table('ecommerce_integrations')->count());
    }

    public function test_adapter_cache_is_purged_on_update_and_destroy(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-token-version-1',
        ]);

        // Warm the manager adapter cache
        $manager = app(EcommerceManager::class);
        $adapter1 = $manager->forIntegration($integration);
        $this->assertNotNull($adapter1);

        // Update integration
        $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Tienda Con Cache Purgada',
            'access_token' => 'APP_USR-token-version-2',
        ]);

        // After update, manager->forIntegration should resolve a fresh instance reflecting new credentials
        $adapter2 = $manager->forIntegration($integration->fresh());
        $this->assertNotSame($adapter1, $adapter2);

        // Delete integration
        $this->actingAsAdmin()->deleteJson("/api/ecommerce/integrations/{$integration->id}");

        $this->assertDatabaseMissing('ecommerce_integrations', ['id' => $integration->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. MULTI-PLATFORM TEST-CONNECTION DIAGNOSTICS (TIENDANUBE, WOOCOMMERCE, INTERNAL)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tiendanube_test_connection_success(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => '998877',
            'access_token' => 'tn_bearer_live_token',
        ]);

        Http::fake([
            'https://api.tiendanube.com/v1/998877/store' => Http::response([
                'id' => 998877,
                'name' => ['es' => 'Tienda Oficial Nube'],
                'url_with_protocol' => 'https://mitienda.mitiendanube.com',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('store_info.id', 998877);

        $integration->refresh();
        $this->assertEquals('connected', $integration->last_health_status);
    }

    public function test_tiendanube_test_connection_failure(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'tiendanube',
            'store_id' => '998877',
            'access_token' => 'tn_bearer_invalid_token',
        ]);

        Http::fake([
            'https://api.tiendanube.com/v1/998877/store' => Http::response([
                'code' => 403,
                'message' => 'Invalid or expired bearer token',
            ], 403),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
    }

    public function test_woocommerce_test_connection_success(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'woocommerce',
            'base_url' => 'https://woo.example.org',
            'client_id' => 'ck_test_consumer_key',
            'client_secret' => 'cs_test_consumer_secret',
        ]);

        Http::fake([
            'https://woo.example.org/wp-json/wc/v3/system_status' => Http::response([
                'environment' => [
                    'site_url' => 'https://woo.example.org',
                    'wc_version' => '8.6.0',
                ],
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('store_info.url', 'https://woo.example.org');

        $integration->refresh();
        $this->assertEquals('connected', $integration->last_health_status);
    }

    public function test_woocommerce_test_connection_failure(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'woocommerce',
            'base_url' => 'https://woo.example.org',
            'client_id' => 'ck_test_consumer_key',
            'client_secret' => 'cs_test_consumer_secret',
        ]);

        Http::fake([
            'https://woo.example.org/wp-json/wc/v3/*' => Http::response([
                'code' => 'woocommerce_rest_cannot_view',
                'message' => 'Consumer Secret no coincide',
            ], 401),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
    }

    public function test_internal_platform_test_connection_success(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'ecommerce_platform',
            'base_url' => 'https://internal-api.pos.local',
            'store_id' => 'tenant-corp',
            'access_token' => 'jwt_service_token_xyz',
        ]);

        Http::fake([
            'https://internal-api.pos.local/api/v1/health' => Http::response([
                'status' => 'healthy',
                'tenant' => 'tenant-corp',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $integration->refresh();
        $this->assertEquals('connected', $integration->last_health_status);
    }

    public function test_internal_platform_test_connection_failure(): void
    {
        $integration = $this->createBaseIntegration([
            'platform' => 'ecommerce_platform',
            'base_url' => 'https://internal-api.pos.local',
            'store_id' => 'tenant-corp',
            'access_token' => 'jwt_service_token_xyz',
        ]);

        Http::fake([
            'https://internal-api.pos.local/api/v1/*' => Http::response([
                'status' => 'degraded',
                'error' => 'Database connection failed',
            ], 500),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");
        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $integration->refresh();
        $this->assertEquals('error', $integration->last_health_status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. ADVERSARIAL STRESS: FIELD BOUNDS & DATA TYPES
    // ─────────────────────────────────────────────────────────────────────────

    public function test_validation_rejects_unsupported_platform_and_overlength_strings(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'unsupported_platform_xyz',
            'name' => str_repeat('A', 101), // max: 100
            'store_id' => str_repeat('S', 101), // max: 100
            'base_url' => 'https://'.str_repeat('U', 250).'.com', // max: 255
            'client_id' => str_repeat('C', 151), // max: 150
            'token_expires_at' => 'not-a-valid-date',
            'settings' => 'not-an-array',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'platform',
            'name',
            'store_id',
            'base_url',
            'client_id',
            'token_expires_at',
            'settings',
        ]);
    }

    public function test_settings_dictionary_and_valid_expiration_date_are_persisted(): void
    {
        $payload = [
            'platform' => 'mercadolibre',
            'name' => 'MELI Con Configs Complejas',
            'token_expires_at' => '2028-10-15 12:00:00',
            'settings' => [
                'webhook_notifications' => true,
                'auto_sync_interval' => 15,
                'excluded_categories' => ['CAT-01', 'CAT-02'],
            ],
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.settings.auto_sync_interval', 15);
        $response->assertJsonPath('integration.settings.webhook_notifications', true);
        $response->assertJsonPath('integration.settings.excluded_categories', ['CAT-01', 'CAT-02']);

        $id = $response->json('integration.id');
        $integration = EcommerceIntegration::find($id);
        $this->assertIsArray($integration->settings);
        $this->assertEquals(15, $integration->settings['auto_sync_interval']);
    }
}
