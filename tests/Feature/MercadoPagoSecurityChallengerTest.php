<?php

namespace Tests\Feature;

use App\Events\PaymentApprovedEvent;
use App\Models\BusinessSetting;
use App\Models\MpTransaction;
use App\Models\MpWebhook;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Empirical Security & Adversarial Challenger Test Suite
 * Milestone M4 (Mercado Pago QR & Posnet Físico)
 */
class MercadoPagoSecurityChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected string $validSecret = 'whsec_empirical_adversarial_test_secret_2026';
    protected string $attackerSecret = 'whsec_attacker_evil_secret_666';

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::setSecret('mp_access_token', 'APP_USR-123456789-AUTHENTIC-SECRET-TOKEN');
        BusinessSetting::setSecret('mp_webhook_secret', $this->validSecret);
        BusinessSetting::updateOrCreate(
            ['key' => 'mp_point_device_id'],
            ['value' => 'POINT_DEV_SEC_CHALLENGE']
        );
    }

    // =========================================================================
    // 1. WEBHOOK HMAC SIGNATURE TAMPER TESTS (Must return 403 Forbidden)
    // =========================================================================

    public function test_webhook_rejects_altered_secret_signature_with_403(): void
    {
        $dataId = '99001122';
        $requestId = 'req-tamper-sec-1';
        $ts = (string) time();

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $attackerSignature = hash_hmac('sha256', $manifest, $this->attackerSecret);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$attackerSignature}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);

        $this->assertDatabaseHas('mp_webhooks', [
            'resource_id' => $dataId,
            'is_processed' => false,
            'processing_error' => 'Invalid signature',
        ]);
    }

    public function test_webhook_rejects_tampered_data_id_with_403(): void
    {
        $legitDataId = '11223344';
        $tamperedDataId = '99887766';
        $requestId = 'req-tamper-dataid-1';
        $ts = (string) time();

        // Signature signed for legitDataId
        $manifest = "id:{$legitDataId};request-id:{$requestId};ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, $this->validSecret);

        // Attacker swaps payload to tamperedDataId
        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $tamperedDataId],
        ], [
            'x-signature' => "ts={$ts},v1={$signature}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);
    }

    public function test_webhook_rejects_tampered_request_id_with_403(): void
    {
        $dataId = '44556677';
        $legitRequestId = 'req-legit-100';
        $tamperedRequestId = 'req-tampered-999';
        $ts = (string) time();

        // Signed for legitRequestId
        $manifest = "id:{$dataId};request-id:{$legitRequestId};ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, $this->validSecret);

        // Header sends tamperedRequestId
        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$signature}",
            'x-request-id' => $tamperedRequestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);
    }

    public function test_webhook_rejects_tampered_hash_bit_flip_with_403(): void
    {
        $dataId = '55667788';
        $requestId = 'req-bitflip-1';
        $ts = (string) time();

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $validHash = hash_hmac('sha256', $manifest, $this->validSecret);

        // Flip the last character
        $tamperedHash = substr($validHash, 0, -1) . ($validHash[-1] === 'a' ? 'b' : 'a');

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$tamperedHash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);
    }

    public function test_webhook_rejects_missing_or_malformed_signature_headers_with_403(): void
    {
        // 1. Missing x-signature header completely
        $res1 = $this->postJson('/api/webhooks/mercadopago', ['data' => ['id' => '100']]);
        $res1->assertStatus(403);

        // 2. Only ts present
        $res2 = $this->postJson('/api/webhooks/mercadopago', ['data' => ['id' => '100']], [
            'x-signature' => 'ts=1700000000',
        ]);
        $res2->assertStatus(403);

        // 3. Only v1 present
        $res3 = $this->postJson('/api/webhooks/mercadopago', ['data' => ['id' => '100']], [
            'x-signature' => 'v1=deadbeef',
        ]);
        $res3->assertStatus(403);

        // 4. Empty x-signature header
        $res4 = $this->postJson('/api/webhooks/mercadopago', ['data' => ['id' => '100']], [
            'x-signature' => '',
        ]);
        $res4->assertStatus(403);
    }

    // =========================================================================
    // 2. REPLAY ATTACK BOUNDARY TESTS
    // =========================================================================

    public function test_replay_boundary_valid_at_t_minus_299s(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $dataId = 'replay-valid-299';
        $requestId = 'req-boundary-299';
        $ts = (string) (time() - 299); // Exactly 299s ago (< 300s window)

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $this->validSecret);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_replay_boundary_rejected_at_t_minus_301s(): void
    {
        $dataId = 'replay-stale-301';
        $requestId = 'req-boundary-301-stale';
        $staleTs = (string) (time() - 301); // 301s ago (> 300s maxTolerance)

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$staleTs};";
        $hash = hash_hmac('sha256', $manifest, $this->validSecret);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$staleTs},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);
    }

    public function test_replay_boundary_rejected_at_t_plus_301s(): void
    {
        $dataId = 'replay-future-301';
        $requestId = 'req-boundary-future-301';
        $futureTs = (string) (time() + 301); // 301s in future (future replay attack)

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$futureTs};";
        $hash = hash_hmac('sha256', $manifest, $this->validSecret);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$futureTs},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);
    }

    public function test_replay_boundary_accepted_at_t_plus_299s(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $dataId = 'replay-future-299';
        $requestId = 'req-boundary-future-299';
        $futureTs = (string) (time() + 299); // 299s in future (within 300s clock skew window)

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$futureTs};";
        $hash = hash_hmac('sha256', $manifest, $this->validSecret);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$futureTs},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    // =========================================================================
    // 3. WHITESPACE TOLERANCE TESTS
    // =========================================================================

    public function test_whitespace_tolerance_multiple_spaces_and_tabs_parse_cleanly(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $dataId = 'ws-tolerant-123';
        $requestId = 'req-ws-spaces';
        $ts = (string) time();

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $this->validSecret);

        // Adversarial formatting: extra spaces, tabs, leading and trailing whitespace
        $adversarialHeader = "   ts   =   {$ts}   ,   v1   =   {$hash}   ";

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => $adversarialHeader,
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    // =========================================================================
    // 4. DRIFT RECONCILIATION STRESS TESTS
    // =========================================================================

    public function test_drift_reconciliation_fractional_cent_sum_33_33_reconciles_disc_adj(): void
    {
        $service = app(MercadoPagoService::class);

        // 3 items @ 33.33 = 99.99, while required total is 100.00 => diff = +0.01
        $items = [
            ['title' => 'Tercio 1', 'unit_price' => 33.33, 'quantity' => 1],
            ['title' => 'Tercio 2', 'unit_price' => 33.33, 'quantity' => 1],
            ['title' => 'Tercio 3', 'unit_price' => 33.33, 'quantity' => 1],
        ];
        $total = 100.00;

        $payload = $service->buildV1OrderPayload('CAJA-DRIFT', 'REF-DRIFT-1', $total, $items);

        $this->assertEquals(100.00, $payload['total_amount']);
        $this->assertCount(4, $payload['items']);

        $adj = $payload['items'][3];
        $this->assertEquals('DISC-ADJ', $adj['sku_number']);
        $this->assertEquals(0.01, $adj['unit_price']);
        $this->assertEquals(0.01, $adj['total_amount']);

        // Strict MP Invariant: sum of all item total_amounts MUST exactly equal order total_amount
        $sumOfItems = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
        $this->assertSame(100.00, $sumOfItems);
        $this->assertEquals($payload['total_amount'], $sumOfItems);
    }

    public function test_drift_reconciliation_negative_cents_sum_100_02_vs_100_00(): void
    {
        $service = app(MercadoPagoService::class);

        // 6 items @ 16.67 = 100.02, while required total is 100.00 => diff = -0.02
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = ['title' => "Sexto {$i}", 'unit_price' => 16.67, 'quantity' => 1];
        }
        $total = 100.00;

        $payload = $service->buildV1OrderPayload('CAJA-DRIFT', 'REF-DRIFT-2', $total, $items);

        $this->assertEquals(100.00, $payload['total_amount']);
        $this->assertCount(7, $payload['items']);

        $adj = $payload['items'][6];
        $this->assertEquals('DISC-ADJ', $adj['sku_number']);
        $this->assertEquals(-0.02, $adj['unit_price']);
        $this->assertEquals(-0.02, $adj['total_amount']);

        $sumOfItems = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
        $this->assertSame(100.00, $sumOfItems);
        $this->assertEquals($payload['total_amount'], $sumOfItems);
    }

    public function test_drift_reconciliation_exact_sum_adds_no_disc_adj(): void
    {
        $service = app(MercadoPagoService::class);

        $items = [
            ['title' => 'Producto Exacto 1', 'unit_price' => 50.00, 'quantity' => 1],
            ['title' => 'Producto Exacto 2', 'unit_price' => 50.00, 'quantity' => 1],
        ];
        $total = 100.00;

        $payload = $service->buildV1OrderPayload('CAJA-EXACT', 'REF-EXACT', $total, $items);

        $this->assertCount(2, $payload['items']);
        $skuList = array_column($payload['items'], 'sku_number');
        $this->assertNotContains('DISC-ADJ', $skuList);

        $sumOfItems = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
        $this->assertSame(100.00, $sumOfItems);
    }

    // =========================================================================
    // 5. CREDENTIALS AT REST ENCRYPTION TESTS
    // =========================================================================

    public function test_credentials_at_rest_are_encrypted_ciphertext_not_plaintext(): void
    {
        $rawSecretToken = 'APP_USR-EMPIRICAL-SECRET-TOKEN-998877';
        $rawWebhookSecret = 'whsec_EMPIRICAL_SECRET_KEY_554433';

        // Persist using model helper
        BusinessSetting::setSecret('mp_access_token', $rawSecretToken);
        BusinessSetting::setSecret('mp_webhook_secret', $rawWebhookSecret);

        // Fetch raw database values directly without decrypting accessor
        $dbTokenRow = BusinessSetting::where('key', 'mp_access_token')->first();
        $dbSecretRow = BusinessSetting::where('key', 'mp_webhook_secret')->first();

        $this->assertNotNull($dbTokenRow);
        $this->assertNotNull($dbSecretRow);

        $rawTokenInDb = $dbTokenRow->value;
        $rawSecretInDb = $dbSecretRow->value;

        // 1. Must NOT equal plaintext
        $this->assertNotEquals($rawSecretToken, $rawTokenInDb);
        $this->assertNotEquals($rawWebhookSecret, $rawSecretInDb);

        // 2. Must NOT contain plaintext anywhere in ciphertext string
        $this->assertStringNotContainsString('EMPIRICAL-SECRET-TOKEN', $rawTokenInDb);
        $this->assertStringNotContainsString('EMPIRICAL_SECRET_KEY', $rawSecretInDb);

        // 3. Must be decryptable back to exact plaintext using Laravel Crypt
        $decryptedToken = Crypt::decryptString($rawTokenInDb);
        $decryptedSecret = Crypt::decryptString($rawSecretInDb);

        $this->assertSame($rawSecretToken, $decryptedToken);
        $this->assertSame($rawWebhookSecret, $decryptedSecret);

        // 4. Must be decrypted through BusinessSetting::getSecret
        $this->assertSame($rawSecretToken, BusinessSetting::getSecret('mp_access_token'));
        $this->assertSame($rawWebhookSecret, BusinessSetting::getSecret('mp_webhook_secret'));
    }

    public function test_public_settings_endpoint_never_exposes_mp_secrets(): void
    {
        $response = $this->getJson('/api/settings');

        $response->assertStatus(200);
        $json = $response->json();

        // Must not expose sensitive keys
        $this->assertArrayNotHasKey('mp_access_token', $json);
        $this->assertArrayNotHasKey('mp_webhook_secret', $json);
    }

    public function test_update_integrations_encrypts_credentials_at_rest(): void
    {
        $rawToken = 'APP_USR-HTTP-INTEGRATION-TEST-SECRET';
        $rawSecret = 'whsec_HTTP_INTEGRATION_SECRET';

        $response = $this->actingAsAdmin()->putJson('/api/settings/integrations', [
            'mp_access_token' => $rawToken,
            'mp_webhook_secret' => $rawSecret,
            'mp_qr_enabled' => true,
        ]);

        $response->assertStatus(200);

        // Verify direct DB values
        $dbToken = BusinessSetting::where('key', 'mp_access_token')->first()->value;
        $dbSecret = BusinessSetting::where('key', 'mp_webhook_secret')->first()->value;

        $this->assertNotEquals($rawToken, $dbToken);
        $this->assertNotEquals($rawSecret, $dbSecret);
        $this->assertSame($rawToken, Crypt::decryptString($dbToken));
        $this->assertSame($rawSecret, Crypt::decryptString($dbSecret));

        // Verify GET integrations returns masked token
        $getResp = $this->actingAsAdmin()->getJson('/api/settings/integrations');
        $getResp->assertStatus(200);
        $data = $getResp->json();

        $this->assertStringContainsString('****', $data['mp_access_token']);
        $this->assertStringNotContainsString('INTEGRATION-TEST-SECRET', $data['mp_access_token']);
        $this->assertStringContainsString('****', $data['mp_webhook_secret']);
        $this->assertStringNotContainsString('HTTP_INTEGRATION_SECRET', $data['mp_webhook_secret']);
    }
}

