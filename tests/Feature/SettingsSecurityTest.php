<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
}
