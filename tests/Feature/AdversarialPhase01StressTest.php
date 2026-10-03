<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdversarialPhase01StressTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Cleanup generated test AFIP directories
        $testAfipDir = storage_path('app/private/afip/20999999999');
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

    protected function generateTestCertPair(string $commonName = 'stress_test'): array
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
            throw new \RuntimeException('Failed to generate RSA private key');
        }

        $dn = [
            'countryName' => 'AR',
            'stateOrProvinceName' => 'CABA',
            'localityName' => 'CABA',
            'organizationName' => 'Adversarial Test',
            'commonName' => $commonName,
        ];

        $csrConfig = ['digest_alg' => 'sha256'];
        if (isset($config['config'])) {
            $csrConfig['config'] = $config['config'];
        }

        $csr = openssl_csr_new($dn, $privkey, $csrConfig);
        $x509 = openssl_csr_sign($csr, null, $privkey, 365, $csrConfig);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privkey, $pkeyOut, null, isset($config['config']) ? ['config' => $config['config']] : null);

        return ['cert' => $certOut, 'key' => $pkeyOut];
    }

    // =========================================================================
    // SECTION 1: PHASE 0 SECURITY ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * 1.1 Stress Test: Can unauthenticated callers retrieve ANY sensitive key via GET /api/settings?
     */
    public function test_adversarial_unauthenticated_cannot_leak_sensitive_keys_via_get_settings(): void
    {
        // Populate DB with sensitive keys, internal filepaths, and private tokens
        BusinessSetting::setSecret('mp_access_token', 'APP_USR-secret-mp-token-xyz-123');
        BusinessSetting::setSecret('mp_webhook_secret', 'whsec-super-secret-hmac-key');
        BusinessSetting::create(['key' => 'afip_cert_path', 'value' => 'afip/20999999999/cert.crt']);
        BusinessSetting::create(['key' => 'afip_key_path', 'value' => 'afip/20999999999/cert.key']);
        BusinessSetting::create(['key' => 'afip_cert_expires_at', 'value' => '2028-12-31 23:59:59']);
        BusinessSetting::create(['key' => 'arbitrary_internal_secret', 'value' => 'internal_db_password_123']);
        BusinessSetting::create(['key' => 'company_name', 'value' => 'Public Business Name']);
        BusinessSetting::create(['key' => 'app_plan', 'value' => 'premium']);

        // Test normal unauthenticated request
        $response = $this->getJson('/api/settings');
        $response->assertStatus(200);
        $data = $response->json();

        // Adversarial verifications: None of the secret keys must be present
        $this->assertArrayNotHasKey('mp_access_token', $data);
        $this->assertArrayNotHasKey('mp_webhook_secret', $data);
        $this->assertArrayNotHasKey('afip_cert_path', $data);
        $this->assertArrayNotHasKey('afip_key_path', $data);
        $this->assertArrayNotHasKey('arbitrary_internal_secret', $data);
        $this->assertEquals('Public Business Name', $data['company_name']);

        // Test with parameter tampering / query pollution
        $tamperedResponse = $this->getJson('/api/settings?include_secrets=1&all=true&debug=1&key=mp_access_token');
        $tamperedResponse->assertStatus(200);
        $tamperedData = $tamperedResponse->json();
        $this->assertArrayNotHasKey('mp_access_token', $tamperedData);
        $this->assertArrayNotHasKey('mp_webhook_secret', $tamperedData);
        $this->assertArrayNotHasKey('afip_key_path', $tamperedData);
    }

    /**
     * 1.2 Stress Test: Unauthenticated and non-admin callers attempting PIN bypass on protected endpoints
     */
    public function test_adversarial_pin_bypass_attempts_on_integrations_and_certificates(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('9876'),
        ]);

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'pin' => Hash::make('1111'),
            'permissions' => ['pos'],
        ]);
        $cashierToken = 'cashier-session-token-' . uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $cashierToken]);

        // ATTEMPT A: Completely unauthenticated callers (no session token)
        // Even if they supply the admin PIN in X-Admin-Pin, they MUST be rejected with 401 SESSION_MISSING
        $resNoSession1 = $this->flushHeaders()->withHeader('X-Admin-Pin', '9876')->getJson('/api/settings/integrations');
        $resNoSession1->assertStatus(401);
        $this->assertEquals('SESSION_MISSING', $resNoSession1->json('error_code'));

        $resNoSession2 = $this->flushHeaders()->withHeader('X-Admin-Pin', '9876')->putJson('/api/settings/integrations', ['mp_qr_enabled' => true]);
        $resNoSession2->assertStatus(401);
        $this->assertEquals('SESSION_MISSING', $resNoSession2->json('error_code'));

        $resNoSession3 = $this->flushHeaders()->withHeader('X-Admin-Pin', '9876')->postJson('/api/settings/afip/certificates', ['cuit' => '20999999999']);
        $resNoSession3->assertStatus(401);
        $this->assertEquals('SESSION_MISSING', $resNoSession3->json('error_code'));

        $resNoSession4 = $this->flushHeaders()->withHeader('X-Admin-Pin', '9876')->postJson('/api/settings/afip/upload-certificates', ['cuit' => '20999999999']);
        $resNoSession4->assertStatus(401);
        $this->assertEquals('SESSION_MISSING', $resNoSession4->json('error_code'));

        // ATTEMPT B: Cashier authenticated, but sends wrong PIN or cashier's own PIN
        // Cashier's own PIN (1111) is NOT an admin PIN -> must be rejected with 403 INVALID_ADMIN_PIN
        $resCashierOwnPin = $this->flushHeaders()
            ->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '1111')
            ->getJson('/api/settings/integrations');
        $resCashierOwnPin->assertStatus(403);
        $this->assertEquals('INVALID_ADMIN_PIN', $resCashierOwnPin->json('error_code'));

        // Cashier sends random incorrect PIN
        $resWrongPin = $this->flushHeaders()
            ->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '0000')
            ->getJson('/api/settings/integrations');
        $resWrongPin->assertStatus(403);
        $this->assertEquals('INVALID_ADMIN_PIN', $resWrongPin->json('error_code'));

        // Cashier sends NO pin header -> 403 PIN_REQUIRED
        $resNoPin = $this->flushHeaders()
            ->withHeader('X-Session-Token', $cashierToken)
            ->getJson('/api/settings/integrations');
        $resNoPin->assertStatus(403);
        $this->assertEquals('PIN_REQUIRED', $resNoPin->json('error_code'));

        // Cashier attempting POST afip certificates without admin PIN
        $resCertNoPin = $this->flushHeaders()
            ->withHeader('X-Session-Token', $cashierToken)
            ->postJson('/api/settings/afip/certificates', ['cuit' => '20999999999']);
        $resCertNoPin->assertStatus(403);
        $this->assertEquals('PIN_REQUIRED', $resCertNoPin->json('error_code'));

        // Cashier providing the legitimate Admin PIN -> must be accepted 200 OK
        $resValidPin = $this->flushHeaders()
            ->withHeader('X-Session-Token', $cashierToken)
            ->withHeader('X-Admin-Pin', '9876')
            ->getJson('/api/settings/integrations');
        $resValidPin->assertStatus(200);
    }

    /**
     * 1.3 Stress Test: Verify raw plaintext token never appears anywhere in database after saving
     */
    public function test_adversarial_raw_plaintext_tokens_never_appear_in_database(): void
    {
        $rawMpAccessToken = 'TEST_CANARY_RAW_MP_ACCESS_TOKEN_ABC123XYZ';
        $rawMpWebhookSecret = 'TEST_CANARY_RAW_MP_WEBHOOK_SECRET_DEF456UVW';

        // 1. Save via PUT /api/settings/integrations
        $res = $this->actingAsAdmin()->putJson('/api/settings/integrations', [
            'mp_access_token' => $rawMpAccessToken,
            'mp_webhook_secret' => $rawMpWebhookSecret,
        ]);
        $res->assertStatus(200);

        // Direct DB Query: Look for the canary string in ANY column of business_settings table
        $leakedRows = DB::table('business_settings')
            ->where('value', 'LIKE', '%' . $rawMpAccessToken . '%')
            ->orWhere('value', 'LIKE', '%' . $rawMpWebhookSecret . '%')
            ->get();

        $this->assertCount(0, $leakedRows, 'Vulnerability: Raw plaintext token was found in business_settings table!');

        // Check the actual stored values are encrypted
        $tokenRow = DB::table('business_settings')->where('key', 'mp_access_token')->first();
        $this->assertNotNull($tokenRow);
        $this->assertNotEquals($rawMpAccessToken, $tokenRow->value);
        $this->assertEquals($rawMpAccessToken, Crypt::decryptString($tokenRow->value));

        // 2. Also test saving via legacy PUT /api/settings
        $rawMpAccessTokenLegacy = 'TEST_CANARY_LEGACY_RAW_TOKEN_777';
        $resLegacy = $this->actingAsAdmin()->putJson('/api/settings', [
            'mp_access_token' => $rawMpAccessTokenLegacy,
        ]);
        $resLegacy->assertStatus(200);

        $leakedRowsLegacy = DB::table('business_settings')
            ->where('value', 'LIKE', '%' . $rawMpAccessTokenLegacy . '%')
            ->get();
        $this->assertCount(0, $leakedRowsLegacy, 'Vulnerability: Raw token stored in plaintext via PUT /api/settings!');

        $legacyRow = DB::table('business_settings')->where('key', 'mp_access_token')->first();
        $this->assertEquals($rawMpAccessTokenLegacy, Crypt::decryptString($legacyRow->value));
    }

    /**
     * 1.4 Stress Test: Upload AFIP certificates strictly rejects mismatched keys and corrupt inputs with 422
     */
    public function test_adversarial_afip_certificates_strictly_rejects_mismatched_and_corrupt_keys(): void
    {
        $pairA = $this->generateTestCertPair('cuit_pair_A');
        $pairB = $this->generateTestCertPair('cuit_pair_B');

        // Mismatched Cert A with Key B -> 422 CERT_KEY_MISMATCH
        $resMismatch = $this->actingAsAdmin()->postJson('/api/settings/afip/certificates', [
            'cuit' => '20999999999',
            'cert_content' => $pairA['cert'],
            'key_content' => $pairB['key'],
        ]);
        $resMismatch->assertStatus(422);
        $this->assertEquals('CERT_KEY_MISMATCH', $resMismatch->json('error_code'));

        // Corrupted cert content -> 422 INVALID_CERTIFICATE
        $resCorruptCert = $this->actingAsAdmin()->postJson('/api/settings/afip/certificates', [
            'cuit' => '20999999999',
            'cert_content' => '--- MALFORMED GARBAGE CERTIFICATE CONTENT ---',
            'key_content' => $pairA['key'],
        ]);
        $resCorruptCert->assertStatus(422);
        $this->assertEquals('INVALID_CERTIFICATE', $resCorruptCert->json('error_code'));

        // Corrupted key content -> 422 INVALID_PRIVATE_KEY
        $resCorruptKey = $this->actingAsAdmin()->postJson('/api/settings/afip/certificates', [
            'cuit' => '20999999999',
            'cert_content' => $pairA['cert'],
            'key_content' => '--- MALFORMED GARBAGE PRIVATE KEY CONTENT ---',
        ]);
        $resCorruptKey->assertStatus(422);
        $this->assertEquals('INVALID_PRIVATE_KEY', $resCorruptKey->json('error_code'));

        // Non-numeric CUIT -> 422 INVALID_CUIT
        $resInvalidCuit = $this->actingAsAdmin()->postJson('/api/settings/afip/certificates', [
            'cuit' => 'ABC-DEF-XYZ',
            'cert_content' => $pairA['cert'],
            'key_content' => $pairA['key'],
        ]);
        $resInvalidCuit->assertStatus(422);
        $this->assertEquals('INVALID_CUIT', $resInvalidCuit->json('error_code'));

        // Missing cert or key -> 422 MISSING_CERT_OR_KEY
        $resMissing = $this->actingAsAdmin()->postJson('/api/settings/afip/certificates', [
            'cuit' => '20999999999',
            'cert_content' => $pairA['cert'],
            'key_content' => '',
        ]);
        $resMissing->assertStatus(422);
    }
}

