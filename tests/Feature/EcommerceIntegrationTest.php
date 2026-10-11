<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use App\Models\EcommerceSyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a valid base integration in database.
     */
    protected function createIntegration(array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => 'mercadolibre',
            'name' => 'Tienda Oficial MELI',
            'client_id' => 'APP_12345678',
            'client_secret' => 'sec_client_secret_xyz',
            'access_token' => 'APP_USR-123456789-abcdef',
            'refresh_token' => 'TG-abcdef123456-789',
            'webhook_secret' => 'whsec_secret_hash_key',
            'is_active' => true,
            'sync_stock_enabled' => true,
            'sync_price_enabled' => false,
            'rate_limit_per_minute' => 60,
            'batch_size' => 50,
            'timeout_seconds' => 30,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // I. SEGURIDAD, AUTENTICACIÓN Y AUTORIZACIÓN (MIDDLEWARE)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $response = $this->getJson('/api/ecommerce/integrations');

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'SESSION_MISSING');
    }

    public function test_cashier_without_manage_settings_permission_is_rejected_with_403(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        $response = $this->withHeader('X-Session-Token', $cashierToken)
            ->getJson('/api/ecommerce/integrations');

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    public function test_cashier_with_valid_admin_pin_is_authorized(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $response = $this->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '1234')
            ->getJson('/api/ecommerce/integrations');

        $response->assertStatus(200);
    }

    public function test_cashier_with_invalid_admin_pin_is_rejected_with_403(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $response = $this->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '9999')
            ->getJson('/api/ecommerce/integrations');

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
    }

    public function test_admin_user_can_access_endpoints(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations');

        $response->assertStatus(200);
        $response->assertJson([]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // II. OPERACIONES CRUD BÁSICAS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_can_list_integrations_empty_and_populated(): void
    {
        $responseEmpty = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations');
        $responseEmpty->assertStatus(200)->assertExactJson([]);

        $integration = $this->createIntegration();

        $responsePopulated = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations');
        $responsePopulated->assertStatus(200);
        $this->assertCount(1, $responsePopulated->json());
        $responsePopulated->assertJsonFragment([
            'id' => $integration->id,
            'name' => 'Tienda Oficial MELI',
            'platform' => 'mercadolibre',
        ]);
    }

    public function test_can_create_mercadolibre_integration(): void
    {
        $payload = [
            'platform' => 'mercadolibre',
            'name' => 'Canal Mercado Libre Argentina',
            'client_id' => 'APP_MELI_9988',
            'client_secret' => 'secret_plain_meli_123',
            'access_token' => 'APP_USR-plain-token-5566',
            'refresh_token' => 'TG-refresh-token-7788',
            'sync_stock_enabled' => true,
            'sync_price_enabled' => true,
            'rate_limit_per_minute' => 120,
            'batch_size' => 40,
            'timeout_seconds' => 25,
            'auto_pause_on_zero_stock' => true,
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Integración creada con éxito.');
        $response->assertJsonPath('integration.name', 'Canal Mercado Libre Argentina');
        $response->assertJsonPath('integration.platform', 'mercadolibre');
        $response->assertJsonPath('integration.has_access_token', true);
        $response->assertJsonPath('integration.has_client_secret', true);
        $response->assertJsonPath('integration.has_refresh_token', true);

        $this->assertDatabaseHas('ecommerce_integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Canal Mercado Libre Argentina',
            'client_id' => 'APP_MELI_9988',
        ]);
    }

    public function test_can_create_woocommerce_integration(): void
    {
        $payload = [
            'platform' => 'woocommerce',
            'name' => 'Tienda WooCommerce Online',
            'base_url' => 'https://mitienda.com.ar',
            'client_id' => 'ck_987654321',
            'client_secret' => 'cs_123456789',
            'webhook_secret' => 'whsec_woo_hmac_key',
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.platform', 'woocommerce');
        $response->assertJsonPath('integration.base_url', 'https://mitienda.com.ar');
        $response->assertJsonPath('integration.has_webhook_secret', true);

        $this->assertDatabaseHas('ecommerce_integrations', [
            'platform' => 'woocommerce',
            'base_url' => 'https://mitienda.com.ar',
            'client_id' => 'ck_987654321',
        ]);
    }

    public function test_can_create_tiendanube_integration(): void
    {
        $payload = [
            'platform' => 'tiendanube',
            'name' => 'TiendaNube Sucursal',
            'store_id' => '1234567',
            'client_id' => 'app_id_nube',
            'client_secret' => 'sec_nube_client',
            'access_token' => 'nube_bearer_token_xyz',
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.platform', 'tiendanube');
        $response->assertJsonPath('integration.store_id', '1234567');

        $this->assertDatabaseHas('ecommerce_integrations', [
            'platform' => 'tiendanube',
            'store_id' => '1234567',
        ]);
    }

    public function test_can_create_internal_platform_integration(): void
    {
        $payload = [
            'platform' => 'ecommerce_platform',
            'name' => 'Plataforma E-Commerce Interna',
            'base_url' => 'https://api.internal-shop.local',
            'store_id' => 'tenant-corp-01',
            'access_token' => 'internal_jwt_service_token',
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('integration.platform', 'ecommerce_platform');
        $response->assertJsonPath('integration.store_id', 'tenant-corp-01');

        $this->assertDatabaseHas('ecommerce_integrations', [
            'platform' => 'ecommerce_platform',
            'store_id' => 'tenant-corp-01',
        ]);
    }

    public function test_can_show_single_integration(): void
    {
        $integration = $this->createIntegration();

        $response = $this->actingAsAdmin()->getJson("/api/ecommerce/integrations/{$integration->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $integration->id);
        $response->assertJsonPath('name', $integration->name);
        $response->assertJsonPath('platform', 'mercadolibre');
        $response->assertJsonPath('has_access_token', true);
        $response->assertJsonPath('has_client_secret', true);
    }

    public function test_show_non_existent_integration_returns_404(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations/99999');

        $response->assertStatus(404);
    }

    public function test_can_update_integration_operational_settings(): void
    {
        $integration = $this->createIntegration();

        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Nombre Actualizado',
            'sync_stock_enabled' => false,
            'sync_price_enabled' => true,
            'batch_size' => 80,
            'timeout_seconds' => 45,
            'rate_limit_per_minute' => 150,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Integración actualizada correctamente.');
        $response->assertJsonPath('integration.name', 'Nombre Actualizado');
        $response->assertJsonPath('integration.sync_stock_enabled', false);
        $response->assertJsonPath('integration.sync_price_enabled', true);
        $response->assertJsonPath('integration.batch_size', 80);
        $response->assertJsonPath('integration.timeout_seconds', 45);

        $this->assertDatabaseHas('ecommerce_integrations', [
            'id' => $integration->id,
            'name' => 'Nombre Actualizado',
            'sync_stock_enabled' => false,
            'batch_size' => 80,
        ]);
    }

    public function test_can_delete_integration(): void
    {
        $integration = $this->createIntegration();

        $response = $this->actingAsAdmin()->deleteJson("/api/ecommerce/integrations/{$integration->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Integración eliminada correctamente.');

        $this->assertDatabaseMissing('ecommerce_integrations', [
            'id' => $integration->id,
        ]);
    }

    public function test_toggle_active_flips_is_active_flag(): void
    {
        $integration = $this->createIntegration(['is_active' => true]);

        // Toggle to false
        $responseOff = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $responseOff->assertStatus(200);
        $responseOff->assertJsonPath('integration.is_active', false);
        $this->assertFalse((bool) $integration->fresh()->is_active);

        // Toggle back to true
        $responseOn = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $responseOn->assertStatus(200);
        $responseOn->assertJsonPath('integration.is_active', true);
        $this->assertTrue((bool) $integration->fresh()->is_active);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // III. CIFRADO EN REPOSO Y ENMASCARAMIENTO (DEFENSE-IN-DEPTH)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_credentials_are_strictly_encrypted_in_database_at_rest(): void
    {
        $plainSecret = 'super_secret_client_key_999';
        $plainToken = 'APP_USR-plain-access-token-777';
        $plainRefresh = 'TG-plain-refresh-token-888';
        $plainWebhook = 'webhook_hmac_raw_secret_555';

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Tienda Cifrada MELI',
            'client_secret' => $plainSecret,
            'access_token' => $plainToken,
            'refresh_token' => $plainRefresh,
            'webhook_secret' => $plainWebhook,
        ]);

        $response->assertStatus(201);
        $createdId = $response->json('integration.id');

        // Consultar directamente la fila raw de la base de datos sin pasar por Eloquent mutators
        $dbRow = DB::table('ecommerce_integrations')->where('id', $createdId)->first();
        $this->assertNotNull($dbRow);

        // 1. Los campos raw en BD NO deben coincidir con el texto plano
        $this->assertNotEquals($plainSecret, $dbRow->client_secret, 'client_secret guardado en texto plano');
        $this->assertNotEquals($plainToken, $dbRow->access_token, 'access_token guardado en texto plano');
        $this->assertNotEquals($plainRefresh, $dbRow->refresh_token, 'refresh_token guardado en texto plano');
        $this->assertNotEquals($plainWebhook, $dbRow->webhook_secret, 'webhook_secret guardado en texto plano');

        // 2. El ciphertext en BD no debe contener el texto plano como substring
        $this->assertStringNotContainsString($plainSecret, (string) $dbRow->client_secret);
        $this->assertStringNotContainsString($plainToken, (string) $dbRow->access_token);
        $this->assertStringNotContainsString($plainRefresh, (string) $dbRow->refresh_token);
        $this->assertStringNotContainsString($plainWebhook, (string) $dbRow->webhook_secret);

        // 3. Los valores deben desencriptar exactamente al texto plano original con Crypt::decryptString
        $this->assertEquals($plainSecret, Crypt::decryptString($dbRow->client_secret));
        $this->assertEquals($plainToken, Crypt::decryptString($dbRow->access_token));
        $this->assertEquals($plainRefresh, Crypt::decryptString($dbRow->refresh_token));
        $this->assertEquals($plainWebhook, Crypt::decryptString($dbRow->webhook_secret));
    }

    public function test_index_and_show_endpoints_never_leak_plaintext_credentials(): void
    {
        $plainToken = 'APP_USR-1234567890-abcdef';
        $plainSecret = 'super_confidential_client_secret';

        $integration = $this->createIntegration([
            'access_token' => $plainToken,
            'client_secret' => $plainSecret,
        ]);

        // Probar show
        $showResponse = $this->actingAsAdmin()->getJson("/api/ecommerce/integrations/{$integration->id}");
        $showResponse->assertStatus(200);

        $showContent = $showResponse->getContent();
        $this->assertStringNotContainsString($plainToken, $showContent, 'Plaintext access_token filtrado en show');
        $this->assertStringNotContainsString($plainSecret, $showContent, 'Plaintext client_secret filtrado en show');

        $showData = $showResponse->json();
        $this->assertStringContainsString('****', (string) $showData['access_token']);
        $this->assertStringContainsString('****', (string) $showData['client_secret']);
        $this->assertTrue($showData['has_access_token']);
        $this->assertTrue($showData['has_client_secret']);

        // Probar index
        $indexResponse = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations');
        $indexResponse->assertStatus(200);

        $indexContent = $indexResponse->getContent();
        $this->assertStringNotContainsString($plainToken, $indexContent, 'Plaintext access_token filtrado en index');
        $this->assertStringNotContainsString($plainSecret, $indexContent, 'Plaintext client_secret filtrado en index');
    }

    public function test_updating_with_masked_tokens_preserves_original_encrypted_secrets(): void
    {
        $originalSecret = 'original_secret_12345';
        $originalToken = 'APP_USR-9876543210-orig';

        $integration = $this->createIntegration([
            'client_secret' => $originalSecret,
            'access_token' => $originalToken,
        ]);

        $rawDbBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Enviar actualización con tokens enmascarados tal como los recibiría Flutter (con asteriscos)
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Tienda Modificada Sin Alterar Claves',
            'client_secret' => '****',
            'access_token' => 'APP_USR-****orig',
        ]);

        $response->assertStatus(200);

        $rawDbAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // El ciphertext exacto en BD debe permanecer intacto
        $this->assertSame($rawDbBefore->client_secret, $rawDbAfter->client_secret);
        $this->assertSame($rawDbBefore->access_token, $rawDbAfter->access_token);

        // Desencriptar debe seguir arrojando los secretos originales intactos
        $this->assertEquals($originalSecret, Crypt::decryptString($rawDbAfter->client_secret));
        $this->assertEquals($originalToken, Crypt::decryptString($rawDbAfter->access_token));
    }

    public function test_updating_with_null_credential_preserves_original_encrypted_secrets(): void
    {
        $originalToken = 'APP_USR-keep-alive-token-333';

        $integration = $this->createIntegration([
            'access_token' => $originalToken,
        ]);

        $rawDbBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Enviar PUT con access_token en null (comportamiento de formulario no modificado)
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Actualizando Solo Nombre',
            'access_token' => null,
        ]);

        $response->assertStatus(200);

        $rawDbAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Debe conservar el token existente sin sobrescribirlo
        $this->assertSame($rawDbBefore->access_token, $rawDbAfter->access_token);
        $this->assertEquals($originalToken, Crypt::decryptString($rawDbAfter->access_token));
    }

    public function test_updating_with_new_plaintext_token_re_encrypts_and_updates_database(): void
    {
        $oldToken = 'APP_USR-old-expired-token-111';
        $newToken = 'APP_USR-brand-new-live-token-222';

        $integration = $this->createIntegration(['access_token' => $oldToken]);

        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'access_token' => $newToken,
        ]);

        $response->assertStatus(200);

        $rawDbAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Debe haberse actualizado con el nuevo valor cifrado
        $this->assertNotEquals($newToken, $rawDbAfter->access_token);
        $this->assertEquals($newToken, Crypt::decryptString($rawDbAfter->access_token));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IV. VALIDACIONES Y CASOS LÍMITE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_validation_requires_platform_and_name(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['platform', 'name']);
    }

    public function test_validation_rejects_unsupported_platform_slug(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'shopify',
            'name' => 'Tienda Shopify Invalida',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['platform']);
    }

    public function test_validation_requires_base_url_for_woocommerce(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'woocommerce',
            'name' => 'Mi Tienda Woo Sin URL',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['base_url']);
    }

    public function test_validation_requires_store_id_for_tiendanube(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'name' => 'Tienda Nube Sin Store ID',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_validation_enforces_unique_platform_and_store_id(): void
    {
        $this->createIntegration([
            'platform' => 'tiendanube',
            'store_id' => '12345',
            'name' => 'Primera Tienda',
        ]);

        // Intentar registrar la misma tienda en la misma plataforma
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'store_id' => '12345',
            'name' => 'Segunda Tienda Duplicada',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['store_id']);
    }

    public function test_validation_allows_same_store_id_for_different_platforms(): void
    {
        $this->createIntegration([
            'platform' => 'tiendanube',
            'store_id' => '12345',
            'name' => 'Tienda Nube',
        ]);

        // Mismo store_id pero en plataforma ecommerce_platform
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'ecommerce_platform',
            'store_id' => '12345',
            'name' => 'Plataforma Propia Mismo ID',
        ]);

        $response->assertStatus(201);
    }

    public function test_validation_unique_store_id_ignores_self_on_update(): void
    {
        $integration = $this->createIntegration([
            'platform' => 'tiendanube',
            'store_id' => '998877',
            'name' => 'Original Tienda',
        ]);

        // Actualizar el nombre manteniendo el mismo store_id no debe disparar error unique
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Nombre Modificado',
            'store_id' => '998877',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('integration.name', 'Nombre Modificado');
    }

    public function test_validation_numeric_bounds(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Tienda Invalida',
            'rate_limit_per_minute' => 0, // min 1
            'batch_size' => 500, // max 200
            'timeout_seconds' => 2, // min 5
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rate_limit_per_minute', 'batch_size', 'timeout_seconds']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // V. PRUEBA DE CONEXIÓN EN VIVO (TEST-CONNECTION)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_test_connection_endpoint_invokes_adapter_and_updates_health_status(): void
    {
        $integration = $this->createIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-valid-meli-test-token',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 99887766,
                'nickname' => 'TIENDA_OFICIAL_MELI',
                'site_id' => 'MLA',
                'email' => 'admin@tiendaoficial.com',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('store_info.id', 99887766);
        $response->assertJsonPath('store_info.nickname', 'TIENDA_OFICIAL_MELI');

        $integration->refresh();
        $this->assertEquals('connected', $integration->last_health_status);
        $this->assertNotNull($integration->last_health_check_at);
    }

    public function test_test_connection_endpoint_handles_failure_and_updates_status(): void
    {
        $integration = $this->createIntegration([
            'platform' => 'mercadolibre',
            'access_token' => 'APP_USR-invalid-token',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'message' => 'Invalid or expired credentials',
                'error' => 'unauthorized',
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

    public function test_can_list_ecommerce_sync_logs(): void
    {
        $integration = $this->createIntegration();

        EcommerceSyncLog::create([
            'integration_id' => $integration->id,
            'direction' => 'outbound',
            'action' => 'stock_update',
            'stock_before' => 10,
            'stock_after' => 8,
            'http_status' => 200,
            'duration_ms' => 150,
            'status' => 'success',
            'request_payload' => ['available_quantity' => 8],
            'response_payload' => ['id' => 'MLA123'],
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/ecommerce/logs');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'integration_id',
                    'direction',
                    'action',
                    'stock_before',
                    'stock_after',
                    'status',
                    'duration_ms',
                    'created_at',
                ],
            ],
            'total',
        ]);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.status', 'success');
        $response->assertJsonPath('data.0.direction', 'outbound');
    }

    public function test_can_filter_ecommerce_sync_logs_by_status_and_integration(): void
    {
        $integration1 = $this->createIntegration(['platform' => 'mercadolibre', 'name' => 'Meli']);
        $integration2 = $this->createIntegration(['platform' => 'woocommerce', 'name' => 'Woo', 'base_url' => 'https://woo.test']);

        EcommerceSyncLog::create([
            'integration_id' => $integration1->id,
            'direction' => 'outbound',
            'action' => 'stock_update',
            'status' => 'success',
        ]);

        EcommerceSyncLog::create([
            'integration_id' => $integration2->id,
            'direction' => 'outbound',
            'action' => 'stock_update',
            'status' => 'failed',
            'error_message' => 'Connection timeout',
        ]);

        // Filter by status failed
        $resFailed = $this->actingAsAdmin()->getJson('/api/ecommerce/logs?status=failed');
        $resFailed->assertStatus(200);
        $resFailed->assertJsonPath('total', 1);
        $resFailed->assertJsonPath('data.0.error_message', 'Connection timeout');

        // Filter by integration1
        $resMeli = $this->actingAsAdmin()->getJson("/api/ecommerce/logs?integration_id={$integration1->id}");
        $resMeli->assertStatus(200);
        $resMeli->assertJsonPath('total', 1);
        $resMeli->assertJsonPath('data.0.integration_id', $integration1->id);
    }
}
