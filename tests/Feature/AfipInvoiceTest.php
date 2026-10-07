<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfipInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected string $testCuit = '30712345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);

        // Configuración de negocio para AFIP
        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->testCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);

        // Precargar en caché el Ticket de Acceso (TA) para aislar llamadas a WSAA
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token'           => 'FAKE_TOKEN_WSAA_123456',
            'sign'            => 'FAKE_SIGN_WSAA_789012',
            'expiration_time' => date('c', time() + 36000),
        ], 36000);
    }

    /**
     * Helper para crear una venta con items de prueba.
     */
    protected function createTestSale(float $total = 1210.00, ?Customer $customer = null): Sale
    {
        $shift = $this->crearTurnoAbierto(user: $this->adminUser);

        $product = Product::create([
            'name'          => 'Taladro Percutor 750W',
            'internal_code' => 'TAL-01',
            'cost_price'    => 500.00,
            'selling_price' => $total,
            'iva_rate'      => 21.00,
            'stock'         => 10,
            'active'        => true,
        ]);

        $sale = Sale::create([
            'total'           => $total,
            'total_surcharge' => 0.00,
            'payment_status'  => 'paid',
            'amount_due'      => 0.00,
            'status'          => 'completed',
            'invoice_status'  => 'none',
            'cash_shift_id'   => $shift->id,
            'user_id'         => $this->adminUser->id,
            'cashier_id'      => $this->adminUser->id,
            'customer_id'     => $customer?->id,
        ]);

        $sale->items()->create([
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'quantity'        => 1,
            'unit_cost_price' => 500.00,
            'unit_price'      => $total,
            'subtotal'        => $total,
            'iva_rate'        => 21.00,
            'net_amount'      => 1000.00,
            'iva_amount'      => 210.00,
        ]);

        return $sale;
    }

    /**
     * 1. Factura B (Consumidor Final) autorizada exitosamente con CAE y QR RG 4892.
     */
    public function test_authorizing_factura_b_generates_cae_and_records_invoice(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>6</CbteTipo>
        <CbteNro>123</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
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
            <Concepto>1</Concepto>
            <DocTipo>99</DocTipo>
            <DocNro>0</DocNro>
            <CbteDesde>124</CbteDesde>
            <CbteHasta>124</CbteHasta>
            <CbteFch>20261007</CbteFch>
            <Resultado>A</Resultado>
            <CAE>74382910492819</CAE>
            <CAEFchVto>20261017</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        $sale = $this->createTestSale(1210.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
            'doc_type'     => 99,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.cae', '74382910492819')
            ->assertJsonPath('invoice.voucher_number', 124)
            ->assertJsonPath('invoice.formatted_number', '00001-00000124')
            ->assertJsonPath('invoice.voucher_letter', 'B')
            ->assertJsonPath('invoice.status', 'authorized');

        $qrData = $response->json('invoice.qr_data');
        $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $qrData);

        // Verificación en base de datos
        $this->assertDatabaseHas('electronic_invoices', [
            'sale_id'        => $sale->id,
            'voucher_type'   => 6,
            'voucher_number' => 124,
            'cae'            => '74382910492819',
            'status'         => 'authorized',
        ]);

        $this->assertEquals('invoiced', $sale->fresh()->invoice_status);
    }

    /**
     * 2. Factura A autorizada con CUIT de cliente Responsable Inscripto y desglose de IVA.
     */
    public function test_authorizing_factura_a_with_valid_cuit_and_iva_breakdown(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>1</CbteTipo>
        <CbteNro>50</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                // Confirmar que el nodo <Iva> fue enviado con la alícuota correcta
                $this->assertStringContainsString('<Iva>', $body);
                $this->assertStringContainsString('<Id>5</Id>', $body);
                $this->assertStringContainsString('<DocTipo>80</DocTipo>', $body);
                $this->assertStringContainsString('<DocNro>30500010912</DocNro>', $body);

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>A</Resultado>
            <CAE>88990011223344</CAE>
            <CAEFchVto>20261017</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        // Cliente Responsable Inscripto con CUIT de Banco Nación (30-50001091-2)
        $customer = Customer::create([
            'name'            => 'Banco de la Nación Argentina',
            'document_number' => '30500010912',
            'document_type'   => 80,
            'tax_condition'   => 'responsable_inscripto',
            'fiscal_address'  => 'Bartolomé Mitre 326, CABA',
        ]);

        $sale = $this->createTestSale(2420.00, $customer);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 80,
            'doc_number'   => '30500010912',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'A')
            ->assertJsonPath('invoice.voucher_type', 1)
            ->assertJsonPath('invoice.voucher_number', 51)
            ->assertJsonPath('invoice.cae', '88990011223344');

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(2420.00, (float) $invoice->total_amount);
        $this->assertEquals('A', $invoice->voucher_letter);
        $this->assertNotEmpty($invoice->iva_breakdown);
    }

    /**
     * 3. Factura C omite estrictamente el nodo <Iva> y tiene ImpIVA = 0.00 (Regla Monotributo AFIP).
     */
    public function test_authorizing_factura_c_strictly_omits_iva_node_and_sets_imp_iva_zero(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <CbteNro>10</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                // REGLA CRÍTICA AFIP MONOTRIBUTO: El nodo <Iva> NO debe existir y ImpIVA debe ser 0.00
                $this->assertStringNotContainsString('<Iva>', $body);
                $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $body);
                $this->assertStringContainsString('<ImpNeto>5000.00</ImpNeto>', $body);
                $this->assertStringContainsString('<ImpTotal>5000.00</ImpTotal>', $body);

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp><Resultado>A</Resultado></FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>A</Resultado>
            <CAE>11223344556677</CAE>
            <CAEFchVto>20261017</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        $sale = $this->createTestSale(5000.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 11, // Factura C
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'C')
            ->assertJsonPath('invoice.voucher_number', 11)
            ->assertJsonPath('invoice.iva_amount', '0.00')
            ->assertJsonPath('invoice.net_amount', '5000.00')
            ->assertJsonPath('invoice.total_amount', '5000.00');

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertEquals(0.00, (float) $invoice->iva_amount);
        $this->assertEquals(5000.00, (float) $invoice->net_amount);
    }

    /**
     * 4. Validación de entrada: Factura A con CUIT inválido falla con error 422.
     */
    public function test_authorizing_factura_a_with_invalid_cuit_fails_with_422(): void
    {
        $sale = $this->createTestSale(1210.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 80,
            'doc_number'   => '30123456789', // CUIT inválido según Módulo 11
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');

        $this->assertEquals('failed', $sale->fresh()->invoice_status);
    }

    /**
     * 5. Rechazo fiscal por parte de los servidores de AFIP marca la venta como failed.
     */
    public function test_afip_rejection_marks_sale_failed_and_returns_422(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult><CbteNro>10</CbteNro></FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp><Resultado>R</Resultado></FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>R</Resultado>
            <Observaciones>
              <Obs>
                <Code>10016</Code>
                <Msg>El total no coincide con la sumatoria de los subtotales e impuestos</Msg>
              </Obs>
            </Observaciones>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        $sale = $this->createTestSale(1210.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');

        $this->assertEquals('failed', $sale->fresh()->invoice_status);
    }

    /**
     * 6. Timeout o caída de servidores de AFIP activa contingencia: HTTP 504 y estado pending.
     */
    public function test_afip_timeout_triggers_contingency_fallback(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
        });

        $sale = $this->createTestSale(1210.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'pending')
            ->assertJsonPath('message', 'Servicio de AFIP no disponible. Venta registrada en contingencia.');

        $this->assertEquals('pending', $sale->fresh()->invoice_status);
    }

    /**
     * 7. Endpoint GET /api/sales/{sale}/electronic-invoice retorna factura o 404.
     */
    public function test_get_electronic_invoice_endpoint(): void
    {
        $sale = $this->createTestSale(1210.00);

        // Sin factura
        $response404 = $this->actingAsAdmin($this->adminUser)->getJson("/api/sales/{$sale->id}/electronic-invoice");
        $response404->assertStatus(404);

        // Con factura autorizada
        ElectronicInvoice::create([
            'sale_id'         => $sale->id,
            'voucher_type'    => 6,
            'voucher_letter'  => 'B',
            'point_of_sale'   => 1,
            'voucher_number'  => 124,
            'cae'             => '74382910492819',
            'cae_expiration'  => '2026-10-17',
            'doc_type'        => 99,
            'doc_number'      => '0',
            'net_amount'      => 1000.00,
            'iva_amount'      => 210.00,
            'total_amount'    => 1210.00,
            'qr_data'         => 'https://www.afip.gob.ar/fe/qr/?p=TEST',
            'status'          => 'authorized',
            'issued_at'       => now(),
        ]);

        $response200 = $this->actingAsAdmin($this->adminUser)->getJson("/api/sales/{$sale->id}/electronic-invoice");
        $response200->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.cae', '74382910492819')
            ->assertJsonPath('invoice.formatted_number', '00001-00000124');
    }
}
