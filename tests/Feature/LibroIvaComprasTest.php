<?php

namespace Tests\Feature;

use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LibroIvaComprasTest extends TestCase
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
            ['value' => 'Mi Negocio Test']
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'tax_id'],
            ['value' => '20-12345678-9']
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Distribuidora Central SA',
            'cuit' => '30-71234567-9',
            'tax_category' => 'Responsable Inscripto',
            'balance' => 0,
        ]);
    }

    /**
     * Test 01: Creación de Factura A con múltiples alícuotas y percepciones.
     */
    public function test_01_store_factura_a_multi_aliquot_and_perceptions(): void
    {
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 4567,
            'amount' => 17875.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'net_105' => 5000.00,
            'iva_105' => 525.00,
            'iva_perception' => 150.00,
            'iibb_perception' => 100.00,
            'issue_date' => '2026-11-05',
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();
        $invoiceId = $res->json('id');
        $this->assertNotNull($invoiceId);

        // Consultar el reporte Libro IVA Compras
        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.total_credit_fiscal', 2625)
            ->assertJsonPath('summary.total_net', 15000)
            ->assertJsonPath('summary.total_net_21', 10000)
            ->assertJsonPath('summary.total_iva_21', 2100)
            ->assertJsonPath('summary.total_net_105', 5000)
            ->assertJsonPath('summary.total_iva_105', 525)
            ->assertJsonPath('summary.total_iva_perception', 150)
            ->assertJsonPath('summary.total_iibb_perception', 100)
            ->assertJsonPath('summary.total_amount', 17875)
            ->assertJsonPath('summary.vouchers_count', 1);

        $voucher = $reportRes->json('vouchers.0');
        $this->assertEquals('Factura A', $voucher['voucher_label']);
        $this->assertEquals('A 00001-00004567', $voucher['formatted_number']);
        $this->assertEquals(2625.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(17875.0, (float) $voucher['total_amount']);
        $this->assertFalse((bool) $voucher['is_historical']);
    }

    /**
     * Test 02: Creación de Factura C fuerza crédito fiscal cero y no gravado.
     */
    public function test_02_store_factura_c_forces_zero_fiscal_credit(): void
    {
        $payload = [
            'type' => 'invoice',
            'voucher_letter' => 'C',
            'point_of_sale' => 2,
            'voucher_number' => 89,
            'amount' => 15000.00,
            'iva_21' => 500.00, // debe ser forzado a 0
            'issue_date' => '2026-11-06',
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $payload);

        $res->assertSuccessful();

        $invoice = SupplierInvoice::findOrFail($res->json('id'));
        $this->assertEquals(0.0, (float) $invoice->iva_21);

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_amount', 15000)
            ->assertJsonPath('summary.total_non_taxed', 15000);

        $voucher = $reportRes->json('vouchers.0');
        $this->assertEquals('Factura C', $voucher['voucher_label']);
        $this->assertEquals(0.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(15000.0, (float) $voucher['total_amount']);
    }

    /**
     * Test 03: Nota de crédito descuenta bases, crédito fiscal y expone valores negativos.
     */
    public function test_03_credit_note_deducts_from_vat_purchases_and_fiscal_credit(): void
    {
        // 1. Factura A inicial
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 100,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-02',
        ])->assertSuccessful();

        // 2. Nota de Crédito A posterior
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'credit_note',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 5,
            'amount' => 4840.00,
            'net_21' => 4000.00,
            'iva_21' => 840.00,
            'issue_date' => '2026-11-10',
        ])->assertSuccessful();

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.total_net', 6000)
            ->assertJsonPath('summary.total_credit_fiscal', 1260)
            ->assertJsonPath('summary.total_amount', 7260)
            ->assertJsonPath('summary.invoices_count', 1)
            ->assertJsonPath('summary.credit_notes_count', 1)
            ->assertJsonPath('summary.vouchers_count', 2);

        $vouchers = $reportRes->json('vouchers');
        $ncVoucher = collect($vouchers)->firstWhere('type', 'credit_note');
        $this->assertNotNull($ncVoucher);
        $this->assertEquals('Nota de Crédito A', $ncVoucher['voucher_label']);
        $this->assertEquals(-4000.0, (float) $ncVoucher['net_21']);
        $this->assertEquals(-840.0, (float) $ncVoucher['iva_21']);
        $this->assertEquals(-840.0, (float) $ncVoucher['credit_fiscal']);
        $this->assertEquals(-4840.0, (float) $ncVoucher['total_amount']);
    }

    /**
     * Test 04: affects_vat_book = false excluye el comprobante del Libro IVA Compras.
     */
    public function test_04_affects_vat_book_false_excludes_invoice_from_report(): void
    {
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 999,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'affects_vat_book' => false,
            'issue_date' => '2026-11-05',
        ])->assertSuccessful();

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('summary.total_amount', 0);
    }

    /**
     * Test 05: Tolerancia de centavos admite <= 0.05 y rechaza > 0.05 con 422.
     */
    public function test_05_rounding_tolerance_allows_small_cents_diff_and_rejects_large(): void
    {
        // 1. Diferencia tolerable (3 centavos)
        $tolerableRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 101,
            'amount' => 12100.03, // 10000 + 2100 = 12100.00 vs 12100.03 (dif = 0.03)
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-05',
        ]);
        $tolerableRes->assertSuccessful();

        // 2. Diferencia intempestiva (5 pesos)
        $invalidRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 102,
            'amount' => 12105.00, // 10000 + 2100 = 12100 vs 12105 (dif = 5.00)
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-05',
        ]);
        $invalidRes->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test 06: Retrocompatibilidad con v1.8.5 aceptando payloads planos sin campos fiscales.
     */
    public function test_06_backward_compatibility_v185_legacy_payload_without_fiscal_fields(): void
    {
        $legacyPayload = [
            'type' => 'invoice',
            'invoice_number' => 'FAC-LEGACY-001',
            'amount' => 1000.00,
            'tax_amount' => 210.00,
            'description' => 'Factura antigua cargada en v1.8.5',
            'issue_date' => '2026-11-07',
        ];

        $res = $this->actingAsAdmin($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/invoices", $legacyPayload);

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
        $this->assertEquals(1000.0, (float) $voucher['net_21']);
        $this->assertEquals(210.0, (float) $voucher['iva_21']);
        $this->assertEquals(210.0, (float) $voucher['credit_fiscal']);
        $this->assertEquals(1210.0, (float) $voucher['total_amount']);
    }

    /**
     * Test 07: Posición mensual de IVA cruza débito vs crédito y calcula saldo exacto con disclaimer.
     */
    public function test_07_vat_position_computes_exact_balance_and_disclaimer(): void
    {
        // 1. Crear venta con Débito Fiscal de $21.000
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
            'voucher_number' => 10,
            'cae' => '12345678901234',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'status' => 'authorized',
            'doc_type' => 80,
            'doc_number' => '30-71234567-9',
            'receiver_name' => 'Cliente RI Test',
            'receiver_tax_condition' => 'Responsable Inscripto',
            'net_amount' => 100000.00,
            'iva_amount' => 21000.00,
            'total_amount' => 121000.00,
            'issued_at' => '2026-11-05 10:00:00',
        ]);

        // 2. Crear compra con Crédito Fiscal de $12.600 y percepción IVA de $1.000
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 200,
            'amount' => 73600.00,
            'net_21' => 60000.00,
            'iva_21' => 12600.00,
            'iva_perception' => 1000.00,
            'issue_date' => '2026-11-06',
        ])->assertSuccessful();

        // 3. Consultar la posición mensual de IVA:
        // Saldo esperado = Débito ($21.000) - Crédito ($12.600) - Percepción ($1.000) = $7.400 a pagar
        $res = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-position?start_date=2026-11-01&end_date=2026-11-30');

        $res->assertOk()
            ->assertJsonPath('sales.fiscal_debit', 21000)
            ->assertJsonPath('purchases.fiscal_credit', 12600)
            ->assertJsonPath('purchases.iva_perceptions', 1000)
            ->assertJsonPath('balance.fiscal_debit', 21000)
            ->assertJsonPath('balance.fiscal_credit', 12600)
            ->assertJsonPath('balance.iva_perceptions', 1000)
            ->assertJsonPath('balance.net_balance', 7400)
            ->assertJsonPath('balance.result', 'to_pay')
            ->assertJsonPath('balance.result_label', 'Saldo Estimado a Pagar a AFIP/ARCA');

        $disclaimer = $res->json('disclaimer');
        $this->assertStringContainsString('Estimación de gestión interna', $disclaimer);
        $this->assertStringContainsString('F. 2002', $disclaimer);
    }

    /**
     * Test 08: Comprobante en papelera se excluye del subdiario y se incluye al restaurar.
     */
    public function test_08_soft_deleted_supplier_invoice_excluded_and_restored_included(): void
    {
        $res = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 300,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-05',
        ]);
        $res->assertSuccessful();
        $invoiceId = $res->json('id');

        // Confirmar presencia inicial
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 1);

        // Soft-delete
        $this->actingAsAdmin($this->admin)
            ->deleteJson("/api/supplier-invoices/{$invoiceId}")
            ->assertSuccessful();

        // Debe excluirse del subdiario
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 0);

        // Restaurar
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/supplier-invoices/{$invoiceId}/restore")
            ->assertSuccessful();

        // Debe reaparecer en el subdiario
        $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30')
            ->assertJsonPath('summary.vouchers_count', 1);
    }

    /**
     * Test 09: Exportaciones a Excel y PDF devuelven 200 con sus respectivos headers.
     */
    public function test_09_vat_purchases_excel_and_pdf_exports_return_200(): void
    {
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 400,
            'amount' => 12100.00,
            'net_21' => 10000.00,
            'iva_21' => 2100.00,
            'issue_date' => '2026-11-05',
        ])->assertSuccessful();

        // 1. Descarga Excel
        $excelRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30');
        $excelRes->assertOk();
        $this->assertStringContainsString('spreadsheet', $excelRes->headers->get('content-type', ''));

        // 2. Descarga PDF
        $pdfRes = $this->actingAsAdmin($this->admin)
            ->get('/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30');
        $pdfRes->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfRes->headers->get('content-type', ''));
    }

    /**
     * Test 10: Remitos (delivery_note) no requieren desglose fiscal y nunca entran al Libro IVA Compras.
     */
    public function test_10_delivery_note_remito_is_excluded_from_vat_book(): void
    {
        $res = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'delivery_note',
            'voucher_letter' => 'A',
            'amount' => 50000.00,
            'invoice_number' => 'REM-0001-00000123',
            'issue_date' => '2026-11-08',
        ]);

        $res->assertSuccessful();
        $remitoId = $res->json('id');

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $remitoId,
            'type' => 'delivery_note',
            'affects_vat_book' => false,
        ]);

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 0)
            ->assertJsonPath('summary.total_amount', 0);
    }

    /**
     * Test 11: Comprobante histórico sin desglose y con tax_amount = 0 se etiqueta como S/D (no como Factura A).
     */
    public function test_11_historical_invoice_with_zero_tax_is_labeled_sd_not_factura_a(): void
    {
        $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'amount' => 410000.00,
            'tax_amount' => 0,
            'issue_date' => '2026-11-05',
        ])->assertSuccessful();

        $reportRes = $this->actingAsAdmin($this->admin)
            ->getJson('/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30');

        $reportRes->assertOk()
            ->assertJsonPath('summary.vouchers_count', 1)
            ->assertJsonPath('summary.total_non_taxed', 410000)
            ->assertJsonPath('summary.total_credit_fiscal', 0)
            ->assertJsonPath('vouchers.0.is_historical', true)
            ->assertJsonPath('vouchers.0.voucher_letter', 'S/D')
            ->assertJsonPath('vouchers.0.voucher_label', 'Comprobante S/D');
    }

    /**
     * Test 12: Edición legacy v1.8.5 sin ítems conserva tax_amount y freight_amount en el cálculo del total.
     */
    public function test_12_legacy_update_without_items_preserves_tax_and_freight_in_total(): void
    {
        $createRes = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'amount' => 1000.00,
            'tax_amount' => 210.00,
            'freight_amount' => 50.00,
            'issue_date' => '2026-11-05',
        ]);
        $createRes->assertSuccessful();
        $invId = $createRes->json('id');

        $this->assertEquals(1260.00, (float) $this->supplier->fresh()->balance);

        $updateRes = $this->actingAsAdmin($this->admin)->putJson("/api/supplier-invoices/{$invId}", [
            'type' => 'invoice',
            'amount' => 2000.00,
            'tax_amount' => 420.00,
            'freight_amount' => 80.00,
            'edit_reason' => 'Corrección de monto legacy',
        ]);
        $updateRes->assertSuccessful();

        $this->assertEquals(2500.00, (float) $this->supplier->fresh()->balance);
    }

    /**
     * Test 13: Comprobante con affects_vat_book = false no bloquea por desglose de Factura A/M.
     */
    public function test_13_factura_a_with_affects_vat_book_false_does_not_require_breakdown(): void
    {
        $res = $this->actingAsAdmin($this->admin)->postJson("/api/suppliers/{$this->supplier->id}/invoices", [
            'type' => 'invoice',
            'voucher_letter' => 'A',
            'affects_vat_book' => false,
            'amount' => 15000.00,
            'issue_date' => '2026-11-09',
        ]);

        $res->assertSuccessful();
    }
}
