<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfessionalServicesAndCustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected CashShift $shift;

    protected PaymentMethod $transferMethod;

    protected string $testCuit = '30712345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);
        $this->shift = $this->crearTurnoAbierto(user: $this->adminUser);

        $this->transferMethod = PaymentMethod::create([
            'name' => 'Transferencia',
            'code' => 'transfer',
            'surcharge_type' => 'none',
            'surcharge_value' => 0,
            'is_cash' => false,
            'is_active' => true,
        ]);

        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->testCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);

        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'FAKE_TOKEN_WSAA_123456',
            'sign' => 'FAKE_SIGN_WSAA_789012',
            'expiration_time' => date('c', time() + 36000),
        ], 36000);
    }

    public function test_service_product_does_not_deduct_stock_nor_trigger_alerts(): void
    {
        $service = Product::create([
            'name' => 'Honorarios Mensuales Monotributo',
            'internal_code' => 'SERV-001',
            'cost_price' => 0,
            'selling_price' => 25000,
            'iva_rate' => 21.00,
            'stock' => 0,
            'min_stock' => 5,
            'is_service' => true,
            'active' => true,
        ]);

        // Procesar venta de 2 unidades del servicio + 1 servicio rápido al vuelo con save_to_catalog
        $saleResp = $this->actingAsAdmin($this->adminUser)->postJson('/api/pos/sales', [
            'total' => 65000,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $service->id,
                    'quantity' => 2,
                    'unit_price' => 25000,
                    'subtotal' => 50000,
                ],
                [
                    'product_id' => null,
                    'is_temporary' => true,
                    'save_to_catalog' => true,
                    'is_service' => true,
                    'name' => 'Certificación de Ingresos',
                    'quantity' => 1,
                    'unit_price' => 15000,
                    'cost_price' => 0,
                    'iva_rate' => 21,
                    'subtotal' => 15000,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'base_amount' => 65000,
                    'surcharge_amount' => 0,
                    'total_amount' => 65000,
                ],
            ],
        ]);

        $saleResp->assertCreated();
        $saleId = $saleResp->json('sale.id');

        $service->refresh();
        $this->assertSame(0.0, (float) $service->stock, 'El servicio no debe descontar stock físico ni quedar en negativo.');
        $this->assertGreaterThan(0, (int) $service->sales_count, 'El contador de popularidad sí debe incrementarse.');
        $this->assertSame(0, StockMovement::where('sale_id', $saleId)->count(), 'No debe generar movimientos de stock físico.');

        // Verificar que el servicio creado al vuelo con save_to_catalog quedó con is_service = true
        $onTheFlyService = Product::where('name', 'Certificación de Ingresos')->first();
        $this->assertNotNull($onTheFlyService);
        $this->assertTrue((bool) $onTheFlyService->is_service);
        $this->assertSame(0.0, (float) $onTheFlyService->stock);

        // Verificar que ninguno aparezca en alertas de reposición ni en critical-alerts
        $alertService = app(InventoryAlertService::class);
        $predictiveAlerts = $alertService->getPredictiveAlerts();
        $alertIds = collect($predictiveAlerts)->pluck('product_id')->all();
        $this->assertNotContains($service->id, $alertIds, 'Los servicios no deben aparecer en alertas predictivas.');
        $this->assertNotContains($onTheFlyService->id, $alertIds);

        $criticalResp = $this->actingAsAdmin($this->adminUser)->getJson('/api/catalog/products/alerts/critical');
        $criticalResp->assertOk();
        $criticalIds = collect($criticalResp->json('critical'))->pluck('id')->all();
        $this->assertNotContains($service->id, $criticalIds);
        $this->assertNotContains($onTheFlyService->id, $criticalIds);
    }

    public function test_afip_wsfe_detects_concepto_2_for_services_and_concepto_3_for_mixed_sales(): void
    {
        $capturedBodies = [];
        $lastCbteNro = 200;

        Http::fake(function (HttpClientRequest $request) use (&$capturedBodies, &$lastCbteNro) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>6</CbteTipo>
        <CbteNro>'.$lastCbteNro.'</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                $capturedBodies[] = $body;
                $lastCbteNro++;

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp>
          <Cuit>30712345678</Cuit>
          <PtoVta>1</PtoVta>
          <CbteTipo>6</CbteTipo>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Concepto>2</Concepto>
            <DocTipo>99</DocTipo>
            <DocNro>0</DocNro>
            <CbteDesde>'.$lastCbteNro.'</CbteDesde>
            <CbteHasta>'.$lastCbteNro.'</CbteHasta>
            <CbteFch>20261110</CbteFch>
            <Resultado>A</Resultado>
            <CAE>74382910492899</CAE>
            <CAEFchVto>20261120</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        $serviceProd = Product::create([
            'name' => 'Liquidación Sueldos F.931',
            'internal_code' => 'SERV-931',
            'cost_price' => 0,
            'selling_price' => 12100.00,
            'iva_rate' => 21.00,
            'stock' => 0,
            'is_service' => true,
            'active' => true,
        ]);

        $physicalProd = Product::create([
            'name' => 'Carpeta Archivo Oficio',
            'internal_code' => 'PROD-CARP',
            'cost_price' => 1000,
            'selling_price' => 2420.00,
            'iva_rate' => 21.00,
            'stock' => 50,
            'is_service' => false,
            'active' => true,
        ]);

        // Caso A: Venta 100% Servicios -> Concepto 2
        $serviceSale = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
        ]);
        $serviceSale->items()->create([
            'product_id' => $serviceProd->id,
            'product_name' => $serviceProd->name,
            'quantity' => 1,
            'unit_cost_price' => 0,
            'unit_price' => 12100.00,
            'subtotal' => 12100.00,
            'iva_rate' => 21.00,
            'net_amount' => 10000.00,
            'iva_amount' => 2100.00,
        ]);

        $resp1 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$serviceSale->id}/invoice", [
            'voucher_type' => 6,
            'doc_type' => 99,
        ]);
        $resp1->assertOk();
        $this->assertNotEmpty($capturedBodies);
        $this->assertStringContainsString('<Concepto>2</Concepto>', $capturedBodies[0]);
        $this->assertStringContainsString('<FchServDesde>', $capturedBodies[0]);
        $this->assertStringContainsString('<FchServHasta>', $capturedBodies[0]);
        $this->assertStringContainsString('<FchVtoPago>', $capturedBodies[0]);

        // Caso B: Venta Mixta (Producto + Servicio) -> Concepto 3
        $mixedSale = Sale::create([
            'total' => 14520.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
        ]);
        $mixedSale->items()->create([
            'product_id' => $serviceProd->id,
            'product_name' => $serviceProd->name,
            'quantity' => 1,
            'unit_cost_price' => 0,
            'unit_price' => 12100.00,
            'subtotal' => 12100.00,
            'iva_rate' => 21.00,
            'net_amount' => 10000.00,
            'iva_amount' => 2100.00,
        ]);
        $mixedSale->items()->create([
            'product_id' => $physicalProd->id,
            'product_name' => $physicalProd->name,
            'quantity' => 1,
            'unit_cost_price' => 1000,
            'unit_price' => 2420.00,
            'subtotal' => 2420.00,
            'iva_rate' => 21.00,
            'net_amount' => 2000.00,
            'iva_amount' => 420.00,
        ]);

        $resp2 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$mixedSale->id}/invoice", [
            'voucher_type' => 6,
            'doc_type' => 99,
        ]);
        $resp2->assertOk();
        $this->assertCount(2, $capturedBodies);
        $this->assertStringContainsString('<Concepto>3</Concepto>', $capturedBodies[1]);
        $this->assertStringContainsString('<FchServDesde>', $capturedBodies[1]);
    }

    public function test_customer_show_returns_direct_sales_without_altering_balance_or_duplicating_charges(): void
    {
        $customer = Customer::create([
            'name' => 'Estudio Contable Pérez S.R.L.',
            'document_number' => '30711122233',
            'tax_condition' => 'responsable_inscripto',
            'balance' => 40000.00,
            'is_active' => true,
        ]);

        // 1. Venta cobrada en el acto (Efectivo / Transferencia) -> debe aparecer en direct_sales
        $directSale = Sale::create([
            'total' => 25000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
        ]);
        $directSale->payments()->create([
            'payment_method_id' => $this->transferMethod->id,
            'base_amount' => 25000.00,
            'surcharge_amount' => 0,
            'total_amount' => 25000.00,
        ]);

        // 2. Venta en Cuenta Corriente (que luego incluso podría pasar a 'paid') -> NO debe duplicarse en direct_sales
        $ccSale = Sale::create([
            'total' => 40000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
        ]);
        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'sale_id' => $ccSale->id,
            'user_id' => $this->adminUser->id,
            'type' => 'charge',
            'amount' => 40000.00,
            'balance_after' => 40000.00,
            'description' => "Cargo por Venta #{$ccSale->id}",
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->getJson("/api/customers/{$customer->id}");
        $response->assertOk();

        $this->assertEquals(40000.00, (float) $response->json('balance'));
        $this->assertCount(1, $response->json('transactions'));
        $this->assertCount(1, $response->json('direct_sales'));
        $this->assertSame('direct_sale', $response->json('direct_sales.0.type'));
        $this->assertSame($directSale->id, $response->json('direct_sales.0.sale_id'));
        $this->assertEquals(25000.00, (float) $response->json('direct_sales.0.amount'));
        $this->assertStringContainsString('Transferencia', (string) $response->json('direct_sales.0.description'));
    }

    public function test_vat_sales_report_breaks_down_iva_21_and_105_and_exports_excel_and_pdf(): void
    {
        $saleA = Sale::create([
            'total' => 23150.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'invoiced',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
        ]);

        \App\Models\ElectronicInvoice::create([
            'sale_id' => $saleA->id,
            'voucher_type' => 1,
            'voucher_letter' => 'A',
            'point_of_sale' => 1,
            'voucher_number' => 24,
            'cae' => '86451065111090',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'doc_type' => 80,
            'doc_number' => '27388231497',
            'receiver_name' => 'Supermercado Los Primos SRL',
            'receiver_tax_condition' => 'IVA Responsable Inscripto',
            'net_amount' => 20000.00,
            'iva_amount' => 3150.00,
            'tribute_amount' => 0.00,
            'exempt_amount' => 0.00,
            'untaxed_amount' => 0.00,
            'total_amount' => 23150.00,
            'iva_breakdown' => [
                ['Id' => 5, 'BaseImp' => 10000.00, 'Importe' => 2100.00], // 21%
                ['Id' => 4, 'BaseImp' => 10000.00, 'Importe' => 1050.00], // 10.5%
            ],
            'status' => 'authorized',
            'issued_at' => now(),
        ]);

        $today = now()->toDateString();
        $resp = $this->actingAsAdmin($this->adminUser)
            ->getJson("/api/reports/vat-sales?start_date={$today}&end_date={$today}");

        $resp->assertOk()
            ->assertJsonPath('summary.total_net', 20000)
            ->assertJsonPath('summary.total_iva', 3150)
            ->assertJsonPath('summary.net_21', 10000)
            ->assertJsonPath('summary.iva_21', 2100)
            ->assertJsonPath('summary.net_105', 10000)
            ->assertJsonPath('summary.iva_105', 1050)
            ->assertJsonPath('summary.net_fiscal_total', 23150);

        $excelResp = $this->actingAsAdmin($this->adminUser)
            ->get("/api/reports/vat-sales/export?start_date={$today}&end_date={$today}");
        $excelResp->assertOk();

        $pdfResp = $this->actingAsAdmin($this->adminUser)
            ->get("/api/reports/vat-sales/pdf?start_date={$today}&end_date={$today}");
        $pdfResp->assertOk();
    }
}
