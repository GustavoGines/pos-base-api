<?php

namespace Tests\Feature;

use App\Events\PaymentApprovedEvent;
use App\Models\BusinessSetting;
use App\Models\MpTransaction;
use App\Models\MpWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected string $webhookSecret = 'feature_webhook_secret_xyz123';

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::setSecret('mp_access_token', 'TEST-app-access-token-feature');
        BusinessSetting::setSecret('mp_webhook_secret', $this->webhookSecret);
        BusinessSetting::updateOrCreate(
            ['key' => 'mp_point_device_id'],
            ['value' => 'POINT_DEV_DEFAULT_001']
        );
    }

    public function test_create_order_endpoint_returns_200_and_qr_data(): void
    {
        Http::fake([
            'https://api.mercadopago.com/instore/orders/qr/seller/collectors/*' => Http::response([
                'id' => 'ord_feat_111',
                'qr_data' => '00020126360014COM.MERCADOPAGO52040000',
                'status' => 'opened',
            ], 201),
        ]);

        $response = $this->actingAsAdmin()->postJson('/api/pos/mp/create-order', [
            'pos_id' => 'CAJAPRINCIPAL',
            'amount' => 1500.50,
            'items' => [
                ['title' => 'Producto Prueba', 'unit_price' => 1500.50, 'quantity' => 1],
            ],
            'external_reference' => 'POS-FEAT-ORDER-1',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'external_reference' => 'POS-FEAT-ORDER-1',
                'order_id' => 'ord_feat_111',
                'qr_data' => '00020126360014COM.MERCADOPAGO52040000',
            ]);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-FEAT-ORDER-1',
            'pos_id' => 'CAJAPRINCIPAL',
            'amount' => 1500.50,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_feat_111',
        ]);
    }

    public function test_status_endpoint_returns_current_transaction_status(): void
    {
        MpTransaction::create([
            'external_reference' => 'POS-STATUS-APPROVED',
            'pos_id' => 'caja-1',
            'amount' => 3000.00,
            'status' => MpTransaction::STATUS_APPROVED,
            'mp_payment_id' => 'PAY-999888',
            'mp_order_id' => 'ORD-999888',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/pos/mp/status/POS-STATUS-APPROVED');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'external_reference' => 'POS-STATUS-APPROVED',
                'status' => 'approved',
                'mp_payment_id' => 'PAY-999888',
                'mp_order_id' => 'ORD-999888',
                'amount' => 3000.00,
            ]);
    }

    public function test_status_endpoint_performs_polling_and_updates_if_paid(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        MpTransaction::create([
            'external_reference' => 'POS-POLL-PENDING',
            'pos_id' => 'caja-1',
            'amount' => 4500.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_poll_123',
        ]);

        Http::fake([
            'https://api.mercadopago.com/merchant_orders/search*' => Http::response([
        'elements' => [
            [
                'id' => 'ord_poll_123',
                'status' => 'closed',
                'payments' => [
                    [
                        'id' => 55443322,
                        'status' => 'approved',
                        'amount' => 4500.00,
                    ],
                ],
            ]
        ]
    ], 200),
    'ignore' => Http::response([
                'id' => 'ord_poll_123',
                'status' => 'closed',
                'payments' => [
                    [
                        'id' => 55443322,
                        'status' => 'approved',
                        'amount' => 4500.00,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/pos/mp/status/POS-POLL-PENDING');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'external_reference' => 'POS-POLL-PENDING',
                'status' => 'approved',
                'mp_payment_id' => '55443322',
            ]);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-POLL-PENDING',
            'status' => MpTransaction::STATUS_APPROVED,
            'mp_payment_id' => '55443322',
        ]);

        Event::assertDispatched(PaymentApprovedEvent::class);
    }

    public function test_create_point_intent_succeeds_with_configured_device(): void
    {
        Http::fake([
            'https://api.mercadopago.com/point/integration-api/devices/POINT_DEV_DEFAULT_001/payment-intents' => Http::response([
                'id' => 'intent_feat_point_123',
                'status' => 'open',
            ], 201),
        ]);

        $response = $this->actingAsAdmin()->postJson('/api/pos/mp/create-point-intent', [
            'amount' => 2000.00,
            'description' => 'Cobro Posnet Prueba',
            'external_reference' => 'POS-POINT-FEAT-1',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'external_reference' => 'POS-POINT-FEAT-1',
                'payment_intent_id' => 'intent_feat_point_123',
                'status' => 'open',
            ]);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-POINT-FEAT-1',
            'pos_id' => 'POINT_DEV_DEFAULT_001',
            'amount' => 2000.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'intent_feat_point_123',
        ]);
    }

    public function test_create_point_intent_returns_422_when_device_not_configured(): void
    {
        BusinessSetting::where('key', 'mp_point_device_id')->delete();

        $response = $this->actingAsAdmin()->postJson('/api/pos/mp/create-point-intent', [
            'amount' => 2000.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_cancel_order_endpoint_returns_200(): void
    {
        MpTransaction::create([
            'external_reference' => 'POS-FEAT-CANCEL',
            'pos_id' => 'caja-1',
            'amount' => 1000.00,
            'status' => MpTransaction::STATUS_OPENED,
            'mp_order_id' => 'ord_feat_cancel_1',
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/*/cancel' => Http::response([], 200),
        ]);

        $response = $this->actingAsAdmin()->postJson('/api/pos/mp/cancel-order', [
            'external_reference' => 'POS-FEAT-CANCEL',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Orden cancelada exitosamente',
            ]);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-FEAT-CANCEL',
            'status' => MpTransaction::STATUS_CANCELLED,
        ]);
    }

    public function test_webhook_rejects_invalid_signature_with_403(): void
    {
        $response = $this->postJson('/api/webhooks/mercadopago', [
            'action' => 'payment.created',
            'data' => ['id' => '123456'],
        ], [
            'x-signature' => 'ts=1700000000,v1=tampered_signature_hash',
            'x-request-id' => 'req-invalid-1',
        ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Invalid webhook signature']);

        $this->assertDatabaseHas('mp_webhooks', [
            'resource_id' => '123456',
            'is_processed' => false,
            'processing_error' => 'Invalid signature',
        ]);
    }

    public function test_webhook_accepts_valid_signature_updates_transaction_and_broadcasts_event(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $tx = MpTransaction::create([
            'external_reference' => 'POS-WH-APPROVED',
            'pos_id' => 'caja-terminal-1',
            'amount' => 5000.00,
            'status' => MpTransaction::STATUS_OPENED,
        ]);

        $dataId = '888777666';
        $requestId = 'req-wh-approved-1';
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $this->webhookSecret);

        $payload = [
            'action' => 'payment.updated',
            'status' => 'approved',
            'external_reference' => 'POS-WH-APPROVED',
            'transaction_amount' => 5000.00,
            'data' => [
                'id' => $dataId,
                'status' => 'approved',
                'external_reference' => 'POS-WH-APPROVED',
            ],
        ];

        $response = $this->postJson('/api/webhooks/mercadopago', $payload, [
            'x-signature' => "ts={$ts},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $tx->refresh();
        $this->assertEquals(MpTransaction::STATUS_APPROVED, $tx->status);
        $this->assertEquals($dataId, $tx->mp_payment_id);

        $this->assertDatabaseHas('mp_webhooks', [
            'resource_id' => $dataId,
            'is_processed' => true,
        ]);

        Event::assertDispatched(PaymentApprovedEvent::class, function ($event) use ($dataId) {
            return $event->externalReference === 'POS-WH-APPROVED'
                && $event->mpPaymentId === $dataId
                && $event->amount === 5000.00;
        });
    }

    public function test_webhook_fetches_payment_details_when_not_in_payload(): void
    {
        Event::fake([PaymentApprovedEvent::class]);

        $tx = MpTransaction::create([
            'external_reference' => 'POS-WH-FETCH-REF',
            'pos_id' => 'caja-terminal-2',
            'amount' => 3200.00,
            'status' => MpTransaction::STATUS_OPENED,
        ]);

        $dataId = '11223344';
        $requestId = 'req-wh-fetch-2';
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $this->webhookSecret);

        Http::fake([
            "https://api.mercadopago.com/v1/payments/{$dataId}" => Http::response([
                'id' => 11223344,
                'status' => 'approved',
                'external_reference' => 'POS-WH-FETCH-REF',
                'transaction_amount' => 3200.00,
            ], 200),
        ]);

        $payload = [
            'action' => 'payment.updated',
            'type' => 'payment',
            'data' => [
                'id' => $dataId,
            ],
        ];

        $response = $this->postJson('/api/webhooks/mercadopago', $payload, [
            'x-signature' => "ts={$ts},v1={$hash}",
            'x-request-id' => $requestId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $tx->refresh();
        $this->assertEquals(MpTransaction::STATUS_APPROVED, $tx->status);
        $this->assertEquals($dataId, $tx->mp_payment_id);

        Event::assertDispatched(PaymentApprovedEvent::class);
    }
}
