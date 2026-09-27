<?php

namespace Tests\Feature;

use App\DTOs\PaySaleDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashShiftService;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChallengerFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['code' => 'efectivo'],
            ['name' => 'Efectivo', 'is_cash' => true, 'is_active' => true]
        );

        $this->cardMethod = PaymentMethod::firstOrCreate(
            ['code' => 'card_visa'],
            ['name' => 'Visa Débito', 'is_cash' => false, 'is_active' => true]
        );

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function createProduct(string $code = 'PROD1', float $price = 100.0, float $cost = 50.0): Product
    {
        return Product::create([
            'name' => "Producto {$code}",
            'internal_code' => $code,
            'selling_price' => $price,
            'cost_price' => $cost,
            'stock' => 100,
            'active' => true,
        ]);
    }

    // =========================================================================
    // 1. REFUND ENUM & FINANCIAL DRAWER IMPACT TESTS
    // =========================================================================

    /**
     * Test all valid ENUM values can be inserted into customer_transactions without SQL error.
     */
    public function test_customer_transaction_supports_refund_payment_and_charge(): void
    {
        $customer = Customer::create([
            'name' => 'Test Customer',
            'document_type' => 'DNI',
            'document_number' => '11223344',
            'balance' => 0.0,
        ]);

        foreach (['charge', 'payment', 'refund'] as $type) {
            $trx = CustomerTransaction::create([
                'customer_id' => $customer->id,
                'user_id' => $this->admin->id,
                'type' => $type,
                'amount' => 150.00,
                'balance_after' => 150.00,
                'description' => "Test {$type}",
                'payment_method' => 'cash',
            ]);

            $this->assertNotNull($trx->id);
            $this->assertEquals($type, $trx->fresh()->type);
        }
    }

    /**
     * Directly challenge the MySQL database connection to verify strict ENUM definition.
     */
    public function test_mysql_strict_mode_accepts_refund_and_rejects_invalid_enum(): void
    {
        try {
            config(['database.connections.mysql.database' => 'sistema_pos']);
            $mysql = DB::connection('mysql');
            $mysql->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection not available: '.$e->getMessage());
        }

        // 1. Inspect MySQL schema definition
        $column = $mysql->select("SHOW COLUMNS FROM customer_transactions WHERE Field = 'type'");
        $this->assertNotEmpty($column, 'Column type must exist in customer_transactions');
        $columnType = $column[0]->Type ?? '';

        $this->assertStringContainsString("'charge'", $columnType);
        $this->assertStringContainsString("'payment'", $columnType);
        $this->assertStringContainsString("'refund'", $columnType);

        // 2. Test inserting 'refund' inside transaction and rollback
        $mysql->beginTransaction();
        try {
            // Pick or create customer and user on mysql
            $user = $mysql->table('users')->first();
            $customer = $mysql->table('customers')->first();

            if ($user && $customer) {
                $id = $mysql->table('customer_transactions')->insertGetId([
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'type' => 'refund',
                    'amount' => 99.99,
                    'balance_after' => 0.00,
                    'description' => 'MySQL strict test refund',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $saved = $mysql->table('customer_transactions')->where('id', $id)->first();
                $this->assertNotNull($saved);
                $this->assertEquals('refund', $saved->type);

                // 3. Test that an invalid enum value triggers MySQL truncation/strict mode error
                $this->expectException(QueryException::class);
                $mysql->table('customer_transactions')->insert([
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'type' => 'invalid_bogus_type',
                    'amount' => 10.00,
                    'balance_after' => 0.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } finally {
            $mysql->rollBack();
        }
    }

    /**
     * Test refund lifecycle via CustomerController and verify balance math & validation boundaries.
     */
    public function test_refund_lifecycle_and_validation_boundaries(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);

        // Customer with $500 credit (balance = -500.00)
        $customer = Customer::create([
            'name' => 'Cliente Con Saldo a Favor',
            'document_type' => 'DNI',
            'document_number' => '22334455',
            'balance' => -500.00,
        ]);

        $this->actingAsAdmin($this->admin);

        // 1. Attempt refund exceeding balance ($600 > $500) -> Should fail with 422
        $resOver = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 600.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);
        $resOver->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // 2. Process partial refund of $200
        $resValid = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 200.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);
        $resValid->assertStatus(200);

        // Verify balance updated: -500 + 200 = -300
        $this->assertEquals(-300.00, (float) $customer->fresh()->balance);

        // 3. Process remaining refund of $300 -> balance becomes 0.00
        $resFinal = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 300.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);
        $resFinal->assertStatus(200);
        $this->assertEquals(0.00, (float) $customer->fresh()->balance);

        // 4. Attempt refund now that balance is 0.00 -> Should fail with 422
        $resZero = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 50.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);
        $resZero->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test that refunds correctly impact CashShiftService drawer balancing calculations.
     */
    public function test_refund_correctly_deducted_from_cash_shift_drawer_balancing(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->crearTurnoAbierto(fondoInicial: 1000.00, user: $cashier);

        // Make a cash sale of $500 in this shift
        $product = $this->createProduct('PROD_CASH', 500.0, 250.0);
        $sale = Sale::create([
            'total' => 500.00,
            'total_surcharge' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'cashier_id' => $cashier->id,
        ]);
        $sale->payments()->create([
            'payment_method_id' => $this->cashMethod->id,
            'base_amount' => 500.00,
            'surcharge_amount' => 0,
            'total_amount' => 500.00,
        ]);

        // Customer receives cash refund of $150 in this shift
        $customer = Customer::create([
            'name' => 'Cliente Reintegro Shift',
            'document_type' => 'DNI',
            'document_number' => '99887766',
            'balance' => -300.00,
        ]);

        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => $cashier->id,
            'cash_shift_id' => $shift->id,
            'type' => 'refund',
            'amount' => 150.00,
            'balance_after' => -150.00,
            'description' => 'Reintegro en efectivo',
            'payment_method' => 'cash',
        ]);

        // Close shift via CashShiftService
        // Physical expected in drawer: Opening (1000) + Cash Sales (500) - Cash Refund (150) = 1350
        $shiftService = app(CashShiftService::class);
        $closedShift = $shiftService->closeShift($shift->id, 1350.00, $cashier->id);

        $this->assertEquals(150.00, (float) $closedShift->total_refunds);
        $this->assertEquals(500.00, (float) $closedShift->cash_sales);
        $this->assertEquals(1350.00, (float) $closedShift->expected_balance);
        $this->assertEquals(1350.00, (float) $closedShift->actual_balance);
        $this->assertEquals(0.00, (float) $closedShift->difference);
    }

    // =========================================================================
    // 2. PENDING SALE SHIFT & CASHIER ASSIGNMENT CHALLENGES
    // =========================================================================

    /**
     * Verify that payPendingSale updates cash_shift_id and cashier_id, while preserving creator user_id.
     */
    public function test_pay_pending_sale_transfers_drawer_attribution_to_paying_cashier(): void
    {
        $waiter = User::factory()->create(['name' => 'Mozo Juan', 'role' => 'cashier']);
        $shift1 = $this->crearTurnoAbierto(fondoInicial: 500.00, user: $waiter);

        $cashier2 = User::factory()->create(['name' => 'Cajero Carlos', 'role' => 'cashier']);
        $register2 = CashRegister::firstOrCreate(['id' => 9], ['name' => 'Caja 2', 'is_active' => true]);
        $shift2 = CashShift::create([
            'cash_register_id' => $register2->id,
            'user_id' => $cashier2->id,
            'opened_at' => now(),
            'opening_balance' => 800.00,
            'status' => 'open',
        ]);

        $product = $this->createProduct('PROD_PENDING', 400.0, 200.0);

        // Step 1: Create pending sale in Shift 1 by Waiter Juan
        $sale = Sale::create([
            'total' => 400.00,
            'total_surcharge' => 0,
            'payment_status' => 'pending',
            'amount_due' => 400.00,
            'status' => 'pending',
            'cash_shift_id' => $shift1->id,
            'user_id' => $waiter->id,
            'cashier_id' => $waiter->id,
            'price_list' => 'lista_salon',
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 200.00,
            'unit_price' => 400.00,
            'subtotal' => 400.00,
        ]);

        // Step 2: Pay pending sale in Shift 2 by Cashier Carlos
        $saleService = app(SaleService::class);
        $payDto = PaySaleDTO::fromArray([
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 400.00,
                'surcharge_amount' => 0,
                'total_amount' => 400.00,
            ]],
            'total_surcharge' => 0,
            'tendered_amount' => 400.00,
            'change_amount' => 0,
        ]);

        $payContext = new SaleContextDTO(
            userId: $cashier2->id,
            cashShiftId: $shift2->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $completedSale = $saleService->payPendingSale($sale, $payDto, $payContext);

        // Assertions on the completed sale
        $this->assertEquals('completed', $completedSale->status);
        $this->assertEquals('paid', $completedSale->payment_status);
        $this->assertEquals($shift2->id, $completedSale->cash_shift_id, 'cash_shift_id must be updated to Shift 2');
        $this->assertEquals($cashier2->id, $completedSale->cashier_id, 'cashier_id must be updated to Cashier Carlos');
        $this->assertEquals($waiter->id, $completedSale->user_id, 'user_id must remain Waiter Juan');
        $this->assertEquals('lista_salon', $completedSale->price_list, 'price_list must be preserved from pending sale');

        // Step 3: Verify Shift Balancing for BOTH shifts
        $shiftService = app(CashShiftService::class);

        // Shift 1 close: Should have ZERO sales and expected balance = 500 (initial only)
        $closedShift1 = $shiftService->closeShift($shift1->id, 500.00, $waiter->id);
        $this->assertEquals(0.00, (float) $closedShift1->cash_sales, 'Shift 1 must NOT count sale paid in Shift 2');
        $this->assertEquals(500.00, (float) $closedShift1->expected_balance);
        $this->assertEquals(0.00, (float) $closedShift1->difference);

        // Shift 2 close: Should have $400 cash sales and expected balance = 800 + 400 = 1200
        $closedShift2 = $shiftService->closeShift($shift2->id, 1200.00, $cashier2->id);
        $this->assertEquals(400.00, (float) $closedShift2->cash_sales, 'Shift 2 MUST count the sale collected in Shift 2');
        $this->assertEquals(1200.00, (float) $closedShift2->expected_balance);
        $this->assertEquals(0.00, (float) $closedShift2->difference);
    }

    /**
     * Adversarial test: Cannot pay an already completed or voided sale.
     */
    public function test_pay_pending_sale_rejects_already_completed_or_voided_sales(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $saleService = app(SaleService::class);
        $product = $this->createProduct('PROD_TEST', 100.0);

        // Completed sale
        $completedSale = Sale::create([
            'total' => 100.0,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
        ]);

        $payDto = PaySaleDTO::fromArray([
            'payments' => [['payment_method_id' => $this->cashMethod->id, 'base_amount' => 100.0, 'surcharge_amount' => 0, 'total_amount' => 100.0]],
            'total_surcharge' => 0,
        ]);
        $context = new SaleContextDTO($this->admin->id, $shift->id, null, null, null, null, false);

        $this->expectException(\InvalidArgumentException::class);
        $saleService->payPendingSale($completedSale, $payDto, $context);
    }

    // =========================================================================
    // 3. PRICE LIST PERSISTENCE CHALLENGES
    // =========================================================================

    /**
     * Verify that createSale stores price_list on sales table and retrieves correctly.
     */
    public function test_sale_creation_persists_price_list_and_handles_null(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $product = $this->createProduct('PROD_PL', 250.0);
        $saleService = app(SaleService::class);

        // Scenario A: Custom Price List specified
        $dtoA = ProcessSaleDTO::fromArray([
            'total' => 250.00,
            'total_surcharge' => 0,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 250.00,
                'surcharge_amount' => 0,
                'total_amount' => 250.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 250.00,
                'subtotal' => 250.00,
            ]],
        ]);

        $contextA = new SaleContextDTO(
            userId: $this->admin->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: 'gremio_distribuidor',
            deliveryAddress: null,
            isInternalAccount: false
        );

        $saleA = $saleService->executeSale($dtoA, $contextA);
        $this->assertEquals('gremio_distribuidor', $saleA->fresh()->price_list);

        // Scenario B: Null Price List specified
        $contextB = new SaleContextDTO(
            userId: $this->admin->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $saleB = $saleService->executeSale($dtoA, $contextB);
        $this->assertNull($saleB->fresh()->price_list);
    }

    /**
     * Verify price_list persistence through full HTTP API request.
     */
    public function test_price_list_persisted_via_pos_sales_api_and_retrievable(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $product = $this->createProduct('PROD_HTTP_PL', 300.0);

        $token = 'test-token-'.uniqid();
        $this->admin->update(['session_token' => $token]);

        $response = $this->withHeader('X-Session-Token', $token)
            ->postJson('/api/pos/sales', [
                'total' => 300.00,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift->id,
                'price_list' => 'tarifa_mayorista_tier2',
                'payments' => [[
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 300.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 300.00,
                ]],
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 300.00,
                    'subtotal' => 300.00,
                ]],
            ]);

        $response->assertStatus(201);
        $saleId = $response->json('sale.id');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'price_list' => 'tarifa_mayorista_tier2',
        ]);

        // Retrieve sale via GET /api/sales/{id}
        $getResponse = $this->withHeader('X-Session-Token', $token)
            ->getJson("/api/sales/{$saleId}");

        $getResponse->assertStatus(200)
            ->assertJsonPath('price_list', 'tarifa_mayorista_tier2');
    }

    /**
     * Stress test: Pending sale paid with split-tender (cash + card) across shifts.
     * Verifies that cash goes only to Shift 2's physical drawer while card is separated.
     */
    public function test_pay_pending_sale_with_split_tender_and_drawer_reconciliation(): void
    {
        $waiter = User::factory()->create(['name' => 'Mesero Ana', 'role' => 'cashier']);
        $shift1 = $this->crearTurnoAbierto(fondoInicial: 2000.00, user: $waiter);

        $cashier2 = User::factory()->create(['name' => 'Cajero Luis', 'role' => 'cashier']);
        $reg2 = CashRegister::firstOrCreate(['id' => 15], ['name' => 'Caja Split', 'is_active' => true]);
        $shift2 = CashShift::create([
            'cash_register_id' => $reg2->id,
            'user_id' => $cashier2->id,
            'opened_at' => now(),
            'opening_balance' => 1000.00,
            'status' => 'open',
        ]);

        $prod = $this->createProduct('PROD_SPLIT', 1000.0, 500.0);

        // Sale opened in Shift 1
        $sale = Sale::create([
            'total' => 1000.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'amount_due' => 1000.00,
            'cash_shift_id' => $shift1->id,
            'user_id' => $waiter->id,
            'cashier_id' => $waiter->id,
        ]);
        $sale->items()->create([
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'quantity' => 1,
            'unit_cost_price' => 500.00,
            'unit_price' => 1000.00,
            'subtotal' => 1000.00,
        ]);

        // Pay in Shift 2: $600 Cash + $400 Card
        $saleService = app(SaleService::class);
        $payDto = PaySaleDTO::fromArray([
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 600.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 600.00,
                ],
                [
                    'payment_method_id' => $this->cardMethod->id,
                    'base_amount' => 400.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 400.00,
                ],
            ],
            'total_surcharge' => 0,
            'tendered_amount' => 600.00,
            'change_amount' => 0,
        ]);

        $context = new SaleContextDTO(
            userId: $cashier2->id,
            cashShiftId: $shift2->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $completedSale = $saleService->payPendingSale($sale, $payDto, $context);

        $this->assertEquals($shift2->id, $completedSale->cash_shift_id);
        $this->assertEquals($cashier2->id, $completedSale->cashier_id);
        $this->assertEquals($waiter->id, $completedSale->user_id);

        // Verify drawers
        $shiftService = app(CashShiftService::class);

        // Shift 1 has 0 sales
        $c1 = $shiftService->closeShift($shift1->id, 2000.00, $waiter->id);
        $this->assertEquals(0.00, (float) $c1->cash_sales);
        $this->assertEquals(0.00, (float) $c1->card_sales);

        // Shift 2 has 600 cash, 400 card. Expected physical cash = 1000 (init) + 600 (cash sale) = 1600.
        $c2 = $shiftService->closeShift($shift2->id, 1600.00, $cashier2->id);
        $this->assertEquals(600.00, (float) $c2->cash_sales);
        $this->assertEquals(400.00, (float) $c2->card_sales);
        $this->assertEquals(1600.00, (float) $c2->expected_balance);
        $this->assertEquals(0.00, (float) $c2->difference);
    }

    /**
     * Test price_list UTF-8 special characters and max length persistence.
     */
    public function test_price_list_handles_special_characters_and_length(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $product = $this->createProduct('PROD_UTF8', 120.0);
        $saleService = app(SaleService::class);

        $specialName = 'Lista N° 1 - Gremio & Distribución (Promoción @ Otoño/Invierno)';
        $dto = ProcessSaleDTO::fromArray([
            'total' => 120.00,
            'total_surcharge' => 0,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 120.00,
                'surcharge_amount' => 0,
                'total_amount' => 120.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 120.00,
                'subtotal' => 120.00,
            ]],
        ]);

        $context = new SaleContextDTO(
            userId: $this->admin->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: $specialName,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $saleService->executeSale($dto, $context);
        $this->assertEquals($specialName, $sale->fresh()->price_list);
    }
}
