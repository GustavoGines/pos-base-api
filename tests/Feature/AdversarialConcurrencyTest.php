<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use App\Services\ReportCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdversarialConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $shift;
    protected $supplier;
    protected $check;

    private function crearVentaCompletada(Product $product, float $qty = 1): Sale
    {
        $metodo = $this->crearMetodoEfectivo();
        $sale = Sale::create([
            'total' => $product->selling_price * $qty,
            'total_surcharge' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $qty,
            'unit_price' => $product->selling_price,
            'unit_cost_price' => $product->cost_price,
            'subtotal' => $product->selling_price * $qty,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount' => $product->selling_price * $qty,
            'surcharge_amount' => 0,
            'total_amount' => $product->selling_price * $qty,
        ]);

        $product->decrement('stock', $qty);

        return $sale;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => bcrypt('1234'),
        ]);
        $this->actingAsAdmin($this->admin);

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['checks' => true, 'expenses' => true])]
        );

        $this->shift = $this->crearTurnoAbierto(5000.00, $this->admin);
        $this->supplier = Supplier::create([
            'name' => 'Proveedor Mayorista Concurrencia SA',
            'balance' => 200000.00,
        ]);

        $customer = Customer::create([
            'name' => 'Cliente Corporativo',
            'document_number' => '30998877665',
        ]);

        $this->check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Banco Nacion',
            'check_number' => 'CHK-ADV-001',
            'amount' => 75000.00,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Cliente Corporativo',
            'issuer_cuit' => '30998877665',
            'status' => 'in_wallet',
        ]);
    }

    /**
     * TEST 1: Stress test distinct rule with explicit nulls across multiple cash and transfer items.
     * Verifies that multiple null check_ids do not trigger false positive 422 errors.
     */
    public function test_adversarial_multiple_cash_and_transfers_with_explicit_null_check_ids(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 1000.00, 'payment_method' => 'cash', 'check_id' => null],
                ['amount' => 2000.00, 'payment_method' => 'cash', 'check_id' => null],
                ['amount' => 3000.00, 'payment_method' => 'transfer', 'check_id' => null],
                ['amount' => 4000.00, 'payment_method' => 'transfer', 'check_id' => null],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $this->supplier->refresh();
        $this->assertEquals(115000.00, (float) $this->supplier->balance);
        $this->assertEquals(5, CashMovement::count());
    }

    /**
     * TEST 2: Amount tampering edge cases.
     * Boundary check: $75,000.00 nominal value.
     */
    public function test_adversarial_amount_tampering_boundaries(): void
    {
        // 1 cent more ($75,000.01) -> must be rejected
        $resHigh = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 75000.01, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resHigh->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);

        // 1 cent less ($74,999.99) -> must be rejected
        $resLow = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 74999.99, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resLow->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);

        // Zero amount -> must be rejected
        $resZero = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 0.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resZero->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);

        // Negative amount -> must be rejected
        $resNeg = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => -75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resNeg->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);
    }

    /**
     * TEST 3: Attempting to use non-in_wallet statuses.
     * Tests deposited, cancelled, rejected statuses.
     */
    public function test_adversarial_rejects_non_wallet_check_statuses(): void
    {
        $statuses = ['deposited', 'cancelled', 'rejected', 'bounced'];

        foreach ($statuses as $badStatus) {
            $this->check->update(['status' => $badStatus]);

            $response = $this->postJson('/api/cash-movements', [
                'type' => 'supplier_payment',
                'supplier_id' => $this->supplier->id,
                'payments' => [
                    ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
                ],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['payments.0.check_id']);
        }
    }

    /**
     * TEST 4: Atomic Rollback in Mixed Payment when Check Lock Fails.
     * Cash and Transfer movements created BEFORE the check must be rolled back.
     */
    public function test_adversarial_mixed_payment_atomic_rollback_preserves_cash_and_supplier_state(): void
    {
        $initialBalance = (float) $this->supplier->balance;
        $initialMovementsCount = CashMovement::count();

        // Simulate concurrent endorsement during saving of the check movement
        CashMovement::saving(function ($movement) {
            if ($movement->payment_method === 'check') {
                $this->check->update(['status' => 'endorsed']);
            }
        });

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 15000.00, 'payment_method' => 'cash'],
                ['amount' => 25000.00, 'payment_method' => 'transfer'],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Error crítico al procesar el movimiento.',
                'error' => "El cheque #{$this->check->id} ya no se encuentra disponible en cartera.",
            ]);

        // Zero new movements persisted
        $this->assertEquals($initialMovementsCount, CashMovement::count());

        // Supplier balance completely unchanged
        $this->supplier->refresh();
        $this->assertEquals($initialBalance, (float) $this->supplier->balance);
    }

    /**
     * TEST 5: Voiding (destroy) clean reversal, audit integrity, and double-delete prevention.
     */
    public function test_adversarial_destroy_full_clean_cycle_and_double_delete(): void
    {
        // 1. Create mixed payment
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'receipt_number' => 'REC-ADV-999',
            'payments' => [
                ['amount' => 10000.00, 'payment_method' => 'cash'],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $response->assertStatus(201);
        $movements = $response->json('movements');
        $this->assertCount(2, $movements);

        $cashMoveId = $movements[0]['id'];
        $checkMoveId = $movements[1]['id'];

        // Endorsement check verified
        $this->check->refresh();
        $this->assertEquals('endorsed', $this->check->status);
        $this->assertEquals($this->supplier->id, $this->check->supplier_id);
        $this->assertStringContainsString('REC-ADV-999', $this->check->endorsement_note);

        $this->supplier->refresh();
        $this->assertEquals(115000.00, (float) $this->supplier->balance);

        // 2. Void the cash movement first
        $delCash = $this->deleteJson("/api/cash-movements/{$cashMoveId}");
        $delCash->assertStatus(200);

        // Supplier balance increases by cash amount ($10,000)
        $this->supplier->refresh();
        $this->assertEquals(125000.00, (float) $this->supplier->balance);

        // Check is STILL endorsed!
        $this->check->refresh();
        $this->assertEquals('endorsed', $this->check->status);
        $this->assertEquals($this->supplier->id, $this->check->supplier_id);

        // 3. Void the check movement
        $delCheck = $this->deleteJson("/api/cash-movements/{$checkMoveId}");
        $delCheck->assertStatus(200);

        // Supplier balance fully restored to 200,000.00
        $this->supplier->refresh();
        $this->assertEquals(200000.00, (float) $this->supplier->balance);

        // Check completely sanitized back to wallet
        $this->check->refresh();
        $this->assertEquals('in_wallet', $this->check->status);
        $this->assertNull($this->check->supplier_id);
        $this->assertNull($this->check->endorsement_note);

        // 4. Try to delete the same movement a second time -> MUST 404 (Not Found)
        $delAgain = $this->deleteJson("/api/cash-movements/{$checkMoveId}");
        $delAgain->assertStatus(404);

        // Balance remains unchanged (no double refund)
        $this->supplier->refresh();
        $this->assertEquals(200000.00, (float) $this->supplier->balance);
    }

    /**
     * TEST 6: Multiple duplicate checks across different indices.
     */
    public function test_adversarial_three_identical_checks_in_payload(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'payments.0.check_id',
                'payments.1.check_id',
                'payments.2.check_id',
            ]);
    }

    /**
     * TEST 7: Cross-type tender bleed test.
     * Verifies that supplier_id is prohibited if type is expense.
     */
    public function test_adversarial_expense_type_prohibits_supplier_id(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'expense',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 500.00, 'payment_method' => 'cash'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    /**
     * TEST 8: Missing receipt number generates graceful note (S/N).
     */
    public function test_adversarial_endorsement_note_without_receipt_number(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'receipt_number' => null,
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $this->check->refresh();
        $this->assertStringContainsString('(S/N)', $this->check->endorsement_note);
    }

    /**
     * TEST 9: Batch UUID generation and movement linkage.
     * Verifies that all movements created in a multi-tender request return a single batch_uuid.
     */
    public function test_adversarial_batch_uuid_linkage(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 10000.00, 'payment_method' => 'cash'],
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $batchUuid = $response->json('batch_uuid');
        $this->assertNotNull($batchUuid);
        $this->assertTrue(Str::isUuid($batchUuid));

        $movements = $response->json('movements');
        $this->assertCount(2, $movements);
        $this->assertEquals(10000.00, (float) $movements[0]['amount']);
        $this->assertEquals('cash', $movements[0]['payment_method']);
        $this->assertEquals(75000.00, (float) $movements[1]['amount']);
        $this->assertEquals('check', $movements[1]['payment_method']);
    }

    /**
     * TEST 10: Race condition where terminal A attempts voidSale while terminal B
     * concurrently endorses the check to a supplier.
     */
    public function test_adversarial_race_condition_void_sale_fails_when_check_is_concurrently_endorsed(): void
    {
        $product = Product::create([
            'name' => 'Producto Concurrencia Race',
            'internal_code' => 'CHQ-RACE-01',
            'selling_price' => 75000.00,
            'cost_price' => 40000.00,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, qty: 1);
        $this->check->update(['sale_id' => $sale->id]);

        // Simular que justo antes de que voidSale verifique el cheque, otra terminal lo endosa
        ThirdPartyCheck::updating(function ($c) {
            // No intervenir
        });

        // Endosamos el cheque simulando carrera
        $this->check->update([
            'status' => 'endorsed',
            'supplier_id' => $this->supplier->id,
            'endorsement_note' => 'Endosado por terminal B',
        ]);

        $response = $this->actingAsAdmin($this->admin)
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $this->shift->id]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'endosado'));

        // Integridad atómica: La venta NO se anuló, el stock no se restauró doblemente
        $this->assertEquals('completed', $sale->fresh()->status);
        $this->assertEquals(9, $product->fresh()->stock);
        $this->assertEquals('endorsed', $this->check->fresh()->status);
    }

    /**
     * TEST 11: Active cash movement protects check from being returned to in_wallet via API.
     */
    public function test_adversarial_cannot_return_check_to_in_wallet_if_active_cash_movement_exists(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $response->assertStatus(201);

        // Intentar actualizar status del cheque directamente a in_wallet por endpoint
        $patchRes = $this->patchJson("/api/third-party-checks/{$this->check->id}/status", [
            'status' => 'in_wallet',
        ]);

        $patchRes->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'movimiento de caja') || str_contains($m, 'proveedor'));

        $this->assertEquals('endorsed', $this->check->fresh()->status);
    }

    /**
     * TEST 12: Cash payment with redundant check_id sanitizes check_id to null in database.
     */
    public function test_adversarial_cash_payment_with_redundant_check_id_sanitizes_to_null(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 5000.00, 'payment_method' => 'cash', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $movement = CashMovement::latest('id')->first();
        $this->assertNull($movement->check_id, 'Check ID must be sanitized to null if payment method is not check.');
        $this->assertEquals('in_wallet', $this->check->fresh()->status);
    }

    /**
     * TEST 13: Destroying a movement does not resurrect an already voided check.
     */
    public function test_adversarial_destroy_movement_does_not_resurrect_voided_check(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $response->assertStatus(201);
        $movementId = $response->json('movements.0.id');

        // Supongamos que por anulación administrativa el cheque se marcó como voided
        $this->check->update(['status' => 'voided']);

        // Anular el movimiento de caja
        $delResponse = $this->deleteJson("/api/cash-movements/{$movementId}");
        $delResponse->assertStatus(200);

        // El cheque debe PERMANECER en voided, NO resucitar a in_wallet
        $this->assertEquals('voided', $this->check->fresh()->status);
    }

    /**
     * TEST 14: Report cache is invalidated when a supplier payment is created and deleted.
     */
    public function test_adversarial_report_cache_invalidated_on_supplier_payment_lifecycle(): void
    {
        $v1 = ReportCacheService::getVersion();

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 1000.00, 'payment_method' => 'cash'],
            ],
        ]);
        $response->assertStatus(201);
        $movementId = $response->json('movements.0.id');

        $v2 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v1, $v2, 'Report cache must invalidate when supplier_payment is created.');

        $delResponse = $this->deleteJson("/api/cash-movements/{$movementId}");
        $delResponse->assertStatus(200);

        $v3 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v2, $v3, 'Report cache must invalidate when supplier_payment is destroyed.');
    }

    /**
     * TEST 15: Payment method 'check' is strictly prohibited for 'expense' movements.
     */
    public function test_adversarial_check_payment_method_rejected_for_expense_movement(): void
    {
        $category = \App\Models\ExpenseCategory::create(['name' => 'Luz y Gas']);

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'expense',
            'expense_category_id' => $category->id,
            'description' => 'Pago de factura con cheque',
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.payment_method']);

        // Check must stay in wallet and untouched
        $this->assertEquals('in_wallet', $this->check->fresh()->status);
        $this->assertNull($this->check->fresh()->supplier_id);
    }

    /**
     * TEST 16: Payment method 'check' is strictly prohibited for 'withdrawal' and 'deposit' movements.
     */
    public function test_adversarial_check_payment_method_rejected_for_withdrawal_and_deposit(): void
    {
        // 1. Withdrawal
        $resWithdrawal = $this->postJson('/api/cash-movements', [
            'type' => 'withdrawal',
            'description' => 'Retiro de dueño con cheque',
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resWithdrawal->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.payment_method']);

        // 2. Deposit
        $resDeposit = $this->postJson('/api/cash-movements', [
            'type' => 'deposit',
            'description' => 'Depósito extra con cheque',
            'payments' => [
                ['amount' => 75000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $resDeposit->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.payment_method']);

        // Check must remain untouched in wallet
        $this->assertEquals('in_wallet', $this->check->fresh()->status);
    }
}
