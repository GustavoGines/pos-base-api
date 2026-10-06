<?php

namespace Tests\Unit;

use App\DTOs\SaleContextDTO;
use App\Http\Requests\ProcessSaleRequest;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\MpTransaction;
use App\Models\MpWebhook;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CashShiftService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MercadoPagoDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected CashRegister $cashRegister;
    protected CashShift $cashShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->cashRegister = CashRegister::create([
            'id' => 1,
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);
        $this->cashShift = CashShift::create([
            'cash_register_id' => $this->cashRegister->id,
            'user_id' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 1000.00,
            'status' => 'open',
        ]);
    }

    /**
     * Test: Los métodos de pago mercadopago_qr y mercadopago_point existen con sus atributos correctos.
     */
    public function test_mercadopago_payment_methods_exist_and_have_correct_attributes(): void
    {
        $qrMethod = PaymentMethod::where('code', 'mercadopago_qr')->first();
        $this->assertNotNull($qrMethod, 'El método de pago mercadopago_qr debe existir.');
        $this->assertEquals('Mercado Pago QR', $qrMethod->name);
        $this->assertFalse((bool) $qrMethod->is_cash);
        $this->assertTrue((bool) $qrMethod->is_active);
        $this->assertEquals(6, $qrMethod->sort_order);

        $pointMethod = PaymentMethod::where('code', 'mercadopago_point')->first();
        $this->assertNotNull($pointMethod, 'El método de pago mercadopago_point debe existir.');
        $this->assertEquals('Mercado Pago Point', $pointMethod->name);
        $this->assertFalse((bool) $pointMethod->is_cash);
        $this->assertTrue((bool) $pointMethod->is_active);
        $this->assertEquals(7, $pointMethod->sort_order);
    }

    /**
     * Test: MpTransaction puede crearse, castea datos y establece relaciones con Sale, CashShift y User.
     */
    public function test_mp_transaction_can_be_created_and_relates_to_sale_cash_shift_and_user(): void
    {
        $sale = Sale::create([
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'total' => 2500.00,
            'total_surcharge' => 0.00,
            'amount_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $mpTx = MpTransaction::create([
            'sale_id' => $sale->id,
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'external_reference' => 'POS-TEST-001',
            'collector_id' => '12345678',
            'pos_id' => 'caja-1',
            'amount' => 2500.00,
            'status' => MpTransaction::STATUS_APPROVED,
            'mp_payment_id' => '9988776655',
            'mp_order_id' => '1122334455',
            'qr_data' => '00020101021243650016COM.MERCADOLIBRE...',
            'payer_email' => 'cliente@test.com',
            'payment_method_type' => 'account_money',
            'payload_received' => ['test_key' => 'test_val'],
        ]);

        $this->assertDatabaseHas('mp_transactions', [
            'external_reference' => 'POS-TEST-001',
            'mp_payment_id' => '9988776655',
            'status' => 'approved',
        ]);

        $this->assertEquals('2500.00', $mpTx->amount);
        $this->assertIsArray($mpTx->payload_received);
        $this->assertEquals('test_val', $mpTx->payload_received['test_key']);

        // Relaciones directas
        $this->assertEquals($sale->id, $mpTx->sale->id);
        $this->assertEquals($this->cashShift->id, $mpTx->cashShift->id);
        $this->assertEquals($this->user->id, $mpTx->user->id);

        // Relaciones inversas
        $this->assertTrue($sale->mpTransactions->contains($mpTx));
        $this->assertTrue($this->cashShift->mpTransactions->contains($mpTx));
        $this->assertTrue($this->user->mpTransactions->contains($mpTx));
    }

    /**
     * Test: MpWebhook almacena payloads, castea tipos y filtra con scope pending.
     */
    public function test_mp_webhook_can_be_created_and_queried_with_pending_scope(): void
    {
        $pendingWebhook = MpWebhook::create([
            'action' => 'payment.created',
            'topic' => 'payment',
            'resource_id' => '123456789',
            'payload' => ['id' => 123456789, 'status' => 'pending'],
            'is_processed' => false,
        ]);

        $processedWebhook = MpWebhook::create([
            'action' => 'payment.updated',
            'topic' => 'payment',
            'resource_id' => '987654321',
            'payload' => ['id' => 987654321, 'status' => 'approved'],
            'is_processed' => true,
            'processed_at' => now(),
        ]);

        $pendingList = MpWebhook::pending()->get();
        $this->assertTrue($pendingList->contains($pendingWebhook));
        $this->assertFalse($pendingList->contains($processedWebhook));

        $this->assertIsArray($pendingWebhook->payload);
        $this->assertFalse($pendingWebhook->is_processed);
        $this->assertTrue($processedWebhook->is_processed);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $processedWebhook->processed_at);
    }

    /**
     * Test: SalePayment persiste columnas de Mercado Pago y relaciona con MpTransaction por reference_id.
     */
    public function test_sale_payment_stores_mp_fields_and_relates_to_mp_transaction(): void
    {
        $sale = Sale::create([
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'total' => 1500.00,
            'total_surcharge' => 0.00,
            'amount_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $qrMethod = PaymentMethod::where('code', 'mercadopago_qr')->first();

        $mpTx = MpTransaction::create([
            'external_reference' => 'REF-MP-TEST-123',
            'amount' => 1500.00,
            'status' => MpTransaction::STATUS_APPROVED,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $qrMethod->id,
            'base_amount' => 1500.00,
            'surcharge_amount' => 0.00,
            'total_amount' => 1500.00,
            'mp_payment_id' => 'MP-PAY-555',
            'mp_order_id' => 'MP-ORD-777',
            'reference_id' => 'REF-MP-TEST-123',
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'id' => $payment->id,
            'mp_payment_id' => 'MP-PAY-555',
            'mp_order_id' => 'MP-ORD-777',
            'reference_id' => 'REF-MP-TEST-123',
        ]);

        $this->assertNotNull($payment->mpTransaction);
        $this->assertEquals($mpTx->id, $payment->mpTransaction->id);
    }

    /**
     * Test: CashShift::getTotalSalesAttribute incluye mp_sales junto al resto de medios.
     */
    public function test_cash_shift_total_sales_includes_mp_sales(): void
    {
        $shift = new CashShift([
            'cash_sales' => 100.00,
            'card_sales' => 50.00,
            'transfer_sales' => 25.00,
            'mp_sales' => 80.50,
            'mp_sales_count' => 2,
            'check_sales' => 0.00,
            'cc_sales' => 0.00,
        ]);

        $expectedTotal = 100.00 + 50.00 + 25.00 + 80.50;
        $this->assertEquals($expectedTotal, $shift->total_sales);
        $this->assertEquals(2, $shift->mp_sales_count);
    }

    /**
     * Test: CashShiftService calcula mp_sales y mp_sales_count y los persiste al cerrar turno.
     */
    public function test_cash_shift_service_calculates_and_closes_shift_with_mercadopago(): void
    {
        $qrMethod = PaymentMethod::where('code', 'mercadopago_qr')->first();
        $pointMethod = PaymentMethod::where('code', 'mercadopago_point')->first();

        // Crear dos ventas completadas con Mercado Pago
        $sale1 = Sale::create([
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'total' => 1200.00,
            'total_surcharge' => 0.00,
            'amount_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        SalePayment::create([
            'sale_id' => $sale1->id,
            'payment_method_id' => $qrMethod->id,
            'base_amount' => 1200.00,
            'surcharge_amount' => 0.00,
            'total_amount' => 1200.00,
            'reference_id' => 'REF-SALE-1',
        ]);

        $sale2 = Sale::create([
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'total' => 800.00,
            'total_surcharge' => 0.00,
            'amount_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        SalePayment::create([
            'sale_id' => $sale2->id,
            'payment_method_id' => $pointMethod->id,
            'base_amount' => 800.00,
            'surcharge_amount' => 0.00,
            'total_amount' => 800.00,
            'reference_id' => 'REF-SALE-2',
        ]);

        /** @var CashShiftService $service */
        $service = app(CashShiftService::class);
        $liveTotals = $service->calculateLiveTotals($this->cashShift);

        $this->assertEquals(2000.00, $liveTotals['mp_sales']);
        $this->assertEquals(2, $liveTotals['mp_sales_count']);
        $this->assertEquals(2000.00, $liveTotals['total_sales']);

        // Cerrar turno y verificar persistencia en base de datos
        $closedShift = $service->closeShift($this->cashShift->id, 1000.00, $this->user->id);

        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(2000.00, (float) $closedShift->mp_sales);
        $this->assertEquals(2, $closedShift->mp_sales_count);
    }

    /**
     * Test: PaymentService::registerPayments persiste campos de MP y actualiza la transacción MP.
     */
    public function test_payment_service_register_payments_persists_mp_fields_and_links_transaction(): void
    {
        $qrMethod = PaymentMethod::where('code', 'mercadopago_qr')->first();

        $mpTx = MpTransaction::create([
            'external_reference' => 'POS-EXT-REF-999',
            'amount' => 3000.00,
            'status' => MpTransaction::STATUS_PENDING,
        ]);

        $sale = Sale::create([
            'cash_shift_id' => $this->cashShift->id,
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'total' => 3000.00,
            'total_surcharge' => 0.00,
            'amount_due' => 0.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $context = SaleContextDTO::fromArray([
            'user_id' => $this->user->id,
            'cash_shift_id' => $this->cashShift->id,
        ]);

        $payments = [
            [
                'payment_method_id' => $qrMethod->id,
                'base_amount' => 3000.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 3000.00,
                'mp_payment_id' => 'MP-PAY-777888',
                'mp_order_id' => 'MP-ORD-999000',
                'reference_id' => 'POS-EXT-REF-999',
            ],
        ];

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $paymentService->registerPayments($sale, $payments, null, $context);

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale->id,
            'payment_method_id' => $qrMethod->id,
            'mp_payment_id' => 'MP-PAY-777888',
            'mp_order_id' => 'MP-ORD-999000',
            'reference_id' => 'POS-EXT-REF-999',
        ]);

        // Verificar que la transacción MP se asoció a la venta y se aprobó
        $mpTx->refresh();
        $this->assertEquals($sale->id, $mpTx->sale_id);
        $this->assertEquals(MpTransaction::STATUS_APPROVED, $mpTx->status);
    }

    /**
     * Test: ProcessSaleRequest valida campos mp_payment_id, mp_order_id, reference_id como nullable.
     */
    public function test_process_sale_request_validation_rules_for_mp_fields(): void
    {
        $qrMethod = PaymentMethod::where('code', 'mercadopago_qr')->first();

        $data = [
            'total' => 1000.00,
            'total_surcharge' => 0.00,
            'cash_shift_id' => $this->cashShift->id,
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                    'subtotal' => 1000.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $qrMethod->id,
                    'base_amount' => 1000.00,
                    'surcharge_amount' => 0.00,
                    'total_amount' => 1000.00,
                    'mp_payment_id' => 'PAY-12345',
                    'mp_order_id' => 'ORD-12345',
                    'reference_id' => 'REF-12345',
                ],
            ],
        ];

        $request = new ProcessSaleRequest();
        $validator = Validator::make($data, $request->rules());

        // Verificar que no haya errores de validación en los campos de MP
        $this->assertFalse($validator->errors()->has('payments.0.mp_payment_id'));
        $this->assertFalse($validator->errors()->has('payments.0.mp_order_id'));
        $this->assertFalse($validator->errors()->has('payments.0.reference_id'));
    }
}
