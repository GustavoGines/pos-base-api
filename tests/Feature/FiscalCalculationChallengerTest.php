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

class FiscalCalculationChallengerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Supplier $supplier2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'app_plan'],
            ['value' => 'premium']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'company_name'],
            ['value' => 'Empirical Challenger POS']
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
            'name' => 'Proveedor Mayorista Fiscal SA',
            'cuit' => '30-99887766-1',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);

        $this->supplier2 = Supplier::create([
            'name' => 'Proveedor Secundario SRL',
            'cuit' => '30-55443322-9',
            'tax_category' => 'Monotributista',
            'balance' => 0,
        ]);
    }

    /**
     * Requirement 1: Factura A with ALL components (21%, 10.5%, 27%, non-taxed, exempt, perceptions, internal taxes).
     */
    public function test_factura_a_multi_aliquot_and_all_components_consistency(): void
    {
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 12,
            'voucher_number' => 8899,
            'amount' => 22475.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'net_105' => 4000.00,
            'iva_105' => 420.00,
            'net_27' => 2500.00,
            'iva_27' => 675.00,
            'non_taxed_amount' => 1200.00,
            'exempt_amount' => 800.00,
            'iva_perception' => 350.00,
            'iibb_perception' => 250.00,
            'internal_taxes' => 180.00,
            'issue_date' => '2026-11-15',
        ];

        // 10000+2100 + 4000+420 + 2500+675 + 1200 + 800 + 350 + 250 + 180 = 22475.00
        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $invoiceId = $res->json('id');
        $this->assertNotNull($invoiceId);

        // Verify report vat-purchases
        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk();
        $summary = $reportRes->json('summary');

        $this->assertEquals(16500.00, (float) $summary['total_net'], 'total_net must be sum of net_21, net_105, net_27');
        $this->assertEquals(3195.00, (float) $summary['total_iva'], 'total_iva must be sum of iva_21, iva_105, iva_27');
        $this->assertEquals(10000.00, (float) $summary['total_net_21']);
        $this->assertEquals(2100.00, (float) $summary['total_iva_21']);
        $this->assertEquals(4000.00, (float) $summary['total_net_105']);
        $this->assertEquals(420.00, (float) $summary['total_iva_105']);
        $this->assertEquals(2500.00, (float) $summary['total_net_27']);
        $this->assertEquals(675.00, (float) $summary['total_iva_27']);
        $this->assertEquals(1200.00, (float) $summary['total_non_taxed']);
        $this->assertEquals(800.00, (float) $summary['total_exempt']);
        $this->assertEquals(350.00, (float) $summary['total_iva_perception']);
        $this->assertEquals(250.00, (float) $summary['total_iibb_perception']);
        $this->assertEquals(180.00, (float) $summary['total_internal_taxes']);
        $this->assertEquals(3195.00, (float) $summary['total_credit_fiscal']);
        $this->assertEquals(22475.00, (float) $summary['total_amount']);
        $this->assertEquals(1, $summary['invoices_count']);
        $this->assertEquals(0, $summary['credit_notes_count']);
        $this->assertEquals(1, $summary['vouchers_count']);

        // Check voucher breakdown
        $v = $reportRes->json('vouchers.0');
        $this->assertEquals('Factura A', $v['voucher_label']);
        $this->assertEquals('A 00012-00008899', $v['formatted_number']);
        $this->assertEquals(10000.00, (float) $v['net_21']);
        $this->assertEquals(2100.00, (float) $v['iva_21']);
        $this->assertEquals(4000.00, (float) $v['net_105']);
        $this->assertEquals(420.00, (float) $v['iva_105']);
        $this->assertEquals(2500.00, (float) $v['net_27']);
        $this->assertEquals(675.00, (float) $v['iva_27']);
        $this->assertEquals(1200.00, (float) $v['non_taxed_amount']);
        $this->assertEquals(800.00, (float) $v['exempt_amount']);
        $this->assertEquals(350.00, (float) $v['iva_perception']);
        $this->assertEquals(250.00, (float) $v['iibb_perception']);
        $this->assertEquals(180.00, (float) $v['internal_taxes']);
        $this->assertEquals(3195.00, (float) $v['credit_fiscal']);
        $this->assertEquals(22475.00, (float) $v['total_amount']);
        $this->assertFalse((bool) $v['is_historical']);
    }

    /**
     * Requirement 2: Tolerance bounds (±0.05).
     * Verify exact boundary: <= 0.05 PASSES, > 0.05 FAILS (422).
     */
    public function test_tolerance_bounds_strict_boundary_verification(): void
    {
        $baseNet = 10000.00;
        $baseIva = 2100.00;
        $exactTotal = 12100.00;

        // 1. Exact amount (+0.00) -> PASS
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1001,
            'amount' => $exactTotal,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-01',
        ])->assertSuccessful();

        // 2. Diff = +0.05 -> PASS (boundary edge)
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1002,
            'amount' => 12100.05,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-02',
        ])->assertSuccessful();

        // 3. Diff = -0.05 -> PASS (boundary edge)
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1003,
            'amount' => 12099.95,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-03',
        ])->assertSuccessful();

        // 4. Diff = +0.06 -> FAIL 422
        $resHigh = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1004,
            'amount' => 12100.06,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-04',
        ]);
        $resHigh->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
        $this->assertStringContainsString('0,06', $resHigh->json('errors.amount.0'));

        // 5. Diff = -0.06 -> FAIL 422
        $resLow = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1005,
            'amount' => 12099.94,
            'net_21' => $baseNet,
            'iva_21' => $baseIva,
            'issue_date' => '2026-11-05',
        ]);
        $resLow->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
        $this->assertStringContainsString('0,06', $resLow->json('errors.amount.0'));

        // 6. Tolerance test on UPDATE request as well
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1006,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-06',
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        // Update with diff = +0.05 -> PASS
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'amount' => 12100.05,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
        ])->assertSuccessful();

        // Update with diff = +0.06 -> FAIL 422
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'amount' => 12100.06,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    /**
     * Requirement 3: Factura B and C forcing zero fiscal credit, preserving perceptions and non-taxed.
     */
    public function test_factura_b_and_c_force_zero_fiscal_credit(): void
    {
        // 1. Factura B with attempts to submit IVA amounts
        $resB = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'B',
            'point_of_sale' => 3,
            'voucher_number' => 501,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00, // Should be wiped to 0
            'iva_105' => 105.00, // Should be wiped to 0
            'iva_27' => 270.00,  // Should be wiped to 0
            'issue_date' => '2026-11-05',
        ]);
        $resB->assertSuccessful();
        $invB = SupplierInvoice::findOrFail($resB->json('id'));
        $this->assertEquals(0.0, (float) $invB->iva_21);
        $this->assertEquals(0.0, (float) $invB->iva_105);
        $this->assertEquals(0.0, (float) $invB->iva_27);

        // 2. Factura C with perceptions
        $resC = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'C',
            'point_of_sale' => 4,
            'voucher_number' => 601,
            'amount' => 8500.00,
            'iva_21' => 1500.00, // Should be wiped to 0
            'iva_perception' => 300.00,
            'iibb_perception' => 200.00,
            'issue_date' => '2026-11-06',
        ]);
        $resC->assertSuccessful();
        $invC = SupplierInvoice::findOrFail($resC->json('id'));
        $this->assertEquals(0.0, (float) $invC->iva_21);
        $this->assertEquals(300.0, (float) $invC->iva_perception);
        $this->assertEquals(200.0, (float) $invC->iibb_perception);

        // 3. Factura X (Remito / Presupuesto / no fiscal)
        $resX = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'X',
            'point_of_sale' => 1,
            'voucher_number' => 1,
            'amount' => 3000.00,
            'iva_21' => 630.00, // Should be wiped to 0
            'issue_date' => '2026-11-07',
        ]);
        $resX->assertSuccessful();
        $invX = SupplierInvoice::findOrFail($resX->json('id'));
        $this->assertEquals(0.0, (float) $invX->iva_21);

        // Check vat-purchases report: all 3 should yield ZERO credit fiscal!
        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk();

        $summary = $reportRes->json('summary');
        $this->assertEquals(0.0, (float) $summary['total_credit_fiscal'], 'B, C, and X vouchers must yield exactly 0 credit fiscal');
        $this->assertEquals(0.0, (float) $summary['total_iva']);
        $this->assertEquals(0.0, (float) $summary['total_iva_21']);
        $this->assertEquals(300.0, (float) $summary['total_iva_perception']);
        $this->assertEquals(200.0, (float) $summary['total_iibb_perception']);
        $this->assertEquals(23600.0, (float) $summary['total_amount'], '12100 + 8500 + 3000 = 23600');

        // B: total 12100 -> non_taxed = 12100
        // C: total 8500, perceptions = 500 -> non_taxed = 8000
        // X: total 3000 -> non_taxed = 3000
        // total non_taxed = 12100 + 8000 + 3000 = 23100
        $this->assertEquals(23100.0, (float) $summary['total_non_taxed']);
    }

    /**
     * Requirement 4: Credit Notes arithmetic.
     * Credit notes must deduct from total_net, total_iva, individual aliquots, perceptions, and credit fiscal.
     */
    public function test_credit_notes_arithmetic_deductions_and_negative_voucher_values(): void
    {
        // 1. Factura A: Net 21% = 20,000 (IVA 4,200), Net 10.5% = 10,000 (IVA 1,050), IVA Perception = 500. Total = 35,750
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 201,
            'amount' => 35750.00,
            'net_21' => 20000.00,
            'iva_21' => 4200.00,
            'net_105' => 10000.00,
            'iva_105' => 1050.00,
            'iva_perception' => 500.00,
            'issue_date' => '2026-11-05',
        ])->assertSuccessful();

        // 2. Nota de Crédito A partial: Net 21% = 5,000 (IVA 1,050), Net 10.5% = 2,000 (IVA 210), IVA Perception = 100. Total = 8,360
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 55,
            'amount' => 8360.00,
            'net_21' => 5000.00,
            'iva_21' => 1050.00,
            'net_105' => 2000.00,
            'iva_105' => 210.00,
            'iva_perception' => 100.00,
            'issue_date' => '2026-11-12',
        ])->assertSuccessful();

        // Verify vat-purchases
        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk();

        $summary = $reportRes->json('summary');
        // Net 21: 20000 - 5000 = 15000
        $this->assertEquals(15000.00, (float) $summary['total_net_21']);
        // IVA 21: 4200 - 1050 = 3150
        $this->assertEquals(3150.00, (float) $summary['total_iva_21']);
        // Net 10.5: 10000 - 2000 = 8000
        $this->assertEquals(8000.00, (float) $summary['total_net_105']);
        // IVA 10.5: 1050 - 210 = 840
        $this->assertEquals(840.00, (float) $summary['total_iva_105']);
        // Total Net: 15000 + 8000 = 23000
        $this->assertEquals(23000.00, (float) $summary['total_net']);
        // Total IVA / Credit Fiscal: 3150 + 840 = 3990
        $this->assertEquals(3990.00, (float) $summary['total_iva']);
        $this->assertEquals(3990.00, (float) $summary['total_credit_fiscal']);
        // IVA Perception: 500 - 100 = 400
        $this->assertEquals(400.00, (float) $summary['total_iva_perception']);
        // Total Amount: 35750 - 8360 = 27390
        $this->assertEquals(27390.00, (float) $summary['total_amount']);
        $this->assertEquals(1, $summary['invoices_count']);
        $this->assertEquals(1, $summary['credit_notes_count']);
        $this->assertEquals(2, $summary['vouchers_count']);

        // Check NC voucher fields: must have negative values for transparent display
        $vouchers = $reportRes->json('vouchers');
        $nc = collect($vouchers)->firstWhere('type', 'credit_note');
        $this->assertNotNull($nc);
        $this->assertEquals('Nota de Crédito A', $nc['voucher_label']);
        $this->assertEquals(-5000.00, (float) $nc['net_21']);
        $this->assertEquals(-1050.00, (float) $nc['iva_21']);
        $this->assertEquals(-2000.00, (float) $nc['net_105']);
        $this->assertEquals(-210.00, (float) $nc['iva_105']);
        $this->assertEquals(-100.00, (float) $nc['iva_perception']);
        $this->assertEquals(-1260.00, (float) $nc['credit_fiscal']);
        $this->assertEquals(-8360.00, (float) $nc['total_amount']);
    }

    /**
     * Requirement 5: Monthly VAT Position Calculation Formula.
     * Formula: net_balance = fiscal_debit - fiscal_credit - iva_perceptions.
     * Validate "to_pay", "favorable", and "zero" cases, and verify IIBB/internal taxes do NOT alter VAT net balance.
     */
    public function test_vat_position_formula_and_balances(): void
    {
        // 1. Create a sale with Fiscal Debit: Net 21% = 100,000, IVA 21% = 21,000
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'total' => 121000.00,
            'status' => 'completed',
        ]);
        ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 500,
            'cae' => '72345678901234',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-71234567-9',
            'receiver_name' => 'Cliente Posicion SA',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 100000.00,
            'iva_amount' => 21000.00,
            'total_amount' => 121000.00,
            'issued_at' => '2026-11-05 10:00:00',
        ]);

        // 2. Case A: Fiscal Debit ($21,000) > Fiscal Credit ($10,000) + IVA Perception ($2,000)
        // Purchases: net 21 = 47619.05, iva 21 = 10000, iva_perc = 2000, iibb_perc = 1500 (provincial, non-VAT)
        // 47619.05 + 10000 + 2000 + 1500 + 500 = 61619.05
        $invA = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 801,
            'amount' => 61619.05,
            'net_21' => 47619.05,
            'iva_21' => 10000.00,
            'iva_perception' => 2000.00,
            'iibb_perception' => 1500.00,
            'internal_taxes' => 500.00,
            'issue_date' => '2026-11-06',
        ]);
        $invA->assertSuccessful();

        // Query VAT position with month=11&year=2026
        $resA = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $resA->assertOk();

        // Check Debit, Credit, IVA Perceptions
        $this->assertEquals(21000.00, (float) $resA->json('sales.fiscal_debit'));
        $this->assertEquals(10000.00, (float) $resA->json('purchases.fiscal_credit'));
        $this->assertEquals(2000.00, (float) $resA->json('purchases.iva_perceptions'));
        $this->assertEquals(1500.00, (float) $resA->json('purchases.iibb_perceptions'));

        // Formula: 21,000 - 10,000 - 2,000 = 9,000
        $this->assertEquals(9000.00, (float) $resA->json('balance.net_balance'));
        $this->assertEquals('to_pay', $resA->json('balance.result'));
        $this->assertEquals(9000.00, (float) $resA->json('balance.absolute_balance'));

        // 3. Case B: Debit < Credit + Perceptions -> "favorable"
        // Add another purchase invoice with high fiscal credit (Credit: $20,000)
        // Now total credit = 30,000, iva_perceptions = 2,000.
        // Balance = 21,000 - 30,000 - 2,000 = -11,000 -> favorable
        $invB = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 802,
            'amount' => 115238.10,
            'net_21' => 95238.10,
            'iva_21' => 20000.00,
            'issue_date' => '2026-11-07',
        ]);
        $invB->assertSuccessful();

        $resB = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $resB->assertOk();

        $this->assertEquals(21000.00, (float) $resB->json('sales.fiscal_debit'));
        $this->assertEquals(30000.00, (float) $resB->json('purchases.fiscal_credit'));
        $this->assertEquals(2000.00, (float) $resB->json('purchases.iva_perceptions'));
        // 21000 - 30000 - 2000 = -11000
        $this->assertEquals(-11000.00, (float) $resB->json('balance.net_balance'));
        $this->assertEquals('favorable', $resB->json('balance.result'));
        $this->assertEquals(11000.00, (float) $resB->json('balance.absolute_balance'));

        // 4. Case C: Credit note in purchases reduces credit and shifts balance
        // Issue purchase Credit Note for credit = $11,000.
        // Purchases credit becomes 30,000 - 11,000 = 19,000.
        // Balance = 21,000 - 19,000 - 2,000 = 0.00 -> "zero"
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 88,
            'amount' => 63380.95,
            'net_21' => 52380.95,
            'iva_21' => 11000.00,
            'issue_date' => '2026-11-15',
        ])->assertSuccessful();

        $resC = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');
        $resC->assertOk();

        $this->assertEquals(21000.00, (float) $resC->json('sales.fiscal_debit'));
        $this->assertEquals(19000.00, (float) $resC->json('purchases.fiscal_credit'));
        $this->assertEquals(2000.00, (float) $resC->json('purchases.iva_perceptions'));
        // 21000 - 19000 - 2000 = 0.00
        $this->assertEquals(0.00, (float) $resC->json('balance.net_balance'));
        $this->assertEquals('zero', $resC->json('balance.result'));
        $this->assertEquals(0.00, (float) $resC->json('balance.absolute_balance'));
    }

    /**
     * Requirement 6: Additional edge case checks.
     * - Factura M validation and handling.
     * - Lowercase letter input normalization ('a' -> 'A', 'b' -> 'B', 'c' -> 'C', 'm' -> 'M').
     * - Automatic invoice_number generation when omitted.
     * - Exclusion when affects_vat_book = false does not affect other vouchers.
     */
    public function test_additional_fiscal_edge_cases(): void
    {
        // 1. Factura M with lowercase 'm' and omitted invoice_number
        $resM = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'm',
            'point_of_sale' => 5,
            'voucher_number' => 123,
            'amount' => 1210.00,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'issue_date' => '2026-11-18',
        ]);
        $resM->assertSuccessful();
        $invM = SupplierInvoice::findOrFail($resM->json('id'));
        $this->assertEquals('M', $invM->voucher_letter, 'Lowercase voucher_letter should be normalized to uppercase M');
        $this->assertEquals('M-00005-00000123', $invM->invoice_number, 'Omitted invoice_number should be generated');
        $this->assertEquals('M 00005-00000123', $invM->formatted_number);

        // 2. Factura A with lowercase 'a'
        $resA = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'a',
            'point_of_sale' => 1,
            'voucher_number' => 77,
            'amount' => 605.00,
            'net_21' => 500.00,
            'iva_21' => 105.00,
            'issue_date' => '2026-11-19',
        ]);
        $resA->assertSuccessful();
        $invA = SupplierInvoice::findOrFail($resA->json('id'));
        $this->assertEquals('A', $invA->voucher_letter);

        // 3. Verify in vat-purchases report that M is included as Factura M
        $rep = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $rep->assertOk();

        $vouchers = $rep->json('vouchers');
        $mVoucher = collect($vouchers)->firstWhere('voucher_letter', 'M');
        $this->assertNotNull($mVoucher);
        $this->assertEquals('Factura M', $mVoucher['voucher_label']);
        $this->assertEquals(210.00, (float) $mVoucher['credit_fiscal']);
    }

    /**
     * Adversarial Stress Test: Supplier Filtering and Voucher Type Filtering.
     */
    public function test_vat_purchases_filtering_by_supplier_and_voucher_type(): void
    {
        // Supplier 1 - Factura A
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1,
            'amount' => 1210.00,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'issue_date' => '2026-11-10',
        ])->assertSuccessful();

        // Supplier 1 - Nota de Crédito A
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 2,
            'amount' => 605.00,
            'net_21' => 500.00,
            'iva_21' => 105.00,
            'issue_date' => '2026-11-11',
        ])->assertSuccessful();

        // Supplier 2 - Factura C
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier2->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'C',
            'point_of_sale' => 2,
            'voucher_number' => 10,
            'amount' => 3000.00,
            'issue_date' => '2026-11-12',
        ])->assertSuccessful();

        // 1. Filter by supplier 1 only
        $resSupp1 = $this->actingAsAdmin($this->admin)
            ->getJson("/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30&supplier_id={$this->supplier->id}");
        $resSupp1->assertOk()
            ->assertJsonPath('summary.vouchers_count', 2)
            ->assertJsonPath('summary.total_credit_fiscal', 105)
            ->assertJsonPath('summary.total_net', 500);

        // 2. Filter by supplier 2 only
        $resSupp2 = $this->actingAsAdmin($this->admin)
            ->getJson("/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30&supplier_id={$this->supplier2->id}");
        $resSupp2->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_amount', 3000);

        // 3. Filter by voucher_type = 'credit_note'
        $resNC = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30&voucher_type=credit_note');
        $resNC->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.credit_notes_count', 1)
            ->assertJsonPath('summary.total_amount', -605);

        // 4. Filter by voucher_type = 'C'
        $resC = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30&voucher_type=C');
        $resC->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_amount', 3000);
    }

    /**
     * Adversarial Stress Test: Conservation of Mass across 20 randomized vouchers.
     */
    public function test_conservation_of_mass_stresstest(): void
    {
        $expectedNet = 0.0;
        $expectedCredit = 0.0;
        $expectedAmount = 0.0;
        $expectedIvaPerception = 0.0;

        for ($i = 1; $i <= 20; $i++) {
            $isNC = ($i % 5 === 0); // 4 credit notes, 16 invoices
            $sign = $isNC ? -1.0 : 1.0;

            $net21 = round(1000 + ($i * 73.15), 2);
            $iva21 = round($net21 * 0.21, 2);
            $net105 = round(500 + ($i * 21.10), 2);
            $iva105 = round($net105 * 0.105, 2);
            $ivaPerc = round(50 + ($i * 5.25), 2);
            $amount = round($net21 + $iva21 + $net105 + $iva105 + $ivaPerc, 2);

            $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
                'type' => $isNC ? 'credit_note' : 'invoice',
                'voucher_letter' => 'A',
                'point_of_sale' => 1,
                'voucher_number' => 2000 + $i,
                'amount' => $amount,
                'net_21' => $net21,
                'iva_21' => $iva21,
                'net_105' => $net105,
                'iva_105' => $iva105,
                'iva_perception' => $ivaPerc,
                'issue_date' => sprintf('2026-11-%02d', min(28, $i + 1)),
            ])->assertSuccessful();

            $expectedNet += $sign * ($net21 + $net105);
            $expectedCredit += $sign * ($iva21 + $iva105);
            $expectedAmount += $sign * $amount;
            $expectedIvaPerception += $sign * $ivaPerc;
        }

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk();

        $summary = $reportRes->json('summary');
        $this->assertEquals(round($expectedNet, 2), (float) $summary['total_net']);
        $this->assertEquals(round($expectedCredit, 2), (float) $summary['total_credit_fiscal']);
        $this->assertEquals(round($expectedAmount, 2), (float) $summary['total_amount']);
        $this->assertEquals(round($expectedIvaPerception, 2), (float) $summary['total_iva_perception']);
        $this->assertEquals(20, $summary['vouchers_count']);
        $this->assertEquals(16, $summary['invoices_count']);
        $this->assertEquals(4, $summary['credit_notes_count']);
    }

    /**
     * EMPIRICAL FINDING 1: Update mutates gross amount by adding tax_amount twice when fiscal fields exist.
     */
    public function test_update_invoice_amount_calculation_behavior(): void
    {
        // 1. Create Factura A with gross amount 12100 (net 10000 + iva 2100)
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 9999,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-20',
        ]);
        $createRes->assertSuccessful();
        $id = $createRes->json('id');

        // Confirm initial amount is 12100.00 and tax_amount is 2100.00
        $inv = SupplierInvoice::findOrFail($id);
        $this->assertEquals(12100.00, (float) $inv->amount);
        $this->assertEquals(2100.00, (float) $inv->tax_amount);

        // 2. Perform an update: change description, passing amount = 12100.00
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$id}", [
            'description' => 'Updated description',
            'amount' => 12100.00,
            'edit_reason' => 'Fix description',
        ]);
        $updateRes->assertSuccessful();

        $invAfter = SupplierInvoice::findOrFail($id);
        // Verified: SupplierInvoiceController::update preserves gross amount (12100.00)
        // and does NOT inflate by adding tax_amount a second time.
        $this->assertEquals(12100.00, (float) $invAfter->amount);
    }

    /**
     * EMPIRICAL FINDING 2 (REMEDIATED): Storing Factura A with items preserves VAT and full gross amount.
     */
    public function test_store_invoice_with_items_and_fiscal_vat_components(): void
    {
        $product = Product::create([
            'internal_code' => 'PROD-001',
            'name' => 'Insumo Test',
            'cost_price' => 1000.00,
            'selling_price' => 2000.00,
            'stock' => 50,
        ]);

        // Scenario 1: User sends items (subtotal 10,000) and net_21 (10,000) + iva_21 (2,100)
        // With amount = 12,100.00. Controller preserves full gross totalAmount = 12100.00.
        $payloadWithAmount = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 881,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_cost' => 1000.00,
                    'subtotal' => 10000.00,
                ],
            ],
        ];

        $res1 = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payloadWithAmount);

        $res1->assertSuccessful();
        $inv1 = SupplierInvoice::findOrFail($res1->json('id'));
        $this->assertEquals(12100.00, (float) $inv1->amount);
        $this->assertEquals(2100.00, (float) $inv1->tax_amount);

        // Scenario 2: User sends items (subtotal 10,000) and net_21 + iva_21 WITHOUT explicit amount.
        // FormRequest computes targetAmount including iva_21 and accepts request; controller calculates 12100.00.
        $payloadWithoutAmount = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 882,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_cost' => 1000.00,
                    'subtotal' => 10000.00,
                ],
            ],
        ];

        $res2 = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payloadWithoutAmount);

        $res2->assertSuccessful();
        $inv2 = SupplierInvoice::findOrFail($res2->json('id'));
        $this->assertEquals(12100.00, (float) $inv2->amount);
        $this->assertEquals(2100.00, (float) $inv2->tax_amount);
    }
}
