<?php

namespace Tests\Unit;

use App\DTOs\SaleContextDTO;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentService $paymentService;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = app(PaymentService::class);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Test: validatePaymentsTotal aprueba cuando los pagos coinciden exactamente con el total esperado.
     */
    public function test_validate_payments_total_passes_on_exact_match(): void
    {
        $payments = [
            [
                'base_amount' => 150.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 150.00,
            ],
            [
                'base_amount' => 50.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 50.00,
            ],
        ];

        // No debe arrojar excepción
        $this->paymentService->validatePaymentsTotal($payments, 200.00);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test: validatePaymentsTotal aprueba con recargos de tarjeta y redondeo a 2 decimales.
     */
    public function test_validate_payments_total_passes_with_surcharges_and_rounding(): void
    {
        $payments = [
            [
                'base_amount' => 100.00,
                'surcharge_amount' => 15.00,
                'total_amount' => 115.00,
            ],
            [
                'base_amount' => 86.95,
                'surcharge_amount' => 13.05,
                'total_amount' => 100.00,
            ],
        ];

        $this->paymentService->validatePaymentsTotal($payments, 215.00);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test: validatePaymentsTotal arroja excepción si base + recargo no coincide con total_amount.
     */
    public function test_validate_payments_total_throws_when_base_plus_surcharge_mismatches_total(): void
    {
        $payments = [
            [
                'base_amount' => 100.00,
                'surcharge_amount' => 10.00,
                'total_amount' => 120.00, // Inconsistente: 100 + 10 != 120
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Inconsistencia en el pago: base + recargo no coinciden con el total.');

        $this->paymentService->validatePaymentsTotal($payments, 120.00);
    }

    /**
     * Test: validatePaymentsTotal arroja excepción si la suma de pagos es inferior al total esperado.
     */
    public function test_validate_payments_total_throws_when_sum_is_less_than_expected(): void
    {
        $payments = [
            [
                'base_amount' => 99.90,
                'surcharge_amount' => 0.00,
                'total_amount' => 99.90,
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no cubre el total esperado de la venta');

        $this->paymentService->validatePaymentsTotal($payments, 100.00);
    }

    /**
     * Test: registerPayments persiste los registros de pago y crea el ThirdPartyCheck en cartera.
     */
    public function test_register_payments_creates_payment_records_and_third_party_check(): void
    {
        $cashMethod = $this->crearMetodoEfectivo();
        $checkMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cheque'],
            ['name' => 'Cheque de Tercero', 'is_cash' => false, 'surcharge_type' => 'none', 'surcharge_value' => 0]
        );

        $shift = $this->crearTurnoAbierto(user: $this->user);

        $customer = Customer::create([
            'name' => 'Cliente Cheques P3',
            'document_number' => '20202020',
        ]);

        $sale = Sale::create([
            'total' => 350.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: $customer->id,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $payments = [
            [
                'payment_method_id' => $cashMethod->id,
                'base_amount' => 100.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 100.00,
            ],
            [
                'payment_method_id' => $checkMethod->id,
                'base_amount' => 250.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 250.00,
            ],
        ];

        $checkDetails = [
            'bank_name' => 'Banco Santander',
            'check_number' => 'CHK-889900',
            'issue_date' => '2026-09-01',
            'payment_date' => '2026-10-15',
            'issuer_name' => 'Construcciones Norte S.A.',
            'issuer_cuit' => '30-71122334-9',
        ];

        $this->paymentService->registerPayments($sale, $payments, $checkDetails, $context);

        // Verificar pagos asociados a la venta
        $this->assertCount(2, $sale->payments);

        // Verificar registro de cheque en cartera
        $this->assertDatabaseHas('third_party_checks', [
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'bank_name' => 'Banco Santander',
            'check_number' => 'CHK-889900',
            'amount' => 250.00,
            'status' => 'in_wallet',
            'issuer_name' => 'Construcciones Norte S.A.',
            'issuer_cuit' => '30-71122334-9',
        ]);
    }

    /**
     * Test: registerPayments mapea adecuadamente múltiples detalles de cheques en pagos con varios cheques.
     */
    public function test_register_payments_handles_multiple_checks(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $checkMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cheque'],
            ['name' => 'Cheque de Tercero', 'is_cash' => false, 'surcharge_type' => 'none', 'surcharge_value' => 0]
        );

        $customer = Customer::create([
            'name' => 'Cliente Multicheques',
            'document_number' => '40404040',
        ]);

        $sale = Sale::create([
            'total' => 600.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: $customer->id,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $payments = [
            [
                'payment_method_id' => $checkMethod->id,
                'base_amount' => 200.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 200.00,
            ],
            [
                'payment_method_id' => $checkMethod->id,
                'base_amount' => 400.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 400.00,
            ],
        ];

        $checkDetails = [
            [
                'bank_name' => 'Banco Galicia',
                'check_number' => 'CHK-001',
                'issue_date' => '2026-09-10',
                'payment_date' => '2026-10-10',
                'issuer_name' => 'Proveedor A',
                'issuer_cuit' => '20-11111111-2',
            ],
            [
                'bank_name' => 'Banco Nación',
                'check_number' => 'CHK-002',
                'issue_date' => '2026-09-12',
                'payment_date' => '2026-10-20',
                'issuer_name' => 'Proveedor B',
                'issuer_cuit' => '20-22222222-3',
            ],
        ];

        $this->paymentService->registerPayments($sale, $payments, $checkDetails, $context);

        $this->assertCount(2, $sale->payments);
        $this->assertDatabaseHas('third_party_checks', [
            'sale_id' => $sale->id,
            'check_number' => 'CHK-001',
            'amount' => 200.00,
            'status' => 'in_wallet',
        ]);
        $this->assertDatabaseHas('third_party_checks', [
            'sale_id' => $sale->id,
            'check_number' => 'CHK-002',
            'amount' => 400.00,
            'status' => 'in_wallet',
        ]);
    }

    /**
     * Test: registerCustomerCharge incrementa la deuda (balance) del cliente y registra la transacción de tipo charge.
     */
    public function test_register_customer_charge_increases_balance_and_creates_transaction(): void
    {
        $customer = Customer::create([
            'name' => 'Cliente Cuenta Corriente',
            'document_number' => '50505050',
            'balance' => 100.00,
        ]);

        $sale = Sale::create([
            'total' => 150.00,
            'status' => 'completed',
            'payment_status' => 'pending',
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: 1,
            customerId: $customer->id,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $this->paymentService->registerCustomerCharge($sale, 150.00, $context);

        // El balance sube en $150 (deuda: 100 + 150 = 250)
        $this->assertEquals(250.00, (float) $customer->fresh()->balance);

        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'type' => 'charge',
            'amount' => 150.00,
            'balance_after' => 250.00,
        ]);
    }

    /**
     * Test: revertCustomerTransactionsForVoid descuenta la deuda y registra la transacción compensatoria (payment).
     */
    public function test_revert_customer_transactions_for_void_decreases_balance_and_creates_payment(): void
    {
        $customer = Customer::create([
            'name' => 'Cliente Reversión CC',
            'document_number' => '60606060',
            'balance' => 250.00,
        ]);

        $sale = Sale::create([
            'total' => 150.00,
            'status' => 'completed',
            'payment_status' => 'pending',
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        // Registrar el cargo original asociado a la venta
        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'sale_id' => $sale->id,
            'type' => 'charge',
            'amount' => 150.00,
            'balance_after' => 250.00,
            'description' => "Venta en Cta. Cte. - Ticket #{$sale->id}",
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: 1,
            customerId: $customer->id,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $this->paymentService->revertCustomerTransactionsForVoid($sale, $context);

        // El balance se restituye al nivel original (250 - 150 = 100)
        $this->assertEquals(100.00, (float) $customer->fresh()->balance);

        // Se crea transacción compensatoria de tipo payment
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'type' => 'payment',
            'amount' => 150.00,
            'balance_after' => 100.00,
        ]);
    }
}
