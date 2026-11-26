<?php

namespace Tests\Feature;

use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChallengerM1Gen3AdversarialTest extends TestCase
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
            ['value' => 'Challenger Gen3 Impositivo']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'tax_id'],
            ['value' => '30-77889900-3']
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Distribuidora Fiscal Central SA',
            'cuit' => '30-66554433-2',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);
    }

    /**
     * Test 1: Boundary Tolerance Strictness.
     * Difference <= $0.05 passes, difference >= $0.06 fails with HTTP 422 on store and update.
     */
    public function test_boundary_tolerance_exact_005_and_006(): void
    {
        $baseNet = 10000.00;
        $baseIva = 2100.00;
        $expectedTotal = 12100.00;

        // 1. Difference +0.05 -> PASS (200/201)
        $resPassHigh = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 101,
            'amount' => $expectedTotal + 0.05,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-01',
        ]);
        $resPassHigh->assertSuccessful();

        // 2. Difference -0.05 -> PASS (200/201)
        $resPassLow = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 102,
            'amount' => $expectedTotal - 0.05,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-02',
        ]);
        $resPassLow->assertSuccessful();

        // 3. Difference +0.06 -> FAIL 422
        $resFailHigh = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 103,
            'amount' => $expectedTotal + 0.06,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-03',
        ]);
        $resFailHigh->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertStringContainsString('0,06', $resFailHigh->json('errors.amount.0'));

        // 4. Difference -0.06 -> FAIL 422
        $resFailLow = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 104,
            'amount' => $expectedTotal - 0.06,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-04',
        ]);
        $resFailLow->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertStringContainsString('0,06', $resFailLow->json('errors.amount.0'));

        // 5. Update boundary: Create invoice with exact total
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 105,
            'amount' => $expectedTotal,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-05',
        ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        // Update with diff = +0.05 -> PASS
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'amount' => $expectedTotal + 0.05,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
        ])->assertSuccessful();

        // Update with diff = +0.06 -> FAIL 422
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'amount' => $expectedTotal + 0.06,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test 2: Adversarial Negative Values Injection.
     * All fiscal numeric fields and amounts must reject negative inputs with HTTP 422.
     */
    public function test_negative_values_rejected_with_422(): void
    {
        // Negative amount
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'amount' => -100.00,
            'issue_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // Negative net_21
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 201,
            'amount' => 1000.00,
            'net_21' => -500.00,
            'iva_21' => 1500.00,
            'issue_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['net_21']);

        // Negative iva_21
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 202,
            'amount' => 1000.00,
            'net_21' => 1200.00,
            'iva_21' => -200.00,
            'issue_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['iva_21']);

        // Negative iva_perception
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 203,
            'amount' => 1210.00,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'iva_perception' => -50.00,
            'issue_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['iva_perception']);

        // Negative credit_note amount
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 204,
            'amount' => -1210.00,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'issue_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test 3: Pure v1.8.5 Legacy Payloads and Retrocompatibility.
     * Legacy payloads without any fiscal fields store cleanly, calculate amounts correctly,
     * and are reported as historical records in vat-purchases.
     */
    public function test_pure_v185_legacy_payloads(): void
    {
        // 1. Bare minimal legacy invoice
        $resMinimal = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'amount' => 7500.00,
            'issue_date' => '2026-11-01',
        ]);
        $resMinimal->assertSuccessful();
        $idMinimal = $resMinimal->json('id');

        $invMinimal = SupplierInvoice::findOrFail($idMinimal);
        $this->assertNull($invMinimal->voucher_letter);
        $this->assertNull($invMinimal->point_of_sale);
        $this->assertNull($invMinimal->voucher_number);
        $this->assertEquals(7500.00, (float) $invMinimal->amount);
        $this->assertEquals('S/N', $invMinimal->formatted_number);

        // 2. Legacy invoice with items, freight, discount, and tax_amount
        $product = Product::create([
            'internal_code' => 'LEGACY-P1',
            'name' => 'Legacy Item',
            'cost_price' => 500.00,
            'selling_price' => 1000.00,
            'stock' => 10,
        ]);

        $resLegacyComplex = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'invoice_number' => 'FAC-LEGACY-001',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 4,
                    'unit_cost' => 500.00,
                    'subtotal' => 2000.00,
                ],
            ],
            'freight_amount' => 200.00,
            'tax_amount' => 420.00,
            'discount_amount' => 100.00,
            'issue_date' => '2026-11-02',
        ]);
        $resLegacyComplex->assertSuccessful();
        $idComplex = $resLegacyComplex->json('id');

        $invComplex = SupplierInvoice::findOrFail($idComplex);
        // subtotal (2000) + freight (200) + tax (420) - discount (100) = 2520
        $this->assertEquals(2520.00, (float) $invComplex->amount);
        $this->assertEquals(420.00, (float) $invComplex->tax_amount);

        // 3. Update legacy invoice with legacy fields only
        $resUpdate = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$idComplex}", [
            'description' => 'Updated legacy description',
            'status' => 'paid',
        ]);
        $resUpdate->assertSuccessful();

        $invComplexFresh = $invComplex->fresh();
        $this->assertEquals('Updated legacy description', $invComplexFresh->description);
        $this->assertEquals('paid', $invComplexFresh->status);
        $this->assertEquals(2520.00, (float) $invComplexFresh->amount);
    }

    /**
     * Test 4: Exclusion of affects_vat_book = false and Soft-Deleted Invoices.
     */
    public function test_vat_purchases_report_excludes_flag_false_and_soft_deleted(): void
    {
        // 1. Invoice with affects_vat_book = false
        $resFlagFalse = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 301,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'affects_vat_book' => false,
            'issue_date' => '2026-11-05',
        ]);
        $resFlagFalse->assertSuccessful();

        // 2. Invoice with affects_vat_book = true (normal)
        $resNormal = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 302,
            'amount' => 24200.00,
            'net_21' => 20000.00,
            'iva_21' => 4200.00,
            'affects_vat_book' => true,
            'issue_date' => '2026-11-06',
        ]);
        $resNormal->assertSuccessful();
        $normalId = $resNormal->json('id');

        // Check report: only the normal invoice should be counted
        $rep1 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $rep1->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 4200)
            ->assertJsonPath('summary.total_amount', 24200);

        // Soft delete the normal invoice
        $this->actingAsAdmin($this->admin)
            ->deleteJson("/api/supplier-invoices/{$normalId}")
            ->assertSuccessful();

        // Report should now have 0 vouchers
        $rep2 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $rep2->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_amount', 0);

        // Also vat-position should have 0 purchases fiscal credit
        $pos = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $pos->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.total_purchases_amount', 0);

        // Restore the normal invoice
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/supplier-invoices/{$normalId}/restore")
            ->assertSuccessful();

        // Report should count it again
        $rep3 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $rep3->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 4200);
    }

    /**
     * Test 5: Credit Note Subtraction and Ledger Integrity.
     * Credit Notes subtract from purchases, fiscal credit, aliquots, and supplier balance.
     */
    public function test_credit_note_subtractions_and_ledger_balance(): void
    {
        $initialBalance = (float) $this->supplier->fresh()->balance;
        $this->assertEquals(0.0, $initialBalance);

        // 1. Factura A: 12,100 gross (net 10,000 + iva 2,100)
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 401,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-10',
        ])->assertSuccessful();

        $this->assertEquals(12100.00, (float) $this->supplier->fresh()->balance);

        // 2. Nota de Crédito A: 4,840 gross (net 4,000 + iva 840)
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 402,
            'amount' => 4840.00,
            'net_21' => 4000.00,
            'iva_21' => 840.00,
            'issue_date' => '2026-11-12',
        ])->assertSuccessful();

        // Balance must decrease by 4,840 -> 7,260
        $this->assertEquals(7260.00, (float) $this->supplier->fresh()->balance);

        // Verify vat-purchases report
        $report = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report->assertOk();

        $summary = $report->json('summary');
        // Net: 10,000 - 4,000 = 6,000
        $this->assertEquals(6000.00, (float) $summary['total_net_21']);
        $this->assertEquals(6000.00, (float) $summary['total_net']);
        // IVA / Credit: 2,100 - 840 = 1,260
        $this->assertEquals(1260.00, (float) $summary['total_iva_21']);
        $this->assertEquals(1260.00, (float) $summary['total_credit_fiscal']);
        // Amount: 12,100 - 4,840 = 7,260
        $this->assertEquals(7260.00, (float) $summary['total_amount']);
        $this->assertEquals(1, $summary['invoices_count']);
        $this->assertEquals(1, $summary['credit_notes_count']);
    }

    /**
     * Test 6: Monthly VAT Position Balance Formula.
     * Formula: net_balance = fiscal_debit - fiscal_credit - iva_perceptions.
     * Verify that provincial taxes (IIBB) and internal taxes do NOT alter VAT net_balance.
     */
    public function test_vat_position_formula_and_non_vat_tax_isolation(): void
    {
        // 1. Create Sale with ElectronicInvoice: Debit 21% = $15,000
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'total' => 86428.57,
            'status' => 'completed',
        ]);
        ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 701,
            'cae' => '71112223334445',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-71234567-9',
            'receiver_name' => 'Cliente Mayorista SA',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 71428.57,
            'iva_amount' => 15000.00,
            'total_amount' => 86428.57,
            'issued_at' => '2026-11-10 14:00:00',
        ]);

        // 2. Create Purchase Invoice with Credit = $8,000, IVA Perception = $1,500,
        // IIBB Perception = $2,000 (Provincial), Internal Taxes = $1,000
        // Total amount = net (38,095.24) + iva (8,000) + iva_perc (1,500) + iibb_perc (2,000) + internal (1,000) = 50,595.24
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 702,
            'amount' => 50595.24,
            'net_21' => 38095.24,
            'iva_21' => 8000.00,
            'iva_perception' => 1500.00,
            'iibb_perception' => 2000.00,
            'internal_taxes' => 1000.00,
            'issue_date' => '2026-11-11',
        ])->assertSuccessful();

        // 3. Query VAT position
        $posRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $posRes->assertOk();

        $debit = (float) $posRes->json('sales.fiscal_debit');
        $credit = (float) $posRes->json('purchases.fiscal_credit');
        $ivaPerc = (float) $posRes->json('purchases.iva_perceptions');
        $iibbPerc = (float) $posRes->json('purchases.iibb_perceptions');
        $netBalance = (float) $posRes->json('balance.net_balance');
        $result = $posRes->json('balance.result');

        $this->assertEquals(15000.00, $debit);
        $this->assertEquals(8000.00, $credit);
        $this->assertEquals(1500.00, $ivaPerc);
        $this->assertEquals(2000.00, $iibbPerc);

        // Expected: 15,000 (debit) - 8,000 (credit) - 1,500 (iva_perception) = 5,500
        // Notice: IIBB (2,000) and Internal Taxes (1,000) must NOT reduce VAT net_balance!
        $this->assertEquals(5500.00, $netBalance);
        $this->assertEquals('to_pay', $result);
        $this->assertEquals('Saldo Estimado a Pagar a ARCA', $posRes->json('balance.result_label'));
        $this->assertEquals(5500.00, (float) $posRes->json('balance.absolute_balance'));
    }

    /**
     * Test 7: Adversarial Case - Net Credit Balance Shift.
     * When Credit Fiscal + IVA Perceptions exceed Fiscal Debit, balance turns favorable.
     */
    public function test_vat_position_favorable_balance_transition(): void
    {
        // 1. Sale: Fiscal Debit = $5,000
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'total' => 28809.52,
            'status' => 'completed',
        ]);
        ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 801,
            'cae' => '71112223334446',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-71234567-9',
            'receiver_name' => 'Cliente Favorable SA',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 23809.52,
            'iva_amount' => 5000.00,
            'total_amount' => 28809.52,
            'issued_at' => '2026-11-15 10:00:00',
        ]);

        // 2. High Purchase Credit: Fiscal Credit = $12,000, IVA Perception = $1,000
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 802,
            'amount' => 70142.86,
            'net_21' => 57142.86,
            'iva_21' => 12000.00,
            'iva_perception' => 1000.00,
            'issue_date' => '2026-11-16',
        ])->assertSuccessful();

        // 3. Query VAT position
        $pos = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $pos->assertOk();

        // Formula: 5,000 - 12,000 - 1,000 = -8,000
        $this->assertEquals(-8000.00, (float) $pos->json('balance.net_balance'));
        $this->assertEquals('favorable', $pos->json('balance.result'));
        $this->assertEquals('Saldo a Favor Técnico del Contribuyente', $pos->json('balance.result_label'));
        $this->assertEquals(8000.00, (float) $pos->json('balance.absolute_balance'));
    }
}
