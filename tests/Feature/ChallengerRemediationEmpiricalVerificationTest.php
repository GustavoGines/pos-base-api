<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChallengerRemediationEmpiricalVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'app_plan'],
            ['value' => 'premium']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'company_name'],
            ['value' => 'Challenger Empirical Verification POS']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'tax_id'],
            ['value' => '30-11223344-5']
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Proveedor Verificacion Empirical SA',
            'cuit' => '30-99887766-1',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);
    }

    /**
     * STRESS TEST 1: Updating an invoice with `amount` matching gross
     * Verifies that total amount stays gross and does NOT inflate by tax.
     */
    public function test_edge_case_update_gross_amount_retains_gross_and_does_not_inflate(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 101,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-20',
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        $this->assertEquals(12100.00, (float) $this->supplier->fresh()->balance);

        // Update passing explicit gross amount and new description
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'amount' => 12100.00,
            'description' => 'Updated without altering gross total',
            'edit_reason' => 'Empirical test step 1',
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(12100.00, (float) $invoice->amount, 'Invoice amount must remain 12100.00 and not inflate to 14200.00');
        $this->assertEquals(2100.00, (float) $invoice->tax_amount);
        $this->assertEquals(12100.00, (float) $this->supplier->fresh()->balance, 'Supplier balance must remain 12100.00');
    }

    /**
     * STRESS TEST 2: Updating an invoice omitting `amount` and items
     * Verifies that invoice retains original amount and does NOT collapse to tax.
     */
    public function test_edge_case_update_omitting_amount_and_items_retains_original_amount_and_balance(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 102,
            'amount' => 18150.00,
            'net_21' => 15000.00,
            'iva_21' => 3150.00,
            'issue_date' => '2026-11-20',
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        $this->assertEquals(18150.00, (float) $this->supplier->fresh()->balance);

        // Update omitting amount and items (e.g. toggling affects_vat_book or editing description)
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'description' => 'Only updating description, amount omitted',
            'edit_reason' => 'Empirical test step 2',
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(18150.00, (float) $invoice->amount, 'Invoice amount must NOT collapse to tax (3150.00); must retain 18150.00');
        $this->assertEquals(3150.00, (float) $invoice->tax_amount);
        $this->assertEquals(18150.00, (float) $this->supplier->fresh()->balance, 'Supplier balance must retain 18150.00');
    }

    /**
     * STRESS TEST 3: Storing an invoice with `items` and fiscal fields omitting `amount`
     * Verifies that FormRequest does not throw false 422 and controller includes VAT, perceptions, freight in $totalAmount and balance.
     */
    public function test_edge_case_store_with_items_and_fiscal_fields_omitting_amount(): void
    {
        $product = Product::create([
            'internal_code' => 'P-VERIF-01',
            'name' => 'Producto Verificacion 1',
            'cost_price' => 5000.00,
            'selling_price' => 8000.00,
            'stock' => 10,
        ]);

        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 103,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'iva_perception' => 300.00,
            'iibb_perception' => 250.00,
            'internal_taxes' => 150.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_cost' => 5000.00,
                    'subtotal' => 10000.00,
                ],
            ],
        ];

        // Expected total:
        // Items subtotal: 10000
        // Tax (iva_21): 2100
        // Perceptions (300 + 250 + 150): 700
        // Total = 10000 + 2100 + 700 = 12800.00
        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $id = $res->json('id');

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(12800.00, (float) $invoice->amount, 'Invoice amount must include VAT, perceptions');
        $this->assertEquals(2100.00, (float) $invoice->tax_amount);
        $this->assertEquals(12800.00, (float) $this->supplier->fresh()->balance, 'Supplier balance must match total invoice debt');
    }

    /**
     * STRESS TEST 4: Storing an invoice with `items` and explicit gross `amount`
     * Verifies that `$validated['amount']` is preserved and not overwritten with net subtotal.
     */
    public function test_edge_case_store_with_items_and_explicit_gross_amount(): void
    {
        $product = Product::create([
            'internal_code' => 'P-VERIF-02',
            'name' => 'Producto Verificacion 2',
            'cost_price' => 2000.00,
            'selling_price' => 3500.00,
            'stock' => 20,
        ]);

        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 104,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_cost' => 2000.00,
                    'subtotal' => 10000.00,
                ],
            ],
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $id = $res->json('id');

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(12100.00, (float) $invoice->amount, 'Explicit gross amount must be preserved');
        $this->assertEquals(2100.00, (float) $invoice->tax_amount);
        $this->assertEquals(12100.00, (float) $this->supplier->fresh()->balance);
    }

    /**
     * STRESS TEST 5: FormRequest validation with items when tax_amount is 0 or omitted
     * Verifies that no false 422 is thrown when iva_21/105/27 are present.
     */
    public function test_edge_case_validating_items_without_tax_amount_does_not_throw_false_422(): void
    {
        $product = Product::create([
            'internal_code' => 'P-VERIF-03',
            'name' => 'Producto Verificacion 3',
            'cost_price' => 1000.00,
            'selling_price' => 1500.00,
            'stock' => 50,
        ]);

        // Scenario: Multi-aliquot items invoice without tax_amount in request
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 105,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'net_105' => 2000.00,
            'iva_105' => 210.00,
            'net_27' => 3000.00,
            'iva_27' => 810.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 6,
                    'unit_cost' => 1000.00,
                    'subtotal' => 6000.00, // 1000 + 2000 + 3000
                ],
            ],
        ];

        // Total expected = 6000 + 210 + 210 + 810 = 7230.00
        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $invoice = SupplierInvoice::findOrFail($res->json('id'));
        $this->assertEquals(7230.00, (float) $invoice->amount);
        $this->assertEquals(1230.00, (float) $invoice->tax_amount);
    }

    /**
     * STRESS TEST 6A: Updating an invoice with new items and explicit gross amount succeeds.
     */
    public function test_edge_case_update_with_new_items_with_explicit_amount_succeeds(): void
    {
        $prodA = Product::create([
            'internal_code' => 'P-VERIF-04A',
            'name' => 'Producto A',
            'cost_price' => 1000.00,
            'selling_price' => 1500.00,
            'stock' => 50,
        ]);
        $prodB = Product::create([
            'internal_code' => 'P-VERIF-04B',
            'name' => 'Producto B',
            'cost_price' => 2000.00,
            'selling_price' => 3000.00,
            'stock' => 50,
        ]);

        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1061,
            'amount' => 6050.00,
            'net_21' => 5000.00,
            'iva_21' => 1050.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $prodA->id,
                    'quantity' => 5,
                    'unit_cost' => 1000.00,
                    'subtotal' => 5000.00,
                ],
            ],
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'amount' => 9680.00,
            'net_21' => 8000.00,
            'iva_21' => 1680.00,
            'edit_reason' => 'Update items with explicit amount',
            'items' => [
                [
                    'product_id' => $prodB->id,
                    'quantity' => 4,
                    'unit_cost' => 2000.00,
                    'subtotal' => 8000.00,
                ],
            ],
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(9680.00, (float) $invoice->amount);
        $this->assertEquals(1680.00, (float) $invoice->tax_amount);
        $this->assertEquals(9680.00, (float) $this->supplier->fresh()->balance);
    }

    /**
     * STRESS TEST 6B (EMPIRICAL BUG REPRODUCTION):
     * Updating an invoice with new items and new fiscal aliquots (omitting amount)
     * Fails with HTTP 422 because UpdateSupplierInvoiceRequest line 158 pulls stale tax_amount from existing invoice!
     */
    public function test_edge_case_update_with_new_items_recalculates_amount_and_balance(): void
    {
        $prodA = Product::create([
            'internal_code' => 'P-VERIF-04A2',
            'name' => 'Producto A2',
            'cost_price' => 1000.00,
            'selling_price' => 1500.00,
            'stock' => 50,
        ]);
        $prodB = Product::create([
            'internal_code' => 'P-VERIF-04B2',
            'name' => 'Producto B2',
            'cost_price' => 2000.00,
            'selling_price' => 3000.00,
            'stock' => 50,
        ]);

        // 1. Initial invoice: 5 units of prodA = 5000 net, 1050 vat. Total = 6050
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 106,
            'amount' => 6050.00,
            'net_21' => 5000.00,
            'iva_21' => 1050.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $prodA->id,
                    'quantity' => 5,
                    'unit_cost' => 1000.00,
                    'subtotal' => 5000.00,
                ],
            ],
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');
        $this->assertEquals(6050.00, (float) $this->supplier->fresh()->balance);

        // 2. Update with new items: 4 units of prodB = 8000 net, 1680 vat. Total = 9680 (omitting amount)
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'net_21' => 8000.00,
            'iva_21' => 1680.00,
            'edit_reason' => 'Update items and fiscal values',
            'items' => [
                [
                    'product_id' => $prodB->id,
                    'quantity' => 4,
                    'unit_cost' => 2000.00,
                    'subtotal' => 8000.00,
                ],
            ],
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(9680.00, (float) $invoice->amount);
        $this->assertEquals(1680.00, (float) $invoice->tax_amount);
        $this->assertEquals(9680.00, (float) $this->supplier->fresh()->balance, 'Supplier balance must reflect exact updated total');
    }

    /**
     * STRESS TEST 7: Credit Note update omitting amount preserves negative ledger balance
     */
    public function test_edge_case_credit_note_update_omitting_amount_preserves_negative_balance(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 107,
            'amount' => 6050.00,
            'net_21' => 5000.00,
            'iva_21' => 1050.00,
            'issue_date' => '2026-11-20',
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        $this->assertEquals(-6050.00, (float) $this->supplier->fresh()->balance);

        // Update description only
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'description' => 'Updated credit note description',
            'edit_reason' => 'Empirical test credit note',
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($id);
        $this->assertEquals(6050.00, (float) $invoice->amount);
        $this->assertEquals(-6050.00, (float) $this->supplier->fresh()->balance, 'Credit note balance must remain negative 6050.00');
    }
}
