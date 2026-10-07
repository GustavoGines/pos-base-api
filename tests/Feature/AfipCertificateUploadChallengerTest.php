<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Afip\AfipWsaaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AfipCertificateUploadChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $cashierUser;
    protected string $validCertPem = '';
    protected string $validKeyPem = '';
    protected string $encryptedKeyPem = '';
    protected string $encryptedKeyPassphrase = 'SuperSecretPassphrase123!';
    protected string $mismatchedKeyPem = '';

    protected function actingAsCashierWithUser(?User $user = null, array $permissions = []): User
    {
        $token = 'cashier-token-' . uniqid();
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('5678'),
                'permissions' => $permissions,
            ]);
        }
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);
        $this->cashierUser = User::factory()->create(['role' => 'cashier']);

        $this->generateCryptographicTestPairs();
    }

    protected function tearDown(): void
    {
        // Cleanup storage afip directories created during tests
        $afipDir = storage_path('app/private/afip');
        if (File::exists($afipDir)) {
            File::deleteDirectory($afipDir);
        }

        parent::tearDown();
    }

    /**
     * Generates valid matching RSA key pair & X.509 cert, an encrypted key, and a mismatched key.
     */
    protected function generateCryptographicTestPairs(): void
    {
        $wsaa = new AfipWsaaService();
        $wsaa->ensureOpenSslConfig();

        $cnfPath = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        $configArgs = file_exists($cnfPath) ? ['config' => $cnfPath] : [];

        $privKeyArgs = array_merge($configArgs, [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        // 1. Primary key & cert
        $privKey = openssl_pkey_new($privKeyArgs);
        if ($privKey === false) {
            throw new RuntimeException('Error generating primary private key');
        }

        $dn = [
            'countryName'            => 'AR',
            'stateOrProvinceName'    => 'Buenos Aires',
            'localityName'           => 'CABA',
            'organizationName'       => 'Empresa Testing AFIP',
            'commonName'             => 'cert-test-afip',
        ];

        $csr = openssl_csr_new($dn, $privKey, array_merge($configArgs, ['digest_alg' => 'sha256']));
        $x509 = openssl_csr_sign($csr, null, $privKey, 365, array_merge($configArgs, ['digest_alg' => 'sha256']));

        openssl_x509_export($x509, $this->validCertPem);
        openssl_pkey_export($privKey, $this->validKeyPem, null, $configArgs);

        // 2. Encrypted version of primary key
        openssl_pkey_export($privKey, $this->encryptedKeyPem, $this->encryptedKeyPassphrase, $configArgs);

        // 3. Mismatched key
        $mismatchedKey = openssl_pkey_new($privKeyArgs);
        openssl_pkey_export($mismatchedKey, $this->mismatchedKeyPem, null, $configArgs);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Unusual File Extensions & File Parsing Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unusual_extension_binary_exe_is_rejected_with_422_invalid_certificate(): void
    {
        $exeFile = UploadedFile::fake()->createWithContent('malicious.exe', "\x4D\x5A\x90\x00BinaryExeContent");
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $exeFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'INVALID_CERTIFICATE',
            ]);
    }

    public function test_unusual_extension_pdf_file_is_rejected_with_422_invalid_certificate(): void
    {
        $pdfFile = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nSome pdf content");
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $pdfFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'INVALID_CERTIFICATE',
            ]);
    }

    public function test_valid_certificate_with_unusual_extension_is_accepted_and_normalized_to_crt(): void
    {
        // Many users save certs as .cer, .pem, or .txt
        $cerFile = UploadedFile::fake()->createWithContent('afip_cert.cer', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('afip_key.pem', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $cerFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'afip_cuit' => '30712345678',
                'afip_has_cert' => true,
                'afip_has_key' => true,
            ]);

        // Verify storage file names were strictly normalized to cert.crt and cert.key
        $this->assertFileExists(storage_path('app/private/afip/30712345678/cert.crt'));
        $this->assertFileExists(storage_path('app/private/afip/30712345678/cert.key'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Empty Byte Arrays & Missing Content Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_empty_zero_byte_certificate_file_returns_422_missing_cert_or_key(): void
    {
        $emptyCert = UploadedFile::fake()->createWithContent('empty.crt', '');
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $emptyCert,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'MISSING_CERT_OR_KEY',
            ]);
    }

    public function test_empty_zero_byte_private_key_file_returns_422_missing_cert_or_key(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $emptyKey = UploadedFile::fake()->createWithContent('empty.key', '');

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $emptyKey,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'MISSING_CERT_OR_KEY',
            ]);
    }

    public function test_empty_content_strings_return_422_missing_cert_or_key(): void
    {
        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_content' => '',
            'key_content' => '',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'MISSING_CERT_OR_KEY',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Passphrase Handling Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_encrypted_private_key_without_passphrase_returns_422_invalid_private_key(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->encryptedKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
            // key_passphrase omitted
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'INVALID_PRIVATE_KEY',
            ]);
    }

    public function test_encrypted_private_key_with_wrong_passphrase_returns_422_invalid_private_key(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->encryptedKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
            'key_passphrase' => 'TotallyWrongPassword999',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'INVALID_PRIVATE_KEY',
            ]);
    }

    public function test_encrypted_private_key_with_correct_passphrase_is_accepted_and_saved(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->encryptedKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
            'key_passphrase' => $this->encryptedKeyPassphrase,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'afip_cuit' => '30712345678',
                'afip_has_cert' => true,
                'afip_has_key' => true,
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. CUIT Special Characters & Sanitization Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cuit_with_dashes_and_dots_is_sanitized_to_digits_only(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '  30-71.234.567-8  ',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'afip_cuit' => '30712345678',
            ]);

        $this->assertEquals('30712345678', BusinessSetting::where('key', 'afip_cuit')->value('value'));
        $this->assertFileExists(storage_path('app/private/afip/30712345678/cert.crt'));
    }

    public function test_cuit_with_path_traversal_payload_is_stripped_to_digits_only_safely(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        // Path traversal payload
        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '../../../../etc/passwd/30712345678',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'afip_cuit' => '30712345678',
            ]);

        // Ensure file was NOT written outside storage
        $this->assertFalse(file_exists(base_path('etc')));
        $this->assertFileExists(storage_path('app/private/afip/30712345678/cert.crt'));
    }

    public function test_cuit_with_purely_non_numeric_characters_returns_422_invalid_cuit(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => 'ABC-DEF-$%^&*()',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'INVALID_CUIT',
            ]);
    }

    public function test_missing_cuit_returns_422_validation_error(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cert_file' => $certFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Cryptographic Mismatch & Expiration Parsing Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mismatched_private_key_returns_422_cert_key_mismatch(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $mismatchedKeyFile = UploadedFile::fake()->createWithContent('cert.key', $this->mismatchedKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $mismatchedKeyFile,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'CERT_KEY_MISMATCH',
            ]);
    }

    public function test_successful_upload_persists_expiration_date_in_business_settings(): void
    {
        $certFile = UploadedFile::fake()->createWithContent('cert.crt', $this->validCertPem);
        $keyFile = UploadedFile::fake()->createWithContent('cert.key', $this->validKeyPem);

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
            'cert_file' => $certFile,
            'key_file' => $keyFile,
        ]);

        $response->assertStatus(200);

        $expiresAt = BusinessSetting::where('key', 'afip_cert_expires_at')->value('value');
        $this->assertNotNull($expiresAt);
        $this->assertNotEmpty($expiresAt);

        // Verification endpoint /api/settings/integrations reflects has_cert and expiration
        $integrationsRes = $this->actingAsAdmin($this->adminUser)->getJson('/api/settings/integrations');
        $integrationsRes->assertStatus(200)
            ->assertJson([
                'afip_has_cert' => true,
                'afip_has_key' => true,
                'afip_cert_expires_at' => $expiresAt,
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Security & Permission Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
        ]);

        $response->assertStatus(401);
    }

    public function test_cashier_without_manage_settings_permission_is_rejected_with_403(): void
    {
        $this->actingAsCashierWithUser($this->cashierUser, []);
        $response = $this->postJson('/api/settings/afip/upload-certificates', [
            'cuit' => '30712345678',
        ]);

        $response->assertStatus(403);
    }
}
