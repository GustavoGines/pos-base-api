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

class LibroIvaComprasAdversarialChallengeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private Supplier $supplierA;

    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'app_plan'],
            ['value' => 'premium']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'company_name'],
            ['value' => 'Empresa Adversarial Test']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'tax_id'],
            ['value' => '30-99887766-5']
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'pin' => Hash::make('5678'),
        ]);

        $this->supplierA = Supplier::create([
            'name' => 'Proveedor RI Principal SA',
            'cuit' => '30-11223344-9',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);

        $this->supplierB = Supplier::create([
            'name' => 'Proveedor Monotributo Simple SRL',
            'cuit' => '20-22334455-8',
            'tax_category' => 'Monotributo',
            'balance' => 0,
        ]);
    }

    protected function actingAsUser(User $user): static
    {
        $token = 'test-token-'.uniqid();
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $this;
    }

    /**
     * EMPIRICAL CHECK 1: Legacy payloads (v1.8.5)
     * Submitting invoices without ANY fiscal fields must succeed and default safely.
     */
    public function test_empirical_01_legacy_v185_payloads_without_any_fiscal_fields(): void
    {
        // 1.1 Legacy creation without items, without any fiscal fields
        $legacyPayload1 = [
            'amount' => 1500.00,
            'description' => 'Compra legacy sin campos fiscales',
            'issue_date' => '2026-11-04',
        ];

        $res1 = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplierA->id}/invoices", $legacyPayload1);

        $res1->assertSuccessful();
        $id1 = $res1->json('id');
        $this->assertNotNull($id1);

        $inv1 = SupplierInvoice::findOrFail($id1);
        $this->assertEquals('invoice', $inv1->type);
        $this->assertTrue((bool) $inv1->affects_vat_book);
        $this->assertNull($inv1->voucher_letter);
        $this->assertNull($inv1->point_of_sale);
        $this->assertNull($inv1->voucher_number);
        $this->assertEquals(0.0, (float) $inv1->net_21);
        $this->assertEquals(0.0, (float) $inv1->iva_21);
        $this->assertEquals(0.0, (float) $inv1->tax_amount);

        // 1.2 Legacy creation with items and legacy tax_amount
        $prod = Product::create([
            'name' => 'Producto Legacy Test',
            'internal_code' => 'P-LEGACY-001',
            'cost_price' => 100.00,
            'selling_price' => 200.00,
            'stock' => 10,
        ]);

        $legacyPayload2 = [
            'type' => 'invoice',
            'invoice_number' => 'FAC-OLD-999',
            'amount' => 2420.00,
            'tax_amount' => 420.00,
            'issue_date' => '2026-11-05',
            'items' => [
                [
                    'product_id' => $prod->id,
                    'quantity' => 20,
                    'unit_cost' => 100.00,
                    'subtotal' => 2000.00,
                ],
            ],
        ];

        $res2 = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplierA->id}/invoices", $legacyPayload2);

        $res2->assertSuccessful();
        $id2 = $res2->json('id');

        $inv2 = SupplierInvoice::findOrFail($id2);
        $this->assertEquals('FAC-OLD-999', $inv2->invoice_number);
        $this->assertEquals(420.0, (float) $inv2->tax_amount);
        $this->assertTrue((bool) $inv2->affects_vat_book);

        // 1.3 Legacy update using PUT without any fiscal fields
        $updatePayload = [
            'description' => 'Descripción actualizada vía payload v1.8.5',
            'amount' => 2420.00,
            'edit_reason' => 'Ajuste legacy',
        ];

        $resUpdate = $this->actingAsAdmin($this->admin)
            ->putJson("/api/supplier-invoices/{$id2}", $updatePayload);

        $resUpdate->assertSuccessful();

        $inv2Updated = SupplierInvoice::findOrFail($id2);
        $this->assertEquals('Descripción actualizada vía payload v1.8.5', $inv2Updated->description);
        $this->assertTrue((bool) $inv2Updated->affects_vat_book);

        // 1.4 Check show / formatInvoice output
        $showRes = $this->actingAsAdmin($this->admin)
            ->getJson("/api/supplier-invoices/{$id2}");

        $showRes->assertOk();
        $this->assertEquals('FAC-OLD-999', $showRes->json('formatted_number'));
        $this->assertTrue($showRes->json('affects_vat_book'));
    }

    /**
     * EMPIRICAL CHECK 2: Exclusion behavior (affects_vat_book = false)
     * Invoices with affects_vat_book = false must be totally excluded from both vat-purchases and vat-position.
     * Dynamic toggling via update must be reflected immediately.
     */
    public function test_empirical_02_affects_vat_book_false_excludes_from_reports_and_toggles(): void
    {
        // 2.1 Create an invoice with affects_vat_book = false
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 888,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'affects_vat_book' => false,
            'issue_date' => '2026-11-08',
        ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        // Check vat-purchases report: must be completely empty (0 vouchers, 0 credit fiscal)
        $purchasesRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $purchasesRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_net', 0)
            ->assertJsonPath('summary.total_amount', 0);

        // Check vat-position report: fiscal_credit must be 0
        $positionRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');

        $positionRes->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.total_purchases_amount', 0)
            ->assertJsonPath('purchases.invoices_count', 0);

        // 2.2 Dynamic toggle: Update affects_vat_book to true
        $invModel = SupplierInvoice::findOrFail($invoiceId);
        $invModel->update(['affects_vat_book' => true]);

        // Check vat-purchases report again: now it must be included!
        $purchasesRes2 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $purchasesRes2->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 2100)
            ->assertJsonPath('summary.total_amount', 12100);

        // Check vat-position: now includes fiscal_credit = 2100
        $positionRes2 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');

        $positionRes2->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 2100)
            ->assertJsonPath('balance.fiscal_credit', 2100);

        // 2.3 Dynamic toggle: Update affects_vat_book back to false
        $invModel->update(['affects_vat_book' => false]);

        $positionRes3 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');

        $positionRes3->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0);
    }

    /**
     * EMPIRICAL CHECK 3: Soft-deletes behavior
     * Soft-deleted invoices and credit notes must not appear in vat-purchases or vat-position.
     * Restoring them must immediately re-include them.
     */
    public function test_empirical_03_soft_deletes_and_restoration_for_invoices_and_credit_notes(): void
    {
        // 3.1 Create Factura A
        $invRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 701,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-10',
        ]);
        $invRes->assertSuccessful();
        $invoiceId = $invRes->json('id');

        // 3.2 Create Nota de Crédito A
        $ncRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 15,
            'amount' => 2420.00,
            'net_21' => 2000.00,
            'iva_21' => 420.00,
            'issue_date' => '2026-11-12',
        ]);
        $ncRes->assertSuccessful();
        $ncId = $ncRes->json('id');

        // Initial combined credit fiscal = 2100 - 420 = 1680
        $report1 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report1->assertOk()
            ->assertJsonPath('summary.vouchers_count', 2)
            ->assertJsonPath('summary.total_credit_fiscal', 1680)
            ->assertJsonPath('summary.total_net', 8000);

        // Soft-delete the credit note
        $this->actingAsAdmin($this->admin)
            ->deleteJson("/api/supplier-invoices/{$ncId}")
            ->assertSuccessful();

        // Now report must only have the invoice: credit fiscal = 2100
        $report2 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report2->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 2100)
            ->assertJsonPath('summary.total_net', 10000);

        // Soft-delete the invoice as well
        $this->actingAsAdmin($this->admin)
            ->deleteJson("/api/supplier-invoices/{$invoiceId}")
            ->assertSuccessful();

        // Both deleted: report must have 0 vouchers
        $report3 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report3->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0);

        // Position report must also show 0
        $posReport = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');
        $posReport->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0);

        // Restore invoice
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/supplier-invoices/{$invoiceId}/restore")
            ->assertSuccessful();

        $report4 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report4->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 2100);

        // Restore credit note
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/supplier-invoices/{$ncId}/restore")
            ->assertSuccessful();

        $report5 = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $report5->assertOk()
            ->assertJsonPath('summary.vouchers_count', 2)
            ->assertJsonPath('summary.total_credit_fiscal', 1680);
    }

    /**
     * EMPIRICAL CHECK 4: Historical record behavior
     * Invoices with tax_amount > 0 and no aliquot breakdown must be treated as is_historical: true,
     * assigning tax_amount to 21% net/IVA. Invoices without tax_amount are treated as non-taxed.
     */
    public function test_empirical_04_historical_record_graceful_breakdown(): void
    {
        // 4.1 Historical invoice with tax_amount > 0
        $histInv1 = SupplierInvoice::create([
            'supplier_id' => $this->supplierA->id,
            'type' => 'invoice',
            'status' => 'paid',
            'amount' => 1210.00,
            'tax_amount' => 210.00,
            'invoice_number' => 'HIST-2025-01',
            'issue_date' => '2026-11-15',
            'voucher_letter' => null, // empty/legacy
            'affects_vat_book' => true,
        ]);

        // 4.2 Historical invoice with tax_amount = 0 (e.g. monotributo or exento antiguo)
        $histInv2 = SupplierInvoice::create([
            'supplier_id' => $this->supplierB->id,
            'type' => 'invoice',
            'status' => 'paid',
            'amount' => 5000.00,
            'tax_amount' => 0.00,
            'invoice_number' => 'HIST-2025-02',
            'issue_date' => '2026-11-16',
            'voucher_letter' => null,
            'affects_vat_book' => true,
        ]);

        // 4.3 Historical credit note with tax_amount > 0
        $histNC = SupplierInvoice::create([
            'supplier_id' => $this->supplierA->id,
            'type' => 'credit_note',
            'status' => 'paid',
            'amount' => 605.00,
            'tax_amount' => 105.00,
            'invoice_number' => 'HIST-NC-01',
            'issue_date' => '2026-11-17',
            'voucher_letter' => null,
            'affects_vat_book' => true,
        ]);

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk();
        $summary = $reportRes->json('summary');
        $vouchers = $reportRes->json('vouchers');

        $this->assertEquals(3, $summary['vouchers_count']);
        // Credit fiscal: 210 (inv1) + 0 (inv2) - 105 (NC) = 105
        $this->assertEquals(105.0, (float) $summary['total_credit_fiscal']);
        // Net: 1000 (inv1) + 0 (inv2) - 500 (NC) = 500
        $this->assertEquals(500.0, (float) $summary['total_net_21']);
        // Non-taxed: 5000 (inv2)
        $this->assertEquals(5000.0, (float) $summary['total_non_taxed']);
        // Total amount: 1210 + 5000 - 605 = 5605
        $this->assertEquals(5605.0, (float) $summary['total_amount']);

        // Inspect individual vouchers
        $v1 = collect($vouchers)->firstWhere('invoice_number', 'HIST-2025-01');
        $this->assertTrue((bool) $v1['is_historical']);
        $this->assertEquals(1000.0, (float) $v1['net_21']);
        $this->assertEquals(210.0, (float) $v1['iva_21']);
        $this->assertEquals(210.0, (float) $v1['credit_fiscal']);
        $this->assertEquals('HIST-2025-01', $v1['formatted_number']);

        $v2 = collect($vouchers)->firstWhere('invoice_number', 'HIST-2025-02');
        $this->assertTrue((bool) $v2['is_historical']);
        $this->assertEquals(0.0, (float) $v2['credit_fiscal']);
        $this->assertEquals(5000.0, (float) $v2['non_taxed_amount']);

        $vNC = collect($vouchers)->firstWhere('invoice_number', 'HIST-NC-01');
        $this->assertTrue((bool) $vNC['is_historical']);
        $this->assertEquals(-105.0, (float) $vNC['credit_fiscal']);
        $this->assertEquals(-500.0, (float) $vNC['net_21']);
        $this->assertEquals(-605.0, (float) $vNC['total_amount']);
    }

    /**
     * EMPIRICAL CHECK 5: Excel and PDF export HTTP endpoints
     * Endpoints must return HTTP 200 with appropriate content types both with empty dataset and full dataset.
     */
    public function test_empirical_05_excel_and_pdf_export_endpoints_empty_and_populated(): void
    {
        // 5.1 Empty dataset (no records in period)
        $emptyExcel = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/export?start_date=2026-01-01&end_date=2026-01-31');
        $emptyExcel->assertOk();
        $this->assertStringContainsString('spreadsheet', $emptyExcel->headers->get('content-type', ''));

        $emptyPdf = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/pdf?start_date=2026-01-01&end_date=2026-01-31');
        $emptyPdf->assertOk();
        $this->assertStringContainsString('application/pdf', $emptyPdf->headers->get('content-type', ''));

        // 5.2 Populated dataset
        SupplierInvoice::create([
            'supplier_id' => $this->supplierA->id,
            'type' => 'invoice',
            'status' => 'paid',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 9901,
            'amount' => 14300.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'net_105' => 2000.00,
            'iva_105' => 210.00,
            'iva_perception' => 1000.00,
            'issue_date' => '2026-11-20',
            'affects_vat_book' => true,
        ]);

        SupplierInvoice::create([
            'supplier_id' => $this->supplierB->id,
            'type' => 'invoice',
            'status' => 'paid',
            'voucher_letter' => 'C',
            'point_of_sale' => 2,
            'voucher_number' => 450,
            'amount' => 7500.00,
            'non_taxed_amount' => 7500.00,
            'issue_date' => '2026-11-21',
            'affects_vat_book' => true,
        ]);

        $populatedExcel = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30');
        $populatedExcel->assertOk();
        $this->assertStringContainsString('spreadsheet', $populatedExcel->headers->get('content-type', ''));

        $populatedPdf = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30');
        $populatedPdf->assertOk();
        $this->assertStringContainsString('application/pdf', $populatedPdf->headers->get('content-type', ''));

        // 5.3 Filter by supplier_id in exports
        $filteredExcel = $this->actingAsAdmin($this->admin)
            ->get("/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30&supplier_id={$this->supplierA->id}");
        $filteredExcel->assertOk();

        // 5.4 Check permission middleware: non-admin without view_reports permission gets 403
        $forbiddenExcel = $this->actingAsUser($this->cashier)
            ->get('/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30');
        $this->assertTrue(in_array($forbiddenExcel->status(), [401, 403]));

        $forbiddenPdf = $this->actingAsUser($this->cashier)
            ->get('/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30');
        $this->assertTrue(in_array($forbiddenPdf->status(), [401, 403]));
    }

    /**
     * EMPIRICAL CHECK 6: Multi-aliquot, Perceptions, and Month/Year parameter support in vat-position
     */
    public function test_empirical_06_vat_position_month_year_and_all_aliquots(): void
    {
        // 1. Sale with multiple VAT rates
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'total' => 250000.00,
            'status' => 'completed',
        ]);

        ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 50,
            'cae' => '72345678901234',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-11223344-9',
            'receiver_name' => 'Cliente Mayorista',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 100000.00,
            'iva_amount' => 21000.00,
            'total_amount' => 121000.00,
            'issued_at' => '2026-11-10 14:00:00',
        ]);

        // 2. Purchase with 21%, 10.5%, 27% and perceptions
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 800,
            'amount' => 22020.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'net_105' => 4000.00,
            'iva_105' => 420.00,
            'net_27' => 3000.00,
            'iva_27' => 810.00,
            'non_taxed_amount' => 500.00,
            'exempt_amount' => 200.00,
            'iva_perception' => 600.00,
            'iibb_perception' => 300.00,
            'internal_taxes' => 90.00,
            'issue_date' => '2026-11-15',
        ])->assertSuccessful();

        // 3. Query using month and year parameters (as used by Flutter frontend)
        $res = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=11&year=2026');

        $res->assertOk()
            ->assertJsonPath('period.month', 11)
            ->assertJsonPath('period.year', 2026)
            ->assertJsonPath('sales.fiscal_debit', 21000)
            ->assertJsonPath('purchases.credit_21', 2100)
            ->assertJsonPath('purchases.credit_105', 420)
            ->assertJsonPath('purchases.credit_27', 810)
            ->assertJsonPath('purchases.fiscal_credit', 3330)
            ->assertJsonPath('purchases.iva_perceptions', 600)
            ->assertJsonPath('purchases.iibb_perceptions', 300);

        // Expected Net Balance = Débito ($21.000) - Crédito ($3.330) - Percepciones IVA ($600) = $17.070 a pagar
        $this->assertEquals(17070.0, (float) $res->json('balance.net_balance'));
        $this->assertEquals('to_pay', $res->json('balance.result'));
        $this->assertEquals(17070.0, (float) $res->json('balance.absolute_balance'));
    }

    /**
     * EMPIRICAL CHECK 7: Favorable technical balance and completely empty periods in vat-position
     */
    public function test_empirical_07_vat_position_favorable_balance_and_empty_period(): void
    {
        // 7.1 Completely empty period
        $resEmpty = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=1&year=2025');

        $resEmpty->assertOk()
            ->assertJsonPath('sales.fiscal_debit', 0)
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('balance.net_balance', 0)
            ->assertJsonPath('balance.result', 'zero')
            ->assertJsonPath('balance.result_label', 'Saldo Equilibrado');

        // 7.2 Favorable technical balance (Purchases credit > Sales debit)
        // Sale with $5.000 debit
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'total' => 28875.00,
            'status' => 'completed',
        ]);
        ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 99,
            'cae' => '12345678901234',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-11223344-9',
            'receiver_name' => 'Cliente Test',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 23875.00,
            'iva_amount' => 5000.00,
            'total_amount' => 28875.00,
            'issued_at' => '2026-10-05 10:00:00',
        ]);

        // Purchase with $10.000 credit fiscal + $2.000 IVA perception
        SupplierInvoice::create([
            'supplier_id' => $this->supplierA->id,
            'type' => 'invoice',
            'status' => 'paid',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 555,
            'amount' => 59619.00,
            'net_21' => 47619.00,
            'iva_21' => 10000.00,
            'iva_perception' => 2000.00,
            'issue_date' => '2026-10-10',
            'affects_vat_book' => true,
        ]);

        $resFav = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?month=10&year=2026');

        $resFav->assertOk()
            ->assertJsonPath('sales.fiscal_debit', 5000)
            ->assertJsonPath('purchases.fiscal_credit', 10000)
            ->assertJsonPath('purchases.iva_perceptions', 2000);

        // Expected balance = 5000 - 10000 - 2000 = -7000 (favorable)
        $this->assertEquals(-7000.0, (float) $resFav->json('balance.net_balance'));
        $this->assertEquals('favorable', $resFav->json('balance.result'));
        $this->assertEquals('Saldo a Favor Técnico del Contribuyente', $resFav->json('balance.result_label'));
        $this->assertEquals(7000.0, (float) $resFav->json('balance.absolute_balance'));
    }

    /**
     * EMPIRICAL CHECK 8: Fiscal validation rejections and automatic B/C coercion
     */
    public function test_empirical_08_fiscal_validation_rejections_and_b_c_coercion(): void
    {
        // 8.1 Reject Factura A with arithmetic mismatch > 0.05
        $badRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 123,
            'amount' => 15000.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00, // Total components = 12100 vs amount 15000 (diff = 2900)
            'issue_date' => '2026-11-05',
        ]);
        $badRes->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // 8.2 Coerce Factura B with non-zero VAT to zero VAT and non-taxed
        $resB = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'B',
            'point_of_sale' => 1,
            'voucher_number' => 456,
            'amount' => 12100.00,
            'iva_21' => 2100.00, // Client erroneously sent VAT
            'issue_date' => '2026-11-05',
        ]);
        $resB->assertSuccessful();

        $invB = SupplierInvoice::findOrFail($resB->json('id'));
        $this->assertEquals(0.0, (float) $invB->iva_21);

        $reportB = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30&voucher_type=B');
        $reportB->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_non_taxed', 12100);
    }

    /**
     * EMPIRICAL BUG REPRODUCTION:
     * When updating a fiscal invoice with amount = 12100 and VAT = 2100,
     * the controller takes tax_amount = 2100 from $lockedInvoice and adds it to $subtotal,
     * inflating the total amount to 14200 and corrupting the supplier's balance.
     */
    public function test_empirical_bug_fiscal_invoice_update_doubles_vat_amount(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplierA->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 999,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-05',
        ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        $this->assertEquals(12100.0, (float) $this->supplierA->fresh()->balance);

        // Update description only, preserving amount = 12100
        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'amount' => 12100.00,
            'description' => 'Ajuste de notas',
            'edit_reason' => 'Ajuste de notas de prueba',
        ]);
        $updateRes->assertSuccessful();

        $invFresh = SupplierInvoice::findOrFail($invoiceId);
        // Expectation: amount should remain 12100.00, but due to bug it becomes 14200.00
        $this->assertEquals(12100.0, (float) $invFresh->amount, "BUG: Supplier invoice amount was inflated from 12100 to {$invFresh->amount}!");
        $this->assertEquals(12100.0, (float) $this->supplierA->fresh()->balance, "BUG: Supplier balance was inflated from 12100 to {$this->supplierA->fresh()->balance}!");
    }
}
