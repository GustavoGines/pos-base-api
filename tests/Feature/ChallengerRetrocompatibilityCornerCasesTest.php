<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChallengerRetrocompatibilityCornerCasesTest extends TestCase
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
            ['value' => 'Challenger POS Test']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'tax_id'],
            ['value' => '30-99887766-5']
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Proveedor Challenger Test SA',
            'cuit' => '30-76543210-9',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);
    }

    /**
     * Target 1.A: Legacy payload minimal (v1.8.5) without any fiscal fields.
     */
    public function test_legacy_v185_payload_minimal_without_fiscal_fields_and_formats_cleanly(): void
    {
        $payload = [
            'type' => 'invoice',
            'amount' => 5000.00,
            'issue_date' => '2026-11-01',
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $invoiceId = $res->json('id');
        $this->assertNotNull($invoiceId);

        $invoice = SupplierInvoice::findOrFail($invoiceId);
        $this->assertNull($invoice->voucher_letter);
        $this->assertNull($invoice->point_of_sale);
        $this->assertNull($invoice->voucher_number);
        $this->assertTrue((bool) $invoice->affects_vat_book);

        // Verify index/show formatting
        $showRes = $this->actingAsAdmin($this->admin)
            ->getJson("/api/supplier-invoices/{$invoiceId}");
        $showRes->assertOk();
        $this->assertEquals('S/N', $showRes->json('formatted_number'));
        $this->assertNull($showRes->json('voucher_letter'));
        $this->assertEquals(5000.0, (float) $showRes->json('amount'));

        // Verify vat-purchases report handling
        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_non_taxed', 5000)
            ->assertJsonPath('summary.total_amount', 5000);

        $voucher = $reportRes->json('vouchers.0');
        $this->assertTrue((bool) $voucher['is_historical']);
        $this->assertEquals(0.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(5000.0, (float) $voucher['non_taxed_amount']);
    }

    /**
     * Target 1.B & 4.A: Legacy payload with tax_amount > 0 treated as historical record with 21% net/iva.
     */
    public function test_legacy_v185_payload_with_tax_amount_treated_as_historical_in_vat_reports(): void
    {
        $payload = [
            'type' => 'invoice',
            'invoice_number' => 'FAC-OLD-123',
            'amount' => 1000.00,
            'tax_amount' => 210.00,
            'description' => 'Factura antigua cargada en v1.8.5',
            'issue_date' => '2026-11-02',
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);
        $res->assertSuccessful();

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 210)
            ->assertJsonPath('summary.total_net_21', 1000)
            ->assertJsonPath('summary.total_amount', 1210);

        $voucher = $reportRes->json('vouchers.0');
        $this->assertTrue((bool) $voucher['is_historical']);
        $this->assertEquals('Factura A', $voucher['voucher_label']);
        $this->assertEquals('FAC-OLD-123', $voucher['formatted_number']);
        $this->assertEquals(1000.0, (float) $voucher['net_21']);
        $this->assertEquals(210.0, (float) $voucher['iva_21']);
        $this->assertEquals(210.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(1210.0, (float) $voucher['total_amount']);

        // Verify vat-position includes this historical fiscal credit
        $posRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');
        $posRes->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 210)
            ->assertJsonPath('purchases.credit_21', 210)
            ->assertJsonPath('purchases.total_purchases_amount', 1210);
    }

    /**
     * Target 1.C: Legacy update on legacy invoice does not fail validation.
     */
    public function test_legacy_update_on_legacy_invoice_preserves_state_without_validation_errors(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'amount' => 3000.00,
            'issue_date' => '2026-11-03',
        ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'description' => 'Updated legacy invoice description',
            'status' => 'paid',
        ]);
        $updateRes->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($invoiceId);
        $this->assertEquals('Updated legacy invoice description', $invoice->description);
        $this->assertEquals('paid', $invoice->status);
        $this->assertNull($invoice->voucher_letter);
    }

    /**
     * Target 2.A: affects_vat_book = false completely excludes invoice from vat-purchases and vat-position.
     */
    public function test_affects_vat_book_false_completely_excludes_invoice_from_purchases_and_position(): void
    {
        $res = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 777,
            'amount' => 61000.00,
            'net_21' => 50000.00,
            'iva_21' => 10500.00,
            'iva_perception' => 500.00,
            'affects_vat_book' => false,
            'issue_date' => '2026-11-04',
        ]);
        $res->assertSuccessful();

        // 1. Check vat-purchases
        $purchasesRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $purchasesRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_net', 0)
            ->assertJsonPath('summary.total_amount', 0);
        $this->assertEmpty($purchasesRes->json('vouchers'));

        // 2. Check vat-position
        $posRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');
        $posRes->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.iva_perceptions', 0)
            ->assertJsonPath('purchases.total_purchases_net', 0)
            ->assertJsonPath('purchases.total_purchases_amount', 0)
            ->assertJsonPath('purchases.invoices_count', 0)
            ->assertJsonPath('balance.fiscal_credit', 0)
            ->assertJsonPath('balance.iva_perceptions', 0);
    }

    /**
     * Target 2.B: Toggling affects_vat_book via update switches report inclusion.
     */
    public function test_switching_affects_vat_book_via_update_toggles_report_inclusion(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 888,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'affects_vat_book' => false,
            'issue_date' => '2026-11-05',
        ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        // Initially excluded
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 0);

        // Update to affects_vat_book = true
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'affects_vat_book' => true,
        ])->assertSuccessful();

        // Now included
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 2100);

        // Update back to affects_vat_book = false
        $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'affects_vat_book' => false,
        ])->assertSuccessful();

        // Now excluded again
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 0);
    }

    /**
     * Target 3: Soft delete and restore completely updates vat-purchases and vat-position.
     */
    public function test_soft_deleted_invoice_excluded_and_restored_reappears_in_purchases_and_position(): void
    {
        $res = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 901,
            'amount' => 25200.00,
            'net_21' => 20000.00,
            'iva_21' => 4200.00,
            'iva_perception' => 1000.00,
            'issue_date' => '2026-11-06',
        ]);
        $res->assertSuccessful();
        $invoiceId = $res->json('id');

        // Confirm initial presence in vat-purchases & vat-position
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 4200);

        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('purchases.fiscal_credit', 4200)
            ->assertJsonPath('purchases.iva_perceptions', 1000);

        // Soft delete
        $this->actingAsAdmin($this->admin)
            ->deleteJson("/api/supplier-invoices/{$invoiceId}")
            ->assertSuccessful();

        // Must be excluded from both
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0);

        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.iva_perceptions', 0);

        // Restore
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/supplier-invoices/{$invoiceId}/restore")
            ->assertSuccessful();

        // Must re-appear in both
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 4200);

        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('purchases.fiscal_credit', 4200)
            ->assertJsonPath('purchases.iva_perceptions', 1000);
    }

    /**
     * Target 4.B: Historical invoice with tax_amount = 0 allocates all amount to non_taxed.
     */
    public function test_historical_invoice_with_tax_amount_zero_allocates_to_non_taxed(): void
    {
        $invoice = SupplierInvoice::create([
            'supplier_id' => $this->supplier->id,
            'type' => 'invoice',
            'amount' => 3500.00,
            'tax_amount' => 0.00,
            'invoice_number' => 'HIST-ZERO-TAX',
            'issue_date' => '2026-11-07',
            'affects_vat_book' => true,
        ]);

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_non_taxed', 3500)
            ->assertJsonPath('summary.total_amount', 3500);

        $voucher = $reportRes->json('vouchers.0');
        $this->assertTrue((bool) $voucher['is_historical']);
        $this->assertEquals(0.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(3500.0, (float) $voucher['non_taxed_amount']);

        $posRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');
        $posRes->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.total_purchases_amount', 3500);
    }

    /**
     * Target 5.A: Excel and PDF exports return 200, valid headers, and non-empty binary.
     */
    public function test_excel_and_pdf_export_endpoints_status_and_content_headers(): void
    {
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 1234,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-08',
        ])->assertSuccessful();

        // 1. Excel export
        $excelRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30');
        $excelRes->assertOk();
        $contentType = $excelRes->headers->get('content-type', '');
        $this->assertTrue(
            str_contains($contentType, 'spreadsheet') || str_contains($contentType, 'openxmlformats'),
            "Content-Type '{$contentType}' does not indicate an Excel spreadsheet"
        );
        $contentDisposition = $excelRes->headers->get('content-disposition', '');
        $this->assertStringContainsString('libro_iva_compras_', $contentDisposition);
        $this->assertStringContainsString('.xlsx', $contentDisposition);
        $this->assertTrue(file_exists($excelRes->getFile()->getPathname()));
        $this->assertGreaterThan(500, filesize($excelRes->getFile()->getPathname()));

        // 2. PDF export
        $pdfRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30');
        $pdfRes->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfRes->headers->get('content-type', ''));
        $pdfDisposition = $pdfRes->headers->get('content-disposition', '');
        $this->assertStringContainsString('libro_iva_compras_', $pdfDisposition);
        $this->assertStringContainsString('.pdf', $pdfDisposition);
        $content = $pdfRes->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(500, strlen($content));
    }

    /**
     * Target 5.B: Exporting with zero records in period returns 200 gracefully without 500 crashes.
     */
    public function test_empty_date_range_excel_and_pdf_exports_return_200_without_errors(): void
    {
        // Zero records in 2020
        $excelRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/export?start_date=2020-01-01&end_date=2020-01-31');
        $excelRes->assertOk();
        $this->assertTrue(file_exists($excelRes->getFile()->getPathname()));
        $this->assertGreaterThan(500, filesize($excelRes->getFile()->getPathname()));

        $pdfRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/pdf?start_date=2020-01-01&end_date=2020-01-31');
        $pdfRes->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdfRes->getContent());
    }

    /**
     * Corner Case: Factura B / C with perceptions passes perceptions to vat-position and zeroes credit fiscal.
     */
    public function test_factura_b_and_c_with_perceptions_contribute_zero_credit_but_pass_perceptions(): void
    {
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'C',
            'point_of_sale' => 3,
            'voucher_number' => 55,
            'amount' => 11000.00,
            'iva_perception' => 300.00,
            'iibb_perception' => 200.00,
            'issue_date' => '2026-11-09',
        ];

        $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload)
            ->assertSuccessful();

        $purchasesRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $purchasesRes->assertOk()
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_iva_perception', 300)
            ->assertJsonPath('summary.total_iibb_perception', 200)
            ->assertJsonPath('summary.total_non_taxed', 10500)
            ->assertJsonPath('summary.total_amount', 11000);

        $posRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');
        $posRes->assertOk()
            ->assertJsonPath('purchases.fiscal_credit', 0)
            ->assertJsonPath('purchases.iva_perceptions', 300)
            ->assertJsonPath('purchases.iibb_perceptions', 200);
    }

    /**
     * Corner Case: Multi-aliquot Factura A with 21%, 10.5%, 27%, exempt, non-taxed and perceptions.
     */
    public function test_multi_aliquot_factura_a_with_all_components_computes_exact_breakdown(): void
    {
        // 21%: net 1000, iva 210
        // 10.5%: net 2000, iva 210
        // 27%: net 1000, iva 270
        // non_taxed: 500
        // exempt: 300
        // iva_perception: 100
        // iibb_perception: 50
        // internal_taxes: 40
        // total amount = 1000+210 + 2000+210 + 1000+270 + 500 + 300 + 100 + 50 + 40 = 5480
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 9999,
            'amount' => 5680.00,
            'net_21' => 1000.00,
            'iva_21' => 210.00,
            'net_105' => 2000.00,
            'iva_105' => 210.00,
            'net_27' => 1000.00,
            'iva_27' => 270.00,
            'non_taxed_amount' => 500.00,
            'exempt_amount' => 300.00,
            'iva_perception' => 100.00,
            'iibb_perception' => 50.00,
            'internal_taxes' => 40.00,
            'issue_date' => '2026-11-10',
        ];

        $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload)
            ->assertSuccessful();

        $purchasesRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');
        $purchasesRes->assertOk()
            ->assertJsonPath('summary.total_net', 4000)
            ->assertJsonPath('summary.total_credit_fiscal', 690)
            ->assertJsonPath('summary.total_net_21', 1000)
            ->assertJsonPath('summary.total_iva_21', 210)
            ->assertJsonPath('summary.total_net_105', 2000)
            ->assertJsonPath('summary.total_iva_105', 210)
            ->assertJsonPath('summary.total_net_27', 1000)
            ->assertJsonPath('summary.total_iva_27', 270)
            ->assertJsonPath('summary.total_non_taxed', 500)
            ->assertJsonPath('summary.total_exempt', 300)
            ->assertJsonPath('summary.total_iva_perception', 100)
            ->assertJsonPath('summary.total_iibb_perception', 50)
            ->assertJsonPath('summary.total_internal_taxes', 40)
            ->assertJsonPath('summary.total_amount', 5680);
    }
}
