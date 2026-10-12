<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\EcommerceIntegration;
use App\Models\User;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EcommerceApiSecurityChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['ecommerce_sync' => true])]
        );
    }

    protected function createSampleIntegration(array $overrides = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => 'mercadolibre',
            'name' => 'Canal Test MELI',
            'client_id' => 'APP_ID_MELI_123',
            'client_secret' => 'SECRET_MELI_PLAIN_XYZ_999',
            'access_token' => 'APP_USR-TOKEN_PLAIN_MELI_888',
            'refresh_token' => 'TG-REFRESH_PLAIN_MELI_777',
            'webhook_secret' => 'WHSEC_PLAIN_MELI_666',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Recursively scan any nested data structure to ensure none of the forbidden
     * plain secrets appear in values or keys.
     */
    protected function assertNoPlaintextLeaked(array $data, array $plainSecrets): void
    {
        array_walk_recursive($data, function ($value, $key) use ($plainSecrets) {
            foreach ($plainSecrets as $secret) {
                if (empty($secret)) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $value,
                    "Leak detected in key '{$key}': plain secret was found in value '{$value}'"
                );
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $key,
                    "Leak detected in key name '{$key}'"
                );
            }
        });
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. AUTHENTICATION EMPIRICAL TEST (401 WITHOUT VALID SESSION TOKEN)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_all_endpoints_reject_unauthenticated_requests_with_401(): void
    {
        $integration = $this->createSampleIntegration();

        $endpoints = [
            ['GET', '/api/ecommerce/integrations', []],
            ['POST', '/api/ecommerce/integrations', ['platform' => 'mercadolibre', 'name' => 'Test']],
            ['GET', "/api/ecommerce/integrations/{$integration->id}", []],
            ['PUT', "/api/ecommerce/integrations/{$integration->id}", ['name' => 'Updated']],
            ['DELETE', "/api/ecommerce/integrations/{$integration->id}", []],
            ['POST', "/api/ecommerce/integrations/{$integration->id}/test-connection", []],
            ['POST', "/api/ecommerce/integrations/{$integration->id}/toggle-active", []],
        ];

        foreach ($endpoints as [$method, $uri, $data]) {
            // Request without any session token
            $this->flushHeaders();
            $response = $this->json($method, $uri, $data);
            $response->assertStatus(401);
            $response->assertJsonPath('error_code', 'SESSION_MISSING');

            // Request with empty string session token
            $this->flushHeaders();
            $responseEmpty = $this->withHeader('X-Session-Token', '')->json($method, $uri, $data);
            $responseEmpty->assertStatus(401);
            $responseEmpty->assertJsonPath('error_code', 'SESSION_MISSING');

            // Request with invalid / expired session token
            $this->flushHeaders();
            $responseInvalid = $this->withHeader('X-Session-Token', 'non-existent-token-abc')->json($method, $uri, $data);
            $responseInvalid->assertStatus(401);
            $responseInvalid->assertJsonPath('error_code', 'SESSION_EXPIRED');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. AUTHORIZATION REJECTION FOR CASHIERS WITHOUT SUPERVISOR PIN (403)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_all_endpoints_reject_cashier_without_supervisor_pin_with_403(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-token-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        $integration = $this->createSampleIntegration();

        $endpoints = [
            ['GET', '/api/ecommerce/integrations', []],
            ['POST', '/api/ecommerce/integrations', ['platform' => 'mercadolibre', 'name' => 'Test']],
            ['GET', "/api/ecommerce/integrations/{$integration->id}", []],
            ['PUT', "/api/ecommerce/integrations/{$integration->id}", ['name' => 'Updated']],
            ['DELETE', "/api/ecommerce/integrations/{$integration->id}", []],
            ['POST', "/api/ecommerce/integrations/{$integration->id}/test-connection", []],
            ['POST', "/api/ecommerce/integrations/{$integration->id}/toggle-active", []],
        ];

        foreach ($endpoints as [$method, $uri, $data]) {
            // Cashier without PIN -> 403 PIN_REQUIRED
            $this->flushHeaders();
            $response = $this->withHeader('X-Session-Token', $cashierToken)
                ->json($method, $uri, $data);

            $response->assertStatus(403);
            $response->assertJsonPath('error_code', 'PIN_REQUIRED');

            // Cashier with wrong PIN -> 403 INVALID_ADMIN_PIN (clear rate limiter before test so it tests PIN verification)
            RateLimiter::clear("pin_attempts:{$cashier->id}:127.0.0.1");
            $this->flushHeaders();
            $responseBadPin = $this->withHeader('X-Session-Token', $cashierToken)
                ->withHeader('X-Admin-Pin', '999999')
                ->json($method, $uri, $data);

            $responseBadPin->assertStatus(403);
            $responseBadPin->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }
    }

    public function test_supervisor_pin_brute_force_triggers_rate_limit_lockout_429(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-cashier-throttle-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        RateLimiter::clear("pin_attempts:{$cashier->id}:127.0.0.1");

        // 5 consecutive failed attempts
        for ($i = 1; $i <= 5; $i++) {
            $this->flushHeaders();
            $res = $this->withHeader('X-Session-Token', $cashierToken)
                ->withHeader('X-Admin-Pin', '000000')
                ->getJson('/api/ecommerce/integrations');
            $res->assertStatus(403);
            $res->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }

        // 6th attempt must trigger 429 PIN_LOCKED_TEMPORARILY
        $this->flushHeaders();
        $resLocked = $this->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '000000')
            ->getJson('/api/ecommerce/integrations');

        $resLocked->assertStatus(429);
        $resLocked->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');
    }

    public function test_cashier_with_valid_supervisor_pin_is_authorized_to_manage_integrations(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'session-token-cashier-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('4321'),
        ]);

        $integration = $this->createSampleIntegration();

        // Cashier providing supervisor PIN in header can view integration
        $response = $this->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '4321')
            ->getJson("/api/ecommerce/integrations/{$integration->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $integration->id);

        // Cashier providing supervisor PIN in request body can also authorize
        $responseBodyPin = $this->withHeader('X-Session-Token', $cashierToken)
            ->putJson("/api/ecommerce/integrations/{$integration->id}", [
                'name' => 'Nombre Autorizado Por Supervisor',
                'admin_pin' => '4321',
            ]);

        $responseBodyPin->assertStatus(200);
        $responseBodyPin->assertJsonPath('integration.name', 'Nombre Autorizado Por Supervisor');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. DIRECT DB QUERY INSPECTION: RAW DATABASE COLUMNS ARE CIPHERTEXT
    // ═════════════════════════════════════════════════════════════════════════

    public function test_direct_db_inspection_raw_columns_are_strictly_ciphertext(): void
    {
        $plainSecrets = [
            'client_secret' => 'sec_super_secret_raw_pass_!@#$1234',
            'access_token' => 'APP_USR-token_plain_unencrypted_555',
            'refresh_token' => 'TG-refresh_plain_unencrypted_777',
            'webhook_secret' => 'whsec_signature_plain_unencrypted_999',
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Tienda Verificacion DB Raw',
            'client_id' => 'CLIENT_ID_RAW_TEST',
            'client_secret' => $plainSecrets['client_secret'],
            'access_token' => $plainSecrets['access_token'],
            'refresh_token' => $plainSecrets['refresh_token'],
            'webhook_secret' => $plainSecrets['webhook_secret'],
        ]);

        $response->assertStatus(201);
        $id = $response->json('integration.id');

        // DIRECT DB QUERY bypassing Eloquent mutators and accessors
        $rawRow = DB::table('ecommerce_integrations')->where('id', $id)->first();
        $this->assertNotNull($rawRow, 'Registro no encontrado en BD');

        foreach ($plainSecrets as $field => $plainVal) {
            $rawColumnValue = $rawRow->{$field};

            $this->assertNotNull($rawColumnValue, "Columna {$field} no debe ser null en BD");
            $this->assertIsString($rawColumnValue, "Columna {$field} debe ser string en BD");

            // 1. Never equals plaintext
            $this->assertNotEquals(
                $plainVal,
                $rawColumnValue,
                "VULNERABILIDAD CRÍTICA: Columna {$field} contiene texto plano exacto en SQLite"
            );

            // 2. Never contains plaintext as substring
            $this->assertStringNotContainsString(
                $plainVal,
                $rawColumnValue,
                "VULNERABILIDAD: Columna {$field} contiene texto plano como substring en SQLite"
            );

            // 3. Payload must be valid Laravel ciphertext structure (base64 serialized array with iv, value, mac)
            $decodedJson = json_decode((string) base64_decode($rawColumnValue, true), true);
            $this->assertIsArray($decodedJson, "Columna {$field} debe ser un payload cifrado base64 válido");
            $this->assertArrayHasKey('iv', $decodedJson);
            $this->assertArrayHasKey('value', $decodedJson);
            $this->assertArrayHasKey('mac', $decodedJson);

            // 4. Crypt::decryptString must correctly reconstruct the exact plaintext
            $decrypted = Crypt::decryptString($rawColumnValue);
            $this->assertSame(
                $plainVal,
                $decrypted,
                "Fallo de integridad: descifrado no coincide con el secreto original para {$field}"
            );
        }
    }

    public function test_raw_columns_support_unicode_special_chars_and_json_payloads(): void
    {
        $complexSecrets = [
            'client_secret' => 'contraseña_con_ñ_y_tildes_áéíóú_🚀',
            'access_token' => '{"jwt": "token_with_json_and_quotes", "sub": 12345}',
            'refresh_token' => 'token+with/special=chars==&symbols#%*',
            'webhook_secret' => 'whsec_very_long_string_'.str_repeat('A', 256),
        ];

        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', array_merge([
            'platform' => 'internal_ecommerce_platform',
            'platform' => 'ecommerce_platform',
            'name' => 'Tienda Caracteres Especiales',
        ], $complexSecrets));

        $response->assertStatus(201);
        $id = $response->json('integration.id');

        $rawRow = DB::table('ecommerce_integrations')->where('id', $id)->first();
        $this->assertNotNull($rawRow);

        foreach ($complexSecrets as $field => $expectedPlain) {
            $rawColumnValue = $rawRow->{$field};
            $this->assertStringNotContainsString($expectedPlain, $rawColumnValue);
            $this->assertSame($expectedPlain, Crypt::decryptString($rawColumnValue));
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. HTTP GET RESPONSE PAYLOADS: ZERO RAW SECRETS LEAKED
    // ═════════════════════════════════════════════════════════════════════════

    public function test_scan_all_json_fields_guarantees_raw_secrets_never_leaked(): void
    {
        $plainSecrets = [
            'CLIENT_SECRET_TOP_SECRET_111',
            'APP_USR-ACCESS_TOKEN_HIGHLY_CONFIDENTIAL_222',
            'TG-REFRESH_TOKEN_CRITICAL_333',
            'WEBHOOK_SECRET_HMAC_SUPER_SECRET_444',
        ];

        $integration = $this->createSampleIntegration([
            'client_secret' => $plainSecrets[0],
            'access_token' => $plainSecrets[1],
            'refresh_token' => $plainSecrets[2],
            'webhook_secret' => $plainSecrets[3],
        ]);

        // 1. GET /api/ecommerce/integrations (index)
        $indexResponse = $this->actingAsAdmin()->getJson('/api/ecommerce/integrations');
        $indexResponse->assertStatus(200);
        $this->assertStringNotContainsString($plainSecrets[0], $indexResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[1], $indexResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[2], $indexResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[3], $indexResponse->getContent());
        $this->assertNoPlaintextLeaked($indexResponse->json(), $plainSecrets);

        // 2. GET /api/ecommerce/integrations/{id} (show)
        $showResponse = $this->actingAsAdmin()->getJson("/api/ecommerce/integrations/{$integration->id}");
        $showResponse->assertStatus(200);
        $this->assertStringNotContainsString($plainSecrets[0], $showResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[1], $showResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[2], $showResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[3], $showResponse->getContent());
        $showData = $showResponse->json();
        $this->assertNoPlaintextLeaked($showData, $plainSecrets);

        // Verify masking pattern and flags
        $this->assertStringContainsString('****', $showData['client_secret']);
        $this->assertStringContainsString('****', $showData['access_token']);
        $this->assertStringContainsString('****', $showData['refresh_token']);
        $this->assertStringContainsString('****', $showData['webhook_secret']);
        $this->assertTrue($showData['has_client_secret']);
        $this->assertTrue($showData['has_access_token']);
        $this->assertTrue($showData['has_refresh_token']);
        $this->assertTrue($showData['has_webhook_secret']);

        // 3. POST /api/ecommerce/integrations (store response)
        $newPlainSecrets = [
            'PLAIN_CLIENT_SEC_555',
            'APP_USR-PLAIN_ACCESS_666',
            'PLAIN_REFRESH_777',
            'PLAIN_WH_888',
        ];
        $storeResponse = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'tiendanube',
            'store_id' => 'store_leak_check_99',
            'name' => 'Tienda Nube Scan',
            'client_secret' => $newPlainSecrets[0],
            'access_token' => $newPlainSecrets[1],
            'refresh_token' => $newPlainSecrets[2],
            'webhook_secret' => $newPlainSecrets[3],
        ]);
        $storeResponse->assertStatus(201);
        $this->assertStringNotContainsString($newPlainSecrets[0], $storeResponse->getContent());
        $this->assertStringNotContainsString($newPlainSecrets[1], $storeResponse->getContent());
        $this->assertStringNotContainsString($newPlainSecrets[2], $storeResponse->getContent());
        $this->assertStringNotContainsString($newPlainSecrets[3], $storeResponse->getContent());
        $this->assertNoPlaintextLeaked($storeResponse->json(), $newPlainSecrets);

        // 4. PUT /api/ecommerce/integrations/{id} (update response)
        $updateResponse = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Nombre Actualizado Scan Test',
        ]);
        $updateResponse->assertStatus(200);
        $this->assertStringNotContainsString($plainSecrets[0], $updateResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[1], $updateResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[2], $updateResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[3], $updateResponse->getContent());
        $this->assertNoPlaintextLeaked($updateResponse->json(), $plainSecrets);

        // 5. POST /api/ecommerce/integrations/{id}/toggle-active (toggle response)
        $toggleResponse = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active");
        $toggleResponse->assertStatus(200);
        $this->assertStringNotContainsString($plainSecrets[0], $toggleResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[1], $toggleResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[2], $toggleResponse->getContent());
        $this->assertStringNotContainsString($plainSecrets[3], $toggleResponse->getContent());
        $this->assertNoPlaintextLeaked($toggleResponse->json(), $plainSecrets);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. UPDATE WITH MASKED TOKEN (****): DB CIPHERTEXT REMAINS INTACT
    // ═════════════════════════════════════════════════════════════════════════

    public function test_update_with_masked_tokens_preserves_db_ciphertext_strictly_intact(): void
    {
        $originalSecrets = [
            'client_secret' => 'orig_client_secret_xyz123',
            'access_token' => 'APP_USR-orig_access_token_abc456',
            'refresh_token' => 'TG-orig_refresh_token_def789',
            'webhook_secret' => 'orig_webhook_secret_ghi012',
        ];

        $integration = $this->createSampleIntegration($originalSecrets);

        // Snapshot the EXACT raw database values
        $rawBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();
        $this->assertNotNull($rawBefore);

        // Send update containing masked strings as returned by GET/transformIntegration
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Tienda Modificada Preservando Todo',
            'client_secret' => 'orig****y123',
            'access_token' => 'APP_USR-****c456',
            'refresh_token' => 'TG-o****f789',
            'webhook_secret' => 'orig****i012',
        ]);

        $response->assertStatus(200);

        // Snapshot raw database values after update
        $rawAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();
        $this->assertNotNull($rawAfter);

        // Ciphertext in DB must be bit-for-bit IDENTICAL
        $this->assertSame(
            $rawBefore->client_secret,
            $rawAfter->client_secret,
            'client_secret DB ciphertext was mutated during masked update'
        );
        $this->assertSame(
            $rawBefore->access_token,
            $rawAfter->access_token,
            'access_token DB ciphertext was mutated during masked update'
        );
        $this->assertSame(
            $rawBefore->refresh_token,
            $rawAfter->refresh_token,
            'refresh_token DB ciphertext was mutated during masked update'
        );
        $this->assertSame(
            $rawBefore->webhook_secret,
            $rawAfter->webhook_secret,
            'webhook_secret DB ciphertext was mutated during masked update'
        );

        // Decryption of ciphertext after update must yield original plaintext secrets
        $this->assertSame($originalSecrets['client_secret'], Crypt::decryptString($rawAfter->client_secret));
        $this->assertSame($originalSecrets['access_token'], Crypt::decryptString($rawAfter->access_token));
        $this->assertSame($originalSecrets['refresh_token'], Crypt::decryptString($rawAfter->refresh_token));
        $this->assertSame($originalSecrets['webhook_secret'], Crypt::decryptString($rawAfter->webhook_secret));
    }

    public function test_update_with_generic_masked_placeholder_preserves_ciphertext(): void
    {
        $originalSecrets = [
            'client_secret' => 'generic_secret_preserve_1',
            'access_token' => 'generic_token_preserve_2',
        ];

        $integration = $this->createSampleIntegration($originalSecrets);
        $rawBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Updating with simple generic '****' placeholder
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'client_secret' => '****',
            'access_token' => '****',
        ]);

        $response->assertStatus(200);

        $rawAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        $this->assertSame($rawBefore->client_secret, $rawAfter->client_secret);
        $this->assertSame($rawBefore->access_token, $rawAfter->access_token);
        $this->assertSame($originalSecrets['client_secret'], Crypt::decryptString($rawAfter->client_secret));
        $this->assertSame($originalSecrets['access_token'], Crypt::decryptString($rawAfter->access_token));
    }

    public function test_update_with_partial_rotation_re_encrypts_only_modified_secret(): void
    {
        $originalSecrets = [
            'client_secret' => 'client_sec_untouched_123',
            'access_token' => 'old_access_token_to_rotate',
        ];

        $integration = $this->createSampleIntegration($originalSecrets);
        $rawBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        $newAccessToken = 'new_rotated_live_access_token_999';

        // Rotate access_token, preserve client_secret with masked token
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'client_secret' => 'clie****_123',
            'access_token' => $newAccessToken,
        ]);

        $response->assertStatus(200);

        $rawAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // client_secret must remain bit-for-bit unchanged
        $this->assertSame($rawBefore->client_secret, $rawAfter->client_secret);
        $this->assertSame($originalSecrets['client_secret'], Crypt::decryptString($rawAfter->client_secret));

        // access_token must have changed and must encrypt the new token
        $this->assertNotSame($rawBefore->access_token, $rawAfter->access_token);
        $this->assertStringNotContainsString($newAccessToken, $rawAfter->access_token);
        $this->assertSame($newAccessToken, Crypt::decryptString($rawAfter->access_token));
    }

    public function test_update_with_explicit_null_preserves_ciphertext(): void
    {
        $originalSecrets = [
            'client_secret' => 'client_sec_null_check_1',
            'access_token' => 'access_tok_null_check_2',
        ];

        $integration = $this->createSampleIntegration($originalSecrets);
        $rawBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        // Updating with null credentials (e.g., frontend untouched form field)
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Actualizando Con Null Credentials',
            'client_secret' => null,
            'access_token' => null,
        ]);

        $response->assertStatus(200);

        $rawAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();

        $this->assertSame($rawBefore->client_secret, $rawAfter->client_secret);
        $this->assertSame($rawBefore->access_token, $rawAfter->access_token);
        $this->assertSame($originalSecrets['client_secret'], Crypt::decryptString($rawAfter->client_secret));
        $this->assertSame($originalSecrets['access_token'], Crypt::decryptString($rawAfter->access_token));
    }

    public function test_update_with_empty_string_preserves_existing_ciphertext(): void
    {
        $originalSecrets = [
            'webhook_secret' => 'webhook_secret_preserve_on_empty_string',
        ];

        $integration = $this->createSampleIntegration($originalSecrets);
        $rawBefore = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();
        $this->assertNotNull($rawBefore->webhook_secret);

        // Sending empty string (converted to null by ConvertEmptyStringsToNull) must NOT wipe the secret,
        // defending against accidental credential deletion when UI leaves input blank.
        $response = $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'name' => 'Actualizando Nombre Con Input Vacio',
            'webhook_secret' => '',
        ]);

        $response->assertStatus(200);

        $rawAfter = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();
        $this->assertSame($rawBefore->webhook_secret, $rawAfter->webhook_secret, 'Empty string must preserve secret');
        $this->assertSame($originalSecrets['webhook_secret'], Crypt::decryptString($rawAfter->webhook_secret));
        $this->assertTrue($response->json('integration.has_webhook_secret'));
    }

    public function test_manager_cache_purged_on_update_and_destroy(): void
    {
        /** @var EcommerceManager $manager */
        $manager = app(EcommerceManager::class);

        $integration = $this->createSampleIntegration([
            'access_token' => 'token_v1',
        ]);

        // Resolve adapter (populates manager cache)
        $adapter1 = $manager->forIntegration($integration);
        $this->assertSame($adapter1, $manager->forIntegration($integration));

        // Update integration via API -> must invoke purge
        $this->actingAsAdmin()->putJson("/api/ecommerce/integrations/{$integration->id}", [
            'access_token' => 'token_v2_rotated',
        ])->assertStatus(200);

        // Resolving adapter again must instantiate fresh adapter with updated configuration
        $adapter2 = $manager->forIntegration($integration->fresh());
        $this->assertNotSame($adapter1, $adapter2, 'Adapter was not purged from manager cache on update');

        // Delete integration via API -> must invoke purge
        $this->actingAsAdmin()->deleteJson("/api/ecommerce/integrations/{$integration->id}")
            ->assertStatus(200);

        // Cache must have been purged for integration id
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('adapters');
        $property->setAccessible(true);
        $adapters = $property->getValue($manager);
        $this->assertArrayNotHasKey('integration_'.$integration->id, $adapters, 'Deleted integration still in manager cache');
    }

    public function test_mass_assignment_immunity_on_system_fields(): void
    {
        // Try to inject system/internal fields through store
        $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', [
            'platform' => 'mercadolibre',
            'name' => 'Inyeccion Mass Assignment',
            'id' => 77777,
            'last_health_status' => 'healthy',
            'consecutive_failures' => 999,
            'circuit_breaker_tripped_at' => now()->toISOString(),
        ]);

        $response->assertStatus(201);
        $createdId = $response->json('integration.id');
        $this->assertNotEquals(77777, $createdId, 'Attack failed: id cannot be forced via mass assignment');

        $row = DB::table('ecommerce_integrations')->where('id', $createdId)->first();
        $this->assertEquals(0, $row->consecutive_failures, 'consecutive_failures cannot be forged via store');
        $this->assertNull($row->circuit_breaker_tripped_at, 'circuit_breaker_tripped_at cannot be forged via store');
    }

    public function test_sql_injection_in_route_parameter_handled_safely(): void
    {
        $response = $this->actingAsAdmin()->getJson("/api/ecommerce/integrations/1' OR '1'='1");
        $response->assertStatus(404);
    }

    public function test_boundary_validation_on_all_numeric_fields(): void
    {
        $invalidPayloads = [
            ['rate_limit_per_minute' => 0, 'error' => 'rate_limit_per_minute'],
            ['rate_limit_per_minute' => -1, 'error' => 'rate_limit_per_minute'],
            ['rate_limit_per_minute' => 601, 'error' => 'rate_limit_per_minute'],
            ['batch_size' => 0, 'error' => 'batch_size'],
            ['batch_size' => -10, 'error' => 'batch_size'],
            ['batch_size' => 201, 'error' => 'batch_size'],
            ['timeout_seconds' => 4, 'error' => 'timeout_seconds'],
            ['timeout_seconds' => 0, 'error' => 'timeout_seconds'],
            ['timeout_seconds' => 121, 'error' => 'timeout_seconds'],
        ];

        foreach ($invalidPayloads as $case) {
            $response = $this->actingAsAdmin()->postJson('/api/ecommerce/integrations', array_merge([
                'platform' => 'mercadolibre',
                'name' => 'Boundary Test',
            ], $case));

            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$case['error']]);
        }
    }

    public function test_test_connection_diagnostics_never_leak_bearer_tokens_or_secrets(): void
    {
        $plainToken = 'APP_USR-secret-meli-connectivity-token';
        $integration = $this->createSampleIntegration([
            'platform' => 'mercadolibre',
            'access_token' => $plainToken,
        ]);

        // Mock error response from MercadoLibre API
        Http::fake([
            'https://api.mercadolibre.com/*' => Http::response([
                'message' => 'Unauthorized test probe',
                'error' => 'invalid_token',
                'status' => 401,
            ], 401),
        ]);

        $response = $this->actingAsAdmin()->postJson("/api/ecommerce/integrations/{$integration->id}/test-connection");

        $response->assertStatus(400);
        $this->assertStringNotContainsString(
            $plainToken,
            $response->getContent(),
            'Test connection error response leaked plaintext token'
        );
        $this->assertNoPlaintextLeaked($response->json(), [$plainToken]);
    }
}
