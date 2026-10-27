<?php

namespace Tests\Unit;

use App\Services\LicenseSyncService;
use PHPUnit\Framework\TestCase;

class Ed25519CryptographicChallengeTest extends TestCase
{
    private string $seed;
    private string $keyPair;
    private string $secretKey;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $this->keyPair = sodium_crypto_sign_seed_keypair($this->seed);
        $this->secretKey = sodium_crypto_sign_secretkey($this->keyPair);
        $this->publicKey = sodium_crypto_sign_publickey($this->keyPair);
    }

    protected function tearDown(): void
    {
        sodium_memzero($this->seed);
        sodium_memzero($this->secretKey);
        sodium_memzero($this->keyPair);
        parent::tearDown();
    }

    public function test_canonical_payload_is_deterministic_regardless_of_feature_key_order(): void
    {
        $featuresA = [
            'z_reports' => true,
            'mobile_app' => false,
            'arca_afip' => true,
            'fast_pos' => true,
        ];

        $featuresB = [
            'fast_pos' => true,
            'arca_afip' => true,
            'mobile_app' => false,
            'z_reports' => true,
        ];

        $payloadA = [
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'expires_at' => '2027-12-31',
            'server_time' => '2026-10-27 12:00:00',
            'nonce' => 'nonce_123456',
            'features' => $featuresA,
        ];

        $payloadB = $payloadA;
        $payloadB['features'] = $featuresB;

        $canonicalA = LicenseSyncService::buildCanonicalPayload($payloadA, 'inst-001');
        $canonicalB = LicenseSyncService::buildCanonicalPayload($payloadB, 'inst-001');

        $this->assertSame($canonicalA, $canonicalB, 'Canonical payload must be byte-identical regardless of insertion order');
    }

    public function test_ed25519_detached_signature_verifies_correctly_with_public_key(): void
    {
        $payload = [
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'expires_at' => '2027-12-31 23:59:59',
            'server_time' => '2026-10-27 12:00:00',
            'nonce' => 'nonce_abc123',
            'features' => ['fast_pos' => true, 'mobile_app' => true],
        ];
        $instId = 'inst-device-777';

        $canonical = LicenseSyncService::buildCanonicalPayload($payload, $instId);
        $signature = sodium_crypto_sign_detached($canonical, $this->secretKey);

        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($signature));

        // Verificación exitosa
        $valid = sodium_crypto_sign_verify_detached($signature, $canonical, $this->publicKey);
        $this->assertTrue($valid, 'Valid Ed25519 signature must verify');

        // Alteración de status
        $tampered1 = $canonical;
        $tampered1 = str_replace('active', 'lifetime', $tampered1);
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $tampered1, $this->publicKey));

        // Alteración de features
        $tamperedPayload = $payload;
        $tamperedPayload['features']['remote_access'] = true;
        $tamperedCanonical = LicenseSyncService::buildCanonicalPayload($tamperedPayload, $instId);
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $tamperedCanonical, $this->publicKey));

        // Alteración de installation_id
        $tamperedCanonicalInst = LicenseSyncService::buildCanonicalPayload($payload, 'different-device');
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $tamperedCanonicalInst, $this->publicKey));

        // Bit flip en la firma
        $corruptedSig = $signature;
        $corruptedSig[0] = chr(ord($corruptedSig[0]) ^ 0xFF);
        $this->assertFalse(sodium_crypto_sign_verify_detached($corruptedSig, $canonical, $this->publicKey));
    }

    public function test_sodium_memzero_clears_sensitive_key_material_in_ram(): void
    {
        $ephemeralKey = random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $this->assertNotSame(str_repeat("\0", SODIUM_CRYPTO_SIGN_SECRETKEYBYTES), $ephemeralKey);

        sodium_memzero($ephemeralKey);
        $this->assertNull($ephemeralKey, 'sodium_memzero sets the variable reference to null after zeroing underlying memory');
    }
}
