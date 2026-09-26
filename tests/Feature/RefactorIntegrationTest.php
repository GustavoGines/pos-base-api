<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Customer;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class RefactorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\ValidateSessionToken::class]);
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        // Setup initial POS environment
        $this->user = User::create([
            'name' => 'Cajero Test',
            'email' => 'cajero@pos.com',
            'password' => bcrypt('password'),
        ]);


        $register = \App\Models\CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);

        $this->shift = CashShift::create([
            'user_id' => $this->user->id,
            'cash_register_id' => $register->id,
            'status' => 'open',
            'opening_amount' => 1000,
            'opened_at' => now(),
        ]);

        $this->customer = Customer::create([
            'name' => 'Cliente VIP',
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'balance' => 0,
        ]);

        $this->productA = Product::create([
            'name' => 'Coca Cola',
            'internal_code' => 'P001',
            'stock' => 100,
            'cost_price' => 500,
            'price' => 1000,
            'active' => true,
        ]);

        $this->pmCash = PaymentMethod::firstOrCreate(['code' => 'efectivo'], ['name' => 'Efectivo', 'is_cash' => true]);
        $this->pmCC = PaymentMethod::firstOrCreate(['code' => 'cuenta_corriente'], ['name' => 'Cuenta Corriente', 'is_cash' => false]);
        $this->pmCheck = PaymentMethod::firstOrCreate(['code' => 'cheque'], ['name' => 'Cheque', 'is_cash' => false]);
    }

    public function test_anti_hacking_prevents_negative_prices()
    {
        $payload = [
            'total' => -1000, // MALICIOUS
            'total_surcharge' => 0,
            'status' => 'completed',
            'cash_shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 1,
                    'unit_price' => -1000,
                    'subtotal' => -1000,
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->pmCash->id,
                    'base_amount' => -1000,
                    'surcharge_amount' => 0,
                    'total_amount' => -1000,
                ]
            ]
        ];

        $response = $this->actingAs($this->user)->postJson('/api/pos/sales', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total', 'items.0.unit_price', 'payments.0.base_amount']);
    }

    public function test_cuenta_corriente_sale_updates_balance_and_requires_customer()
    {
        // 1. Missing Customer -> Should fail
        $payload = [
            'total' => 1000,
            'total_surcharge' => 0,
            'status' => 'completed',
            'cash_shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'subtotal' => 1000,
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->pmCC->id,
                    'base_amount' => 1000,
                    'surcharge_amount' => 0,
                    'total_amount' => 1000,
                ]
            ]
        ];

        $response = $this->actingAs($this->user)->postJson('/api/pos/sales', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer_id']);

        // 2. With Customer -> Should pass and update balance
        $payload['customer_id'] = $this->customer->id;
        $response = $this->actingAs($this->user)->postJson('/api/pos/sales', $payload);
        
        $response->assertStatus(201);
        $this->assertEquals(1000, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $this->customer->id,
            'type' => 'charge',
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $this->productA->id,
            'stock' => 99,
        ]);
    }

    public function test_order_recall_reconciles_stock_and_accepts_multi_checks()
    {
        // 1. Create a Pending Sale (No payments)
        $sale = Sale::create([
            'total' => 1000,
            'total_surcharge' => 0,
            'payment_status' => 'pending',
            'amount_due' => 1000,
            'status' => 'pending',
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'cash_shift_id' => $this->shift->id,
            'customer_id' => $this->customer->id,
        ]);

        $sale->items()->create([
            'product_id' => $this->productA->id,
            'product_name' => $this->productA->name,
            'quantity' => 1,
            'unit_cost_price' => 500,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);
        
        // Ensure stock was 100 initially, now it's 99 (assume it was deducted manually or by pending)
        // Wait, pending sales usually deduct stock on creation in this POS.
        $this->productA->update(['stock' => 99]);

        // 2. Order Recall: Add another Coca Cola (qty 2) and pay with 2 Checks
        $payload = [
            'total' => 2000,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'subtotal' => 2000,
                ]
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->pmCheck->id,
                    'base_amount' => 1000,
                    'surcharge_amount' => 0,
                    'total_amount' => 1000,
                ],
                [
                    'payment_method_id' => $this->pmCheck->id,
                    'base_amount' => 1000,
                    'surcharge_amount' => 0,
                    'total_amount' => 1000,
                ]
            ],
            'check_details' => [
                [
                    'bank_name' => 'Banco A',
                    'check_number' => 'CH-001',
                    'issue_date' => '2026-09-26',
                    'payment_date' => '2026-10-26',
                    'issuer_name' => 'Test',
                    'issuer_cuit' => '20123456789',
                ],
                [
                    'bank_name' => 'Banco B',
                    'check_number' => 'CH-002',
                    'issue_date' => '2026-09-26',
                    'payment_date' => '2026-10-26',
                    'issuer_name' => 'Test',
                    'issuer_cuit' => '20123456789',
                ]
            ]
        ];

        $response = $this->actingAs($this->user)->putJson("/api/sales/{$sale->id}/pay", $payload);
        $response->assertStatus(200);

        // Assert Stock Reconciled (100 -> 99 -> now 98)
        $this->assertEquals(98, $this->productA->fresh()->stock);

        // Assert 2 ThirdPartyChecks created
        $this->assertDatabaseCount('third_party_checks', 2);
        $this->assertDatabaseHas('third_party_checks', ['bank_name' => 'Banco A']);
        $this->assertDatabaseHas('third_party_checks', ['bank_name' => 'Banco B']);
    }

    public function test_void_sale_restores_stock_and_reverts_customer_balance()
    {
        $this->customer->update(['balance' => 1000]);

        $sale = Sale::create([
            'total' => 1000,
            'total_surcharge' => 0,
            'payment_status' => 'pending', // fully charged to CC
            'amount_due' => 1000,
            'status' => 'completed',
            'user_id' => $this->user->id,
            'cashier_id' => $this->user->id,
            'cash_shift_id' => $this->shift->id,
            'customer_id' => $this->customer->id,
        ]);

        $sale->items()->create([
            'product_id' => $this->productA->id,
            'product_name' => $this->productA->name,
            'quantity' => 1,
            'unit_cost_price' => 500,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);

        $this->productA->update(['stock' => 99]);

        // Create the charge transaction
        \App\Models\CustomerTransaction::create([
            'customer_id' => $this->customer->id,
            'sale_id' => $sale->id,
            'user_id' => $this->user->id,
            'type' => 'charge',
            'amount' => 1000,
            'balance_after' => 1000,
        ]);

        $payload = [
            'cash_shift_id' => $this->shift->id,
        ];

        $response = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/void", $payload);
        $response->assertStatus(200);

        // Assert Stock Restored
        $this->assertEquals(100, $this->productA->fresh()->stock);

        // Assert Balance Reversed
        $this->assertEquals(0, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_transactions', [
            'sale_id' => $sale->id,
            'type' => 'payment', // Reversal of charge
            'amount' => 1000,
        ]);
        
        // Assert API Contract
        $response->assertJsonStructure([
            'message',
            'sale' => [
                'cashier' => ['id', 'name'] // Ensure cashier is present!
            ]
        ]);
    }
}
