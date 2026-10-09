<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Limpieza de cualquier archivo o carpeta generada en storage/app/private/afip
        $testAfipDir = storage_path('app/private/afip/20123456789');
        if (is_dir($testAfipDir)) {
            $files = glob($testAfipDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($testAfipDir);
        }

        $baseAfipDir = storage_path('app/private/afip');
        if (is_dir($baseAfipDir) && count(scandir($baseAfipDir)) === 2) {
            @rmdir($baseAfipDir);
        }

        parent::tearDown();
    }

    /**
     * Helper para generar pares de certificados X.509 y claves privadas RSA válidos para tests.
     */
    protected function generateSelfSignedCert(string $commonName = 'test'): array
    {
        $config = [
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $candidateConf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (file_exists($candidateConf)) {
            $config['config'] = $candidateConf;
        }

        $privkey = openssl_pkey_new($config);
        if (!$privkey) {
            throw new \RuntimeException('Error al generar clave privada RSA: ' . openssl_error_string());
        }

        $dn = [
            'countryName' => 'AR',
            'stateOrProvinceName' => 'CABA',
            'localityName' => 'CABA',
            'organizationName' => 'POS Test',
            'commonName' => $commonName,
        ];

        $csrConfig = ['digest_alg' => 'sha256'];
        if (isset($config['config'])) {
            $csrConfig['config'] = $config['config'];
        }

        $csr = openssl_csr_new($dn, $privkey, $csrConfig);
        if (!$csr) {
            throw new \RuntimeException('Error al generar CSR: ' . openssl_error_string());
        }

        $x509 = openssl_csr_sign($csr, null, $privkey, 365, $csrConfig);
        if (!$x509) {
            throw new \RuntimeException('Error al firmar certificado X.509: ' . openssl_error_string());
        }

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privkey, $pkeyOut, null, isset($config['config']) ? ['config' => $config['config']] : null);

        return [
            'cert' => $certOut,
            'key' => $pkeyOut,
        ];
    }

    /**
     * 1. GET /api/settings público no debe filtrar datos sensibles ni credenciales de integraciones.
     */
    public function test_public_settings_endpoint_does_not_leak_sensitive_credentials(): void
    {
        // Configurar credenciales sensibles y rutas privadas
        BusinessSetting::setSecret('mp_access_token', 'APP_USR-test-token-123456');
        BusinessSetting::setSecret('mp_webhook_secret', 'whsec-test-secret-789');
        BusinessSetting::create(['key' => 'afip_key_path', 'value' => 'afip/20123456789/cert.key']);
        BusinessSetting::create(['key' => 'company_name', 'value' => 'POS Test Business']);
        BusinessSetting::create(['key' => 'address', 'value' => 'Av. Corrientes 1234']);

        // Llamada pública sin token de sesión ni autorización
        $response = $this->getJson('/api/settings');

        $response->assertStatus(200);
        $response->assertJsonPath('company_name', 'POS Test Business');
        $response->assertJsonPath('address', 'Av. Corrientes 1234');
        $response->assertJsonPath('grace_period_hours', 72);
        $this->assertNotNull($response->json('server_time'));

        // Aserciones de seguridad: NINGUNA clave sensible o ruta privada puede estar presente
        $data = $response->json();
        $this->assertArrayNotHasKey('mp_access_token', $data, 'Vulnerabilidad: mp_access_token fue expuesto en endpoint público');
        $this->assertArrayNotHasKey('mp_webhook_secret', $data, 'Vulnerabilidad: mp_webhook_secret fue expuesto en endpoint público');
        $this->assertArrayNotHasKey('afip_key_path', $data, 'Vulnerabilidad: afip_key_path fue expuesto en endpoint público');
    }

    /**
     * 2. GET /api/settings/integrations debe requerir autenticación (401 si no hay token).
     */
    public function test_integrations_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/settings/integrations');

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'SESSION_MISSING');
    }

    /**
     * 3. GET /api/settings/integrations exige permiso manage_settings o PIN de administrador in-situ.
     */
    public function test_integrations_endpoint_requires_manage_settings_permission_or_pin(): void
    {
        // Crear usuario cajero sin permiso de configuración
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'test-cashier-token-' . uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        // Crear administrador con PIN conocido
        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('4321'),
        ]);

        // Intento 1: Cajero sin PIN -> 403 PIN_REQUIRED
        $responseWithoutPin = $this->withHeader('X-Session-Token', $cashierToken)
            ->getJson('/api/settings/integrations');

        $responseWithoutPin->assertStatus(403);
        $responseWithoutPin->assertJsonPath('error_code', 'PIN_REQUIRED');

        // Intento 2: Cajero autorizando in-situ con PIN de administrador -> 200 OK
        $responseWithPin = $this->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '4321')
            ->getJson('/api/settings/integrations');

        $responseWithPin->assertStatus(200);
    }

    /**
     * 4. GET /api/settings/integrations devuelve tokens enmascarados para el administrador.
     */
    public function test_integrations_endpoint_returns_masked_credentials_for_admin(): void
    {
        $rawToken = 'APP_USR-1234567890-abcdef';
        BusinessSetting::setSecret('mp_access_token', $rawToken);
        BusinessSetting::setSecret('mp_webhook_secret', 'secret-key-xyz-987654');

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');

        $response->assertStatus(200);
        $maskedMpToken = $response->json('mp_access_token');

        // Debe contener asteriscos y no revelar el token original en claro
        $this->assertNotNull($maskedMpToken);
        $this->assertStringContainsString('****', $maskedMpToken);
        $this->assertNotEquals($rawToken, $maskedMpToken);
        $this->assertTrue($response->json('mp_has_access_token'));
        $this->assertTrue($response->json('mp_has_webhook_secret'));

        // Compatibilidad con objeto anidado mercado_pago
        $this->assertEquals($maskedMpToken, $response->json('mercado_pago.mp_access_token'));
    }

    /**
     * 5. Guardar configuraciones encripta las credenciales en la base de datos en reposo.
     */
    public function test_saving_integrations_encrypts_credentials_in_database(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rawSecret = 'APP_USR-my-raw-secret-12345';

        $response = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'mp_access_token' => $rawSecret,
            'mp_qr_enabled' => true,
            'mp_point_device_id' => 'POINT_DEVICE_01',
        ]);

        $response->assertStatus(200);

        // Verificar directamente en la tabla de la base de datos
        $dbRow = DB::table('business_settings')->where('key', 'mp_access_token')->first();
        $this->assertNotNull($dbRow, 'La fila mp_access_token no fue guardada en la base de datos');
        $this->assertNotEquals($rawSecret, $dbRow->value, 'Fallo de seguridad: El token se guardó en texto plano');

        // Verificar que puede desencriptarse correctamente con la clave de aplicación
        $decrypted = Crypt::decryptString($dbRow->value);
        $this->assertEquals($rawSecret, $decrypted);

        // Verificar que el helper getSecret() lo resuelve de forma transparente
        $this->assertEquals($rawSecret, BusinessSetting::getSecret('mp_access_token'));
    }

    /**
     * 6. Actualizar con un token enmascarado preserva el secreto original sin sobrescribirlo con asteriscos.
     */
    public function test_updating_with_masked_token_preserves_existing_encrypted_secret(): void
    {
        $originalSecret = 'APP_USR-original-secret-production';
        BusinessSetting::setSecret('mp_access_token', $originalSecret);

        $admin = User::factory()->create(['role' => 'admin']);

        // El cliente envía el token enmascarado recibido previamente (ej: APP_USR-****tion)
        $response = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'mp_access_token' => 'APP_USR-****tion',
            'mp_point_device_id' => 'POINT_DEVICE_99',
        ]);

        $response->assertStatus(200);

        // El secreto cifrado en la base de datos debe permanecer intacto
        $this->assertEquals($originalSecret, BusinessSetting::getSecret('mp_access_token'));
        $this->assertEquals('POINT_DEVICE_99', BusinessSetting::where('key', 'mp_point_device_id')->value('value'));
    }

    /**
     * 7. POST /api/settings/afip/certificates rechaza pares donde la clave no coincide con el certificado.
     */
    public function test_upload_afip_certificates_rejects_mismatched_key_and_cert(): void
    {
        $pair1 = $this->generateSelfSignedCert('cuit_pair_1');
        $pair2 = $this->generateSelfSignedCert('cuit_pair_2');

        $admin = User::factory()->create(['role' => 'admin']);

        // Enviamos el certificado del par 1 con la clave privada del par 2
        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/afip/certificates', [
            'cuit' => '20123456789',
            'cert_content' => $pair1['cert'],
            'key_content' => $pair2['key'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'CERT_KEY_MISMATCH');
    }

    /**
     * 8. POST /api/settings/afip/certificates guarda par válido en el disco privado del sistema.
     */
    public function test_upload_afip_certificates_saves_valid_pair_to_private_storage(): void
    {
        $cuit = '20123456789';
        $validPair = $this->generateSelfSignedCert('cuit_' . $cuit);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/afip/certificates', [
            'cuit' => $cuit,
            'cert_content' => $validPair['cert'],
            'key_content' => $validPair['key'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('afip_has_cert', true);
        $response->assertJsonPath('afip_has_key', true);

        // Verificar almacenamiento físico aislado en app/private/afip/{cuit}/
        $certPath = storage_path("app/private/afip/{$cuit}/cert.crt");
        $keyPath = storage_path("app/private/afip/{$cuit}/cert.key");

        $this->assertFileExists($certPath);
        $this->assertFileExists($keyPath);
        $this->assertEquals(trim($validPair['cert']), trim(file_get_contents($certPath)));
        $this->assertEquals(trim($validPair['key']), trim(file_get_contents($keyPath)));

        // Verificar persistencia de rutas en business_settings
        $this->assertEquals("afip/{$cuit}/cert.crt", BusinessSetting::where('key', 'afip_cert_path')->value('value'));
        $this->assertEquals("afip/{$cuit}/cert.key", BusinessSetting::where('key', 'afip_key_path')->value('value'));
    }

    /**
     * 9. POST /api/settings/integrations/mercadopago/test prueba conexión con token guardado y retorna 200 sin filtrar secretos.
     */
    public function test_mercadopago_test_connection_successful_with_saved_token(): void
    {
        BusinessSetting::setSecret('mp_access_token', 'APP_USR-test-valid-saved-token-1234');
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response([
                'id' => 98765432,
                'nickname' => 'TIENDA_OFICIAL_TEST',
                'first_name' => 'POS',
                'last_name' => 'Demo',
            ], 200),
        ]);

        // Llamada enviando máscara de asteriscos o vacío
        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => 'APP_USR-****1234',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('collector_id', 98765432);
        $response->assertJsonPath('nickname', 'TIENDA_OFICIAL_TEST');

        // Seguridad: el token no debe estar en la respuesta
        $this->assertStringNotContainsString('APP_USR-test-valid-saved-token-1234', $response->getContent());
    }

    /**
     * 10. POST /api/settings/integrations/mercadopago/test usa token directo del request si no está enmascarado.
     */
    public function test_mercadopago_test_connection_with_request_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response([
                'id' => 11223344,
                'nickname' => 'NUEVO_TOKEN_USER',
            ], 200),
        ]);

        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => 'APP_USR-raw-new-token-9999',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('collector_id', 11223344);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer APP_USR-raw-new-token-9999');
        });
    }

    /**
     * 11. POST /api/settings/integrations/mercadopago/test rechaza token inválido con 400 sin exponer credencial.
     */
    public function test_mercadopago_test_connection_fails_on_invalid_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response([
                'message' => 'Invalid credentials',
                'error' => 'unauthorized',
                'status' => 401,
            ], 401),
        ]);

        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => 'APP_USR-invalid-token-0000',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('inválido', $response->json('message'));
        $this->assertStringNotContainsString('APP_USR-invalid-token-0000', $response->getContent());
    }

    /**
     * 12. POST /api/settings/integrations/mercadopago/test devuelve 422 cuando no hay token configurado.
     */
    public function test_mercadopago_test_connection_fails_when_no_token_configured(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /**
     * 13. POST /api/settings/integrations/mercadopago/test requiere autenticación y permiso manage_settings.
     */
    public function test_mercadopago_test_endpoint_requires_auth_and_permission(): void
    {
        // 1. Sin autenticación -> 401
        $unauthResponse = $this->postJson('/api/settings/integrations/mercadopago/test');
        $unauthResponse->assertStatus(401);

        // 2. Cajero sin permiso ni PIN -> 403
        $cashier = User::factory()->create(['role' => 'cashier', 'permissions' => ['pos']]);
        $cashierToken = 'session-cashier-' . uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        $forbiddenResponse = $this->withHeader('X-Session-Token', $cashierToken)
            ->postJson('/api/settings/integrations/mercadopago/test');
        $forbiddenResponse->assertStatus(403);
    }

    /**
     * 14. PUT /api/settings/integrations recorta espacios en tokens y limpia a null si es whitespace.
     */
    public function test_updating_integrations_trims_tokens_and_clears_whitespace(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Guardar con espacios alrededor
        $response = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'mp_access_token' => '   APP_USR-test-trimmed-token-9999   ',
            'mp_webhook_secret' => '   whsec_test_secret_1234   ',
            'afip_pto_vta' => 3,
        ]);
        $response->assertStatus(200);

        // Se deben guardar sin espacios
        $this->assertEquals('APP_USR-test-trimmed-token-9999', BusinessSetting::getSecret('mp_access_token'));
        $this->assertEquals('whsec_test_secret_1234', BusinessSetting::getSecret('mp_webhook_secret'));

        // Limpiar enviando espacios
        $clearResponse = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'mp_access_token' => '     ',
            'mp_webhook_secret' => '',
            'afip_pto_vta' => null,
        ]);
        $clearResponse->assertStatus(200);

        $this->assertNull(BusinessSetting::getSecret('mp_access_token'));
        $this->assertNull(BusinessSetting::getSecret('mp_webhook_secret'));

        // Verificar que GET /settings/integrations reporta afip_pto_vta como null (NO como 0)
        $getResp = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');
        $getResp->assertStatus(200);
        $this->assertNull($getResp->json('afip_pto_vta'));
        $this->assertFalse($getResp->json('mp_has_access_token'));
        $this->assertFalse($getResp->json('mp_has_webhook_secret'));
    }

    /**
     * 15. POST /api/settings/integrations/mercadopago/test con token con espacios hace trim antes de consultar API.
     */
    public function test_mercadopago_test_connection_trims_request_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response([
                'id' => 55443322,
                'nickname' => 'SPACES_TOKEN_USER',
            ], 200),
        ]);

        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => '   APP_USR-raw-with-spaces-8888   ',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer APP_USR-raw-with-spaces-8888');
        });
    }

    /**
     * 16. PUT /api/settings no filtra rutas privadas de certificados en el array settings devuelto.
     */
    public function test_put_settings_does_not_leak_afip_private_paths(): void
    {
        BusinessSetting::create(['key' => 'afip_key_path', 'value' => 'afip/20123456789/cert.key']);
        BusinessSetting::create(['key' => 'afip_cert_path', 'value' => 'afip/20123456789/cert.crt']);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->putJson('/api/settings', [
            'company_name' => 'Comercio Seguro',
        ]);

        $response->assertStatus(200);
        $settings = $response->json('settings');

        $this->assertArrayNotHasKey('afip_key_path', $settings, 'Vulnerabilidad: afip_key_path fue retornado en PUT /api/settings');
        $this->assertArrayNotHasKey('afip_cert_path', $settings, 'Vulnerabilidad: afip_cert_path fue retornado en PUT /api/settings');
    }

    /**
     * 17. GET /api/settings/integrations detecta certificados incluso con CUIT formateado con guiones y almacena dígitos limpios.
     */
    public function test_integrations_detects_cert_with_formatted_cuit_and_stored_paths(): void
    {
        $cuit = '20123456789';
        $validPair = $this->generateSelfSignedCert('cuit_' . $cuit);

        $admin = User::factory()->create(['role' => 'admin']);

        // Guardar certificados válidos
        $this->actingAsAdmin($admin)->postJson('/api/settings/afip/certificates', [
            'cuit' => $cuit,
            'cert_content' => $validPair['cert'],
            'key_content' => $validPair['key'],
        ])->assertStatus(200);

        // Actualizar CUIT comercial con guiones
        $putResp = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'afip_cuit' => '  20-12345678-9  ',
        ]);
        $putResp->assertStatus(200);

        // La base de datos debe almacenar solo dígitos limpios
        $this->assertEquals($cuit, BusinessSetting::where('key', 'afip_cuit')->value('value'));

        // El endpoint GET /settings/integrations debe reconocer la presencia de cert y key
        $getResp = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');
        $getResp->assertStatus(200);
        $this->assertTrue($getResp->json('afip_has_cert'));
        $this->assertTrue($getResp->json('afip_has_key'));
        $this->assertEquals($cuit, $getResp->json('afip_cuit'));
    }

    /**
     * 18. POST /api/settings/integrations/mercadopago/test falla con 422 si se envía token vacío aunque exista secreto guardado en BD.
     */
    public function test_mercadopago_test_connection_fails_with_explicit_empty_token_even_when_secret_in_db(): void
    {
        BusinessSetting::setSecret('mp_access_token', 'APP_USR-test-valid-saved-token-1234');
        $admin = User::factory()->create(['role' => 'admin']);

        // Enviando token vacío explícito
        $response = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        // Enviando token con puros espacios
        $spacesResp = $this->actingAsAdmin($admin)->postJson('/api/settings/integrations/mercadopago/test', [
            'mp_access_token' => '   ',
        ]);

        $spacesResp->assertStatus(422);
        $spacesResp->assertJsonPath('success', false);
    }

    /**
     * 19. Cambiar CUIT a uno sin certificados instalados resetea afip_has_cert a false y limpia fecha de vencimiento.
     */
    public function test_changing_afip_cuit_to_unconfigured_cuit_resets_cert_status_and_clears_expiration(): void
    {
        $cuitA = '20123456789';
        $validPair = $this->generateSelfSignedCert('cuit_' . $cuitA);

        $admin = User::factory()->create(['role' => 'admin']);

        // Subir certificados válidos para CUIT A
        $this->actingAsAdmin($admin)->postJson('/api/settings/afip/certificates', [
            'cuit' => $cuitA,
            'cert_content' => $validPair['cert'],
            'key_content' => $validPair['key'],
        ])->assertStatus(200);

        // Verificar que CUIT A tiene certificados activos y fecha de vencimiento
        $getA = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');
        $getA->assertStatus(200);
        $this->assertTrue($getA->json('afip_has_cert'));
        $this->assertTrue($getA->json('afip_has_key'));
        $this->assertNotNull($getA->json('afip_cert_expires_at'));

        // Cambiar CUIT comercial a CUIT B (para el cual no se subieron certificados)
        $cuitB = '20999999999';
        $putResp = $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'afip_cuit' => $cuitB,
        ]);
        $putResp->assertStatus(200);

        // GET /settings/integrations NO debe reportar falsamente que CUIT B tiene certificados de CUIT A
        $getB = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');
        $getB->assertStatus(200);
        $this->assertEquals($cuitB, $getB->json('afip_cuit'));
        $this->assertFalse($getB->json('afip_has_cert'));
        $this->assertFalse($getB->json('afip_has_key'));
        $this->assertNull($getB->json('afip_cert_expires_at'));
    }

    /**
     * 20. Limpiar CUIT (a null o vacío) resetea afip_has_cert a false y limpia fecha de vencimiento.
     */
    public function test_clearing_afip_cuit_resets_cert_status_and_expires_at(): void
    {
        $cuit = '20123456789';
        $validPair = $this->generateSelfSignedCert('cuit_' . $cuit);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAsAdmin($admin)->postJson('/api/settings/afip/certificates', [
            'cuit' => $cuit,
            'cert_content' => $validPair['cert'],
            'key_content' => $validPair['key'],
        ])->assertStatus(200);

        // Limpiar el CUIT
        $this->actingAsAdmin($admin)->putJson('/api/settings/integrations', [
            'afip_cuit' => '',
        ])->assertStatus(200);

        $getResp = $this->actingAsAdmin($admin)->getJson('/api/settings/integrations');
        $getResp->assertStatus(200);
        $this->assertNull($getResp->json('afip_cuit'));
        $this->assertFalse($getResp->json('afip_has_cert'));
        $this->assertFalse($getResp->json('afip_has_key'));
        $this->assertNull($getResp->json('afip_cert_expires_at'));
    }

    /**
     * 21. PUT /api/settings no puede sobrescribir ni corromper afip_key_path ni afip_cert_path.
     */
    public function test_put_settings_cannot_tamper_afip_private_paths(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'afip_key_path'], ['value' => 'afip/20123456789/cert.key']);
        BusinessSetting::updateOrCreate(['key' => 'afip_cert_path'], ['value' => 'afip/20123456789/cert.crt']);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->putJson('/api/settings', [
            'afip_key_path' => 'malicious/tampered/path.key',
            'afip_cert_path' => 'malicious/tampered/path.crt',
            'company_name' => 'Comercio Seguro',
        ]);

        $response->assertStatus(200);

        // Las rutas originales no deben haber sido modificadas
        $this->assertEquals('afip/20123456789/cert.key', BusinessSetting::where('key', 'afip_key_path')->value('value'));
        $this->assertEquals('afip/20123456789/cert.crt', BusinessSetting::where('key', 'afip_cert_path')->value('value'));
    }

    /**
     * 22. PUT /api/settings no puede alterar claves críticas de licencia ni de sistema.
     */
    public function test_put_settings_cannot_tamper_license_and_system_keys(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => json_encode(['multiple_prices' => false])]);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'starter']);
        BusinessSetting::updateOrCreate(['key' => 'installation_id'], ['value' => 'valid-uuid-1234']);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAsAdmin($admin)->putJson('/api/settings', [
            'license_features_dict' => ['multiple_prices' => true, 'unlimited_users' => true],
            'app_plan' => 'enterprise_hacked',
            'installation_id' => 'forged-uuid',
            'company_name' => 'Comercio Auditado',
        ]);

        $response->assertStatus(200);

        // Los valores críticos de licencia deben permanecer intactos
        $this->assertEquals(json_encode(['multiple_prices' => false]), BusinessSetting::where('key', 'license_features_dict')->value('value'));
        $this->assertEquals('starter', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertEquals('valid-uuid-1234', BusinessSetting::where('key', 'installation_id')->value('value'));

        // La configuración legítima sí debe actualizarse
        $this->assertEquals('Comercio Auditado', BusinessSetting::where('key', 'company_name')->value('value'));
    }
}


