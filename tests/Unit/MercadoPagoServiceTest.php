<?php

namespace Tests\Unit;

use App\Events\PaymentApprovedEvent;
use App\Models\BusinessSetting;
use App\Models\MpTransaction;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MercadoPagoService $service;

    protected string $testAccessToken = 'TEST-access-token-12345';
    protected string $testWebhookSecret = 'test_webhook_secret_67890';
    protected string $testCollectorId = '999888777';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MercadoPagoService(
            accessToken: $this->testAccessToken,
            webhookSecret: $this->testWebhookSecret,
            collectorId: $this->testCollectorId
        );
    }

    public function test_builds_v1_order_payload_with_exact_items(): void
    {
        $items = [
            ['title' => 'Producto A', 'unit_price' => 1500.50, 'quantity' => 2, 'sku' => 'SKU-A'],
            ['title' => 'Producto B', 'unit_price' => 500.00, 'quantity' => 1, 'sku' => 'SKU-B'],
        ];
        $total = 3501.00;

        $payload = $this->service->buildV1OrderPayload('CAJA-01', 'SALE-10023', $total, $items, 'https://test/cb');

        $this->assertEquals('qr', $payload['type']);
        $this->assertEquals(3501.00, $payload['total_amount']);
        $this->assertEquals('SALE-10023', $payload['external_reference']);
        $this->assertEquals('CAJA-01', $payload['config']['qr']['external_pos_id']);
        $this->assertEquals('hybrid', $payload['config']['qr']['mode']);
        $this->assertCount(2, $payload['items']);
        $this->assertEquals('SKU-A', $payload['items'][0]['sku_number']);
        $this->assertEquals(3001.00, $payload['items'][0]['total_amount']);
        $this->assertEquals('SKU-B', $payload['items'][1]['sku_number']);
        $this->assertEquals(500.00, $payload['items'][1]['total_amount']);
    }

    public function test_reconciles_rounding_drift_with_adjustment_item(): void
    {
        $fractionalItems = [
            ['title' => 'Panadería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
            ['title' => 'Verdulería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
            ['title' => 'Fiambrería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
        ];
        $desiredTotal = 100.00; // sum of fractional is 99.99 => diff = +0.01

        $payload = $this->service->buildV1OrderPayload('CAJA-01', 'SALE-REC', $desiredTotal, $fractionalItems);

        $itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
        $this->assertEquals($desiredTotal, $itemSum);
        $this->assertEquals($desiredTotal, $payload['total_amount']);
        $this->assertCount(4, $payload['items']);

        $lastItem = end($payload['items']);
        $this->assertEquals('DISC-ADJ', $lastItem['sku_number']);
        $this->assertEquals(0.01, $lastItem['total_amount']);
    }

    public function test_reconciles_discount_drift_with_negative_adjustment(): void
    {
        $items = [
            ['title' => 'Prenda', 'unit_price' => 1000.00, 'quantity' => 1],
        ];
        $discountedTotal = 850.00; // diff = -150.00

        $payload = $this->service->buildV1OrderPayload('CAJA-01', 'SALE-DISC', $discountedTotal, $items);

        $itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
        $this->assertEquals($discountedTotal, $itemSum);
        $this->assertCount(2, $payload['items']);

        $adjItem = $payload['items'][1];
        $this->assertEquals('DISC-ADJ', $adjItem['sku_number']);
        $this->assertEquals(-150.00, $adjItem['total_amount']);
    }

    public function test_verify_webhook_signature_success_with_valid_signature(): void
    {
        $dataId = '1234567890';
        $requestId = 'req-abc-987';
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $validHash = hash_hmac('sha256', $manifest, $this->testWebhookSecret);

        $xSignature = "ts={$ts},v1={$validHash}";
        $isValid = $this->service->verifyWebhookSignature($xSignature, $requestId, $dataId);

        $this->assertTrue($isValid);
    }

    public function test_verify_webhook_signature_rejects_tampered_signature(): void
    {
        $dataId = '1234567890';
        $requestId = 'req-abc-987';
        $ts = (string) time();

        $invalidSignature = "ts={$ts},v1=invalid_hash_signature_123";
        $isValid = $this->service->verifyWebhookSignature($invalidSignature, $requestId, $dataId);

        $this->assertFalse($isValid);
    }

    public function test_verify_webhook_signature_tolerates_whitespace_around_delimiters(): void
    {
        $dataId = '1234567890';
        $requestId = 'req-abc-987';
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $validHash = hash_hmac('sha256', $manifest, $this->testWebhookSecret);

        $spacedSignature = "ts = {$ts} , v1 = {$validHash}";
        $isValid = $this->service->verifyWebhookSignature($spacedSignature, $requestId, $dataId);

        $this->assertTrue($isValid);
    }

    public function test_verify_webhook_signature_rejects_stale_timestamp_replay_attack(): void
    {
        $dataId = '1234567890';
        $requestId = 'req-abc-987';
        $staleTs = (string) (time() - 301); // 301 seconds old (> 300s maxTolerance)
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$staleTs};";
        $validHashForStale = hash_hmac('sha256', $manifest, $this->testWebhookSecret);

        $staleSignature = "ts={$staleTs},v1={$validHashForStale}";
        $isValid = $this->service->verifyWebhookSignature($staleSignature, $requestId, $dataId);

        $this->assertFalse($isValid);
    }

    public function test_verify_webhook_signature_rejects_missing_parts(): void
    {
        $this->assertFalse($this->service->verifyWebhookSignature('ts=123', 'req-1', 'data-1'));
        $this->assertFalse($this->service->verifyWebhookSignature('v1=abc', 'req-1', 'data-1'));
        $this->assertFalse($this->service->verifyWebhookSignature('', 'req-1', 'data-1'));
    }

    public function test_create_in_store_order_calls_api_and_creates_transaction(): void
    {
        Http::fake([
            'https://api.mercadopago.com/instore/orders/qr/seller/collectors/*' => Http::response([
                'id' => 'ord_test_123',
                'qr_data' => '00020101021243650016COM.MERCADOPAGO',
                'status' => 'opened',
            ], 201),
        ]);

        $result = $this->service->createInStoreOrder(
            posId: 'CAJA-1',
            externalRef: 'POS-ORDER-100',
            amount: 1500.50,
            items: [['title' => 'Producto 1', 'unit_price' => 1500.50, 'quantity' => 1]]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('POS-ORDER-100', $result['external_reference']);
        $this->assertEquals('ord_test_123', $result['order_id']);
        $this->assertNotNull($result['qr_data']);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-ORDER-100',
            'pos_id' => 'CAJA-1',
            'amount' => 1500.50,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_test_123',
        ]);
    }

    public function test_create_point_payment_intent_calls_point_api_and_creates_transaction(): void
    {
        Http::fake([
            'https://api.mercadopago.com/point/integration-api/devices/DEV_POINT_01/payment-intents' => Http::response([
                'id' => 'intent_uuid_999',
                'status' => 'open',
            ], 201),
        ]);

        $result = $this->service->createPointPaymentIntent(
            deviceId: 'DEV_POINT_01',
            externalRef: 'POS-POINT-200',
            amount: 2500.00,
            description: 'Venta Mostrador Point'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('POS-POINT-200', $result['external_reference']);
        $this->assertEquals('intent_uuid_999', $result['payment_intent_id']);
        $this->assertEquals('open', $result['status']);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-POINT-200',
            'pos_id' => 'DEV_POINT_01',
            'amount' => 2500.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'intent_uuid_999',
        ]);
    }

    public function test_check_order_status_updates_transaction_to_approved_and_dispatches_event(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $tx = MpTransaction::create([
            'external_reference' => 'POS-CHECK-300',
            'pos_id' => 'CAJA-1',
            'amount' => 1200.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_check_300',
        ]);

        Http::fake([
            'https://api.mercadopago.com/merchant_orders/search*' => Http::response([
        'elements' => [
            [
                'id' => 'ord_check_300',
                'status' => 'closed',
                'payments' => [
                    [
                        'id' => 99887766,
                        'status' => 'approved',
                        'amount' => 1200.00,
                    ],
                ],
            ]
        ]
    ], 200),
    'ignore' => Http::response([
                'id' => 'ord_check_300',
                'status' => 'closed',
                'payments' => [
                    [
                        'id' => 99887766,
                        'status' => 'approved',
                        'amount' => 1200.00,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->checkOrderStatus('ord_check_300', 'POS-CHECK-300');

        $this->assertEquals('approved', $result['status']);
        $this->assertEquals('99887766', $result['mp_payment_id']);

        $tx->refresh();
        $this->assertEquals(MpTransaction::STATUS_APPROVED, $tx->status);
        $this->assertEquals('99887766', $tx->mp_payment_id);

        Event::assertDispatched(PaymentApprovedEvent::class, function ($event) {
            return $event->externalReference === 'POS-CHECK-300'
                && $event->mpPaymentId === '99887766'
                && $event->amount === 1200.00;
        });
    }

    public function test_cancel_in_store_order_updates_transaction_to_cancelled(): void
    {
        $tx = MpTransaction::create([
            'external_reference' => 'POS-CANCEL-400',
            'pos_id' => 'CAJA-1',
            'amount' => 800.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_cancel_400',
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/*/cancel' => Http::response([], 200),
        ]);

        $res = $this->service->cancelInStoreOrder('POS-CANCEL-400');
        $this->assertTrue($res);

        $tx->refresh();
        $this->assertEquals(MpTransaction::STATUS_CANCELLED, $tx->status);
    }
}
