<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\ElectronicInvoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Afip\AfipHelper;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IibbPerceptionBackendTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $testCuit = '30712345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
            'permissions' => ['manage_settings', 'create_sales', 'create_customers', 'manage_customers'],
        ]);

        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->testCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);
        BusinessSetting::create(['key' => 'is_iibb_perception_agent', 'value' => '0']);
        BusinessSetting::create(['key' => 'default_iibb_perception_rate', 'value' => '0.00']);

        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'FAKE_TOKEN_WSAA_123456',
            'sign' => 'FAKE_SIGN_WSAA_789012',
            'expiration_time' => date('c', time() + 36000),
        ], 36000);
    }

    protected function createOpenShift(?User $user = null): CashShift
    {
        $user = $user ?? $this->adminUser;
        $register = CashRegister::create([
            'name' => 'Caja Principal',
            'status' => 'active',
        ]);

        return CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'initial_cash' => 1000.00,
            'status' => 'open',
        ]);
    }

    protected function mockAfipSuccess(int $cbteNro = 100, string $cae = '74382910492819', ?callable $requestInspector = null): void
    {
        Http::fake(function (HttpClientRequest $request) use ($cbteNro, $cae, $requestInspector) {
            $body = $request->body();

            if ($requestInspector !== null) {
                $requestInspector($request);
            }

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>6</CbteTipo>
        <CbteNro>'.($cbteNro - 1).'</CbteNro>
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
          <Cuit>'.$this->testCuit.'</Cuit>
          <PtoVta>1</PtoVta>
          <CbteTipo>6</CbteTipo>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Concepto>1</Concepto>
            <DocTipo>80</DocTipo>
            <DocNro>20301234567</DocNro>
            <CbteDesde>'.$cbteNro.'</CbteDesde>
            <CbteHasta>'.$cbteNro.'</CbteHasta>
            <CbteFch>'.date('Ymd').'</CbteFch>
            <Resultado>A</Resultado>
            <CAE>'.$cae.'</CAE>
            <CAEFchVto>'.date('Ymd', strtotime('+10 days')).'</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });
    }

    /**
     * 1. Customer Model: Fillable & Casts
     */
    public function test_customer_model_persists_and_casts_iibb_perception_fields(): void
    {
        $customer = Customer::create([
            'name' => 'Comercial Formosa SRL',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30711122234',
            'tax_condition' => 'responsable_inscripto',
            'fiscal_address' => 'Av. 25 de Mayo 1234, Formosa',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.50,
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'applies_iibb_perception' => 1,
            'iibb_perception_rate' => 3.50,
        ]);

        $fresh = $customer->fresh();
        $this->assertTrue($fresh->applies_iibb_perception);
        $this->assertSame('3.50', (string) $fresh->iibb_perception_rate);
    }

    /**
     * 2. Customer Controller: Validation & CRUD
     */
    public function test_customer_controller_stores_and_updates_iibb_perception(): void
    {
        $storePayload = [
            'name' => 'Mayorista El Norte',
            'document_number' => '30799887766',
            'document_type' => 80,
            'tax_condition' => 'responsable_inscripto',
            'fiscal_address' => 'Ruta 11 Km 50',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 4.25,
        ];

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/customers', $storePayload);

        $response->assertStatus(201)
            ->assertJsonPath('applies_iibb_perception', true)
            ->assertJsonPath('iibb_perception_rate', '4.25');

        $customerId = $response->json('id');

        // Update perception fields
        $updatePayload = [
            'applies_iibb_perception' => false,
            'iibb_perception_rate' => 2.00,
        ];

        $updateResponse = $this->actingAsAdmin($this->adminUser)->putJson("/api/customers/{$customerId}", $updatePayload);
        $updateResponse->assertStatus(200)
            ->assertJsonPath('applies_iibb_perception', false)
            ->assertJsonPath('iibb_perception_rate', '2.00');
    }

    /**
     * 2b. Customer Controller: Validation rejects invalid rates
     */
    public function test_customer_controller_rejects_invalid_iibb_perception_rate(): void
    {
        $invalidPayload = [
            'name' => 'Cliente Invalido',
            'document_number' => '20112233445',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 120.00, // Exceeds 100%
        ];

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/customers', $invalidPayload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iibb_perception_rate']);

        $negativePayload = [
            'name' => 'Cliente Invalido Negativo',
            'document_number' => '20112233446',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => -5.00,
        ];

        $responseNeg = $this->actingAsAdmin($this->adminUser)->postJson('/api/customers', $negativePayload);
        $responseNeg->assertStatus(422)
            ->assertJsonValidationErrors(['iibb_perception_rate']);
    }

    /**
     * 3. BusinessSetting: Public keys and Settings API
     */
    public function test_business_settings_api_exposes_and_updates_iibb_keys(): void
    {
        $response = $this->actingAsAdmin($this->adminUser)->getJson('/api/settings');
        $response->assertStatus(200)
            ->assertJsonStructure(['is_iibb_perception_agent', 'default_iibb_perception_rate']);

        $putResponse = $this->actingAsAdmin($this->adminUser)->putJson('/api/settings', [
            'is_iibb_perception_agent' => '1',
            'default_iibb_perception_rate' => '3.00',
        ]);

        $putResponse->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'key' => 'is_iibb_perception_agent',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('business_settings', [
            'key' => 'default_iibb_perception_rate',
            'value' => '3.00',
        ]);
    }

    /**
     * 4. POS Sale Checkout persists perception amount and rate
     */
    public function test_pos_process_sale_stores_iibb_perception_fields(): void
    {
        $shift = $this->createOpenShift();
        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'is_cash' => true,
            'active' => true,
        ]);

        $product = Product::create([
            'name' => 'Bolsa Cemento 50kg',
            'internal_code' => 'CEM-50',
            'cost_price' => 5000.00,
            'selling_price' => 12100.00,
            'iva_rate' => 21.00,
            'stock' => 50,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Constructora Formosa',
            'document_type' => 80,
            'document_number' => '30665544332',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);

        // Subtotal: 12100 (Net 10000 + IVA 2100). Perception 3% on 10000 = 300. Total = 12400.
        $totalWithPerception = 12400.00;

        $salePayload = [
            'total' => $totalWithPerception,
            'total_surcharge' => 0.00,
            'shipping_cost' => 0.00,
            'cash_shift_id' => $shift->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 300.00,
            'iibb_perception_rate' => 3.00,
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => $totalWithPerception,
                    'surcharge_amount' => 0.00,
                    'total_amount' => $totalWithPerception,
                ],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 12100.00,
                    'subtotal' => 12100.00,
                ],
            ],
        ];

        $response = $this->actingAsAdmin($this->adminUser)->postJson('/api/pos/sales', $salePayload);

        $response->assertStatus(201);
        $saleId = $response->json('sale.id');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'iibb_perception_amount' => 300.00,
            'iibb_perception_rate' => 3.00,
            'total' => 12400.00,
        ]);

        $sale = Sale::find($saleId);
        $this->assertSame('300.00', (string) $sale->iibb_perception_amount);
        $this->assertSame('3.00', (string) $sale->iibb_perception_rate);
    }

    /**
     * 5. AfipWsfeService: Inject Tributos node and enforce strict order & math invariant
     */
    public function test_afip_wsfe_generates_tributos_xml_with_strict_schema_order_and_math_invariant(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);

        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 50, cae: '74382910492819', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Generador Diesel 5KVA',
            'internal_code' => 'GEN-5KVA',
            'cost_price' => 50000.00,
            'selling_price' => 1210.00,
            'iva_rate' => 21.00,
            'stock' => 10,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Comercial Mayorista Formosa',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'fiscal_address' => 'Calle Mitre 456',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);

        $sale = Sale::create([
            'total' => 1240.00, // 1000 Net + 210 IVA + 30 IIBB
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 30.00,
            'iibb_perception_rate' => 3.00,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 500.00,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'A')
            ->assertJsonPath('invoice.net_amount', '1000.00')
            ->assertJsonPath('invoice.iva_amount', '210.00')
            ->assertJsonPath('invoice.tribute_amount', '30.00')
            ->assertJsonPath('invoice.total_amount', '1240.00');

        $this->assertNotNull($interceptedXml, 'SOAP XML should have been captured');

        // 1. Tag <ImpTrib>
        $this->assertStringContainsString('<ImpTrib>30.00</ImpTrib>', $interceptedXml);

        // 2. Tag <ImpTotal> = 1240.00 (1000 + 210 + 30)
        $this->assertStringContainsString('<ImpTotal>1240.00</ImpTotal>', $interceptedXml);

        // 3. Nodo <Tributos>
        $this->assertStringContainsString('<Tributos>', $interceptedXml);
        $this->assertStringContainsString('<Id>2</Id>', $interceptedXml);
        $this->assertStringContainsString('<Desc>Percepcion de Ingresos Brutos</Desc>', $interceptedXml);
        $this->assertStringContainsString('<BaseImp>1000.00</BaseImp>', $interceptedXml);
        $this->assertStringContainsString('<Alic>3.00</Alic>', $interceptedXml);
        $this->assertStringContainsString('<Importe>30.00</Importe>', $interceptedXml);

        // 4. Orden estricto WSFEv1: CondicionIVAReceptorId -> Tributos -> Iva
        $posCondicion = strpos($interceptedXml, '<CondicionIVAReceptorId>');
        $posTributos = strpos($interceptedXml, '<Tributos>');
        $posIva = strpos($interceptedXml, '<Iva>');

        $this->assertNotFalse($posCondicion, 'CondicionIVAReceptorId must be present');
        $this->assertNotFalse($posTributos, 'Tributos must be present');
        $this->assertNotFalse($posIva, 'Iva must be present');

        $this->assertTrue($posCondicion < $posTributos, '<CondicionIVAReceptorId> must precede <Tributos>');
        $this->assertTrue($posTributos < $posIva, '<Tributos> must precede <Iva>');

        // 5. Verificación de persistencia en electronic_invoices
        $this->assertDatabaseHas('electronic_invoices', [
            'sale_id' => $sale->id,
            'tribute_amount' => 30.00,
        ]);

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertIsArray($invoice->tributes_breakdown);
        $this->assertEquals(2, $invoice->tributes_breakdown[0]['Id']);
        $this->assertEquals(30.00, (float) $invoice->tributes_breakdown[0]['Importe']);
    }

    /**
     * 6. AfipWsfeService: Omits Tributos node completely when perception <= 0
     */
    public function test_afip_wsfe_omits_tributos_node_completely_when_perception_is_zero(): void
    {
        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 60, cae: '74382910492819', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Tornillo Autoperforante',
            'internal_code' => 'TOR-01',
            'cost_price' => 10.00,
            'selling_price' => 1210.00,
            'iva_rate' => 21.00,
            'stock' => 100,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 1210.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 10.00,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_B,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '1210.00');

        $this->assertNotNull($interceptedXml);

        // Strict omission of <Tributos>
        $this->assertStringNotContainsString('<Tributos>', $interceptedXml);
        $this->assertStringNotContainsString('<Tributo>', $interceptedXml);
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>1210.00</ImpTotal>', $interceptedXml);
    }

    /**
     * 7. AfipWsfeService: Factura C (Monotributo / Exento) omite <Iva> y fuerza <Tributos> en $0
     */
    public function test_afip_wsfe_factura_c_supports_perception_and_omits_iva_node(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'Responsable Monotributo']);
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);

        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 70, cae: '74382910492819', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Servicio de Asesoría',
            'internal_code' => 'SRV-01',
            'cost_price' => 100.00,
            'selling_price' => 1000.00,
            'iva_rate' => 0.00,
            'stock' => 1,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Empresa Cliente Monotributo',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);

        $sale = Sale::create([
            'total' => 1030.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 30.00,
            'iibb_perception_rate' => 3.00,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 100.00,
            'unit_price' => 1000.00,
            'subtotal' => 1000.00,
            'iva_rate' => 0.00,
            'net_amount' => 1000.00,
            'iva_amount' => 0.00,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_C,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('invoice.voucher_letter', 'C')
            ->assertJsonPath('invoice.net_amount', '1000.00')
            ->assertJsonPath('invoice.iva_amount', '0.00')
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '1000.00');

        $this->assertNotNull($interceptedXml);

        // Factura C must NOT have <Iva> nor <Tributos>
        $this->assertStringNotContainsString('<Iva>', $interceptedXml);
        $this->assertStringNotContainsString('<Tributos>', $interceptedXml);
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $interceptedXml);
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<ImpNeto>1000.00</ImpNeto>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>1000.00</ImpTotal>', $interceptedXml);
    }

    /**
     * 8. AfipWsfeService: Auto-calculation fallback when perception amount not in sale but customer applies and business is agent
     */
    public function test_afip_wsfe_auto_calculates_perception_when_business_is_agent_and_customer_applies(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);

        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 80, cae: '74382910492819', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Lámpara LED Industrial',
            'internal_code' => 'LED-IND',
            'cost_price' => 200.00,
            'selling_price' => 1210.00,
            'iva_rate' => 21.00,
            'stock' => 10,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Distribuidora Formoseña',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 4.00, // Custom customer rate 4%
        ]);

        // Sale with total 1210.00 and no iibb_perception_amount pre-stored
        $sale = Sale::create([
            'total' => 1210.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 200.00,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);

        // 4% of 1000.00 net is 40.00. Total = 1000 + 210 + 40 = 1250.00
        $response->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '40.00')
            ->assertJsonPath('invoice.total_amount', '1250.00');

        $this->assertNotNull($interceptedXml);
        $this->assertStringContainsString('<ImpTrib>40.00</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>1250.00</ImpTotal>', $interceptedXml);
        $this->assertStringContainsString('<Alic>4.00</Alic>', $interceptedXml);
        $this->assertStringContainsString('<Importe>40.00</Importe>', $interceptedXml);
    }

    public function test_bk_high_01_credit_sale_iibb_perception_creates_ledger_entry_and_reverts_cleanly_on_void(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        $this->mockAfipSuccess(cbteNro: 81, cae: '74382910492820');

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Generador Industrial',
            'internal_code' => 'GEN-IND',
            'cost_price' => 500.00,
            'selling_price' => 1210.00,
            'iva_rate' => 21.00,
            'stock' => 10,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Constructora Norte S.A.',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 4.00,
            'balance' => 1210.00,
        ]);

        $sale = Sale::create([
            'total' => 1210.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'pending',
            'amount_due' => 1210.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 0.00,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 500.00,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
        ]);

        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => $this->adminUser->id,
            'cash_shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'type' => 'charge',
            'amount' => 1210.00,
            'balance_after' => 1210.00,
            'description' => "Venta en Cta. Cte. - Ticket #{$sale->id}",
        ]);

        // 1. Emitir factura electrónica (agrega $40.00 de IIBB a la venta en CC)
        $invoiceResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);
        $invoiceResp->assertStatus(200);

        $this->assertEquals(1250.00, (float) $customer->fresh()->balance);
        $this->assertEquals(2, CustomerTransaction::where('sale_id', $sale->id)->where('type', 'charge')->count());

        // 2. Anular la venta: debe revertir tanto los $1210 como los $40 de IIBB, dejando balance = 0.00
        $voidResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'reason' => 'Anulación de prueba con IIBB',
        ]);
        $voidResp->assertStatus(200);

        $this->assertEquals(0.00, (float) $customer->fresh()->balance, 'El saldo del cliente debe volver exactamente a 0.00 sin deuda huérfana de IIBB.');
    }

    /**
     * 9. Explicit 0% override in POS (iibb_perception_rate = 0.00) must NOT be recalculated
     * during contingency/deferred invoicing nor when emitting a Credit Note on void.
     */
    public function test_explicit_zero_perception_override_is_respected_in_deferred_invoice_and_credit_note_void(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);

        $interceptedXmls = [];
        $this->mockAfipSuccess(cbteNro: 95, cae: '74382910492899', requestInspector: function (HttpClientRequest $req) use (&$interceptedXmls) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXmls[] = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Motor Trifásico',
            'internal_code' => 'MOT-3F',
            'cost_price' => 400.00,
            'selling_price' => 1210.00,
            'iva_rate' => 21.00,
            'stock' => 10,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Metalúrgica Formosa S.R.L.',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 4.00,
        ]);

        // Cashier explicitly chose "Sin perc. (0%)", storing iibb_perception_rate = 0.00 (not null)
        $sale = Sale::create([
            'total' => 1210.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => 0.00,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 400.00,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
        ]);

        // 1. Emitir factura sin enviar opciones de percepción en el request (simula contingencia o facturación desde historial)
        $invoiceResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);

        $invoiceResp->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '1210.00');

        $this->assertEquals(1210.00, (float) $sale->fresh()->total);
        $this->assertNotEmpty($interceptedXmls);
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $interceptedXmls[0]);
        $this->assertStringContainsString('<ImpTotal>1210.00</ImpTotal>', $interceptedXmls[0]);

        // 2. Anular la venta: la Nota de Crédito A debe emitirse también con 0.00 de percepción y total 1210.00
        $voidResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'reason' => 'Anulación de factura con override 0% IIBB',
        ]);
        $voidResp->assertStatus(200);

        $this->assertCount(2, $interceptedXmls);
        $ncXml = $interceptedXmls[1];
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $ncXml);
        $this->assertStringContainsString('<ImpTotal>1210.00</ImpTotal>', $ncXml);
        $this->assertStringNotContainsString('<Tributos>', $ncXml);
    }

    /**
     * 10. Deferred Factura A from Sales History auto-applies default IIBB rate when business is agent,
     * even if the registered customer does not have applies_iibb_perception=true.
     */
    public function test_deferred_factura_a_auto_applies_default_iibb_rate_for_customer_without_flag(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);

        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 110, cae: '86461089624789', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Refrescante Bucal',
            'internal_code' => 'REF-01',
            'cost_price' => 4000.00,
            'selling_price' => 10000.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);

        // Exact real-world scenario: customer has CUIT 27388231497, applies_iibb_perception=false, rate=null
        $customer = Customer::create([
            'name' => 'Supermercado Los Primos SRL',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '27388231497',
            'tax_condition' => 'consumidor_final',
            'fiscal_address' => 'Av. 25 de Mayo 1420, Formosa',
            'applies_iibb_perception' => false,
            'iibb_perception_rate' => null,
        ]);

        $sale = Sale::create([
            'total' => 10000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 4000.00,
            'unit_price' => 10000.00,
            'subtotal' => 10000.00,
            'iva_rate' => 21.00,
            'net_amount' => 8264.46,
            'iva_amount' => 1735.54,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
            'doc_type' => AfipHelper::DOC_CUIT,
            'doc_number' => '27388231497',
            'receiver_name' => 'Supermercado Los Primos SRL',
            'receiver_tax_condition' => 'responsable_inscripto',
            'customer_id' => $customer->id,
        ]);

        // Net = 8264.46, IVA = 1735.54, IIBB 3% = 247.93, Total = 10247.93
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.net_amount', '8264.46')
            ->assertJsonPath('invoice.iva_amount', '1735.54')
            ->assertJsonPath('invoice.tribute_amount', '247.93')
            ->assertJsonPath('invoice.total_amount', '10247.93');

        $this->assertNotNull($interceptedXml);
        $this->assertStringContainsString('<ImpTrib>247.93</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<Alic>3.00</Alic>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>10247.93</ImpTotal>', $interceptedXml);

        $freshSale = $sale->fresh();
        $this->assertEquals(10247.93, (float) $freshSale->total);
        $this->assertEquals(247.93, (float) $freshSale->iibb_perception_amount);
        $this->assertEquals(3.00, (float) $freshSale->iibb_perception_rate);
    }

    /**
     * 11. Deferred Factura A without a linked customer_id auto-applies default IIBB rate.
     */
    public function test_deferred_factura_a_auto_applies_default_iibb_rate_without_local_customer(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);

        $this->mockAfipSuccess(cbteNro: 111, cae: '86461089624790');
        $shift = $this->createOpenShift();

        $product = Product::create([
            'name' => 'Refrescante Bucal',
            'internal_code' => 'REF-02',
            'cost_price' => 4000.00,
            'selling_price' => 10000.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 10000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => null,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 4000.00,
            'unit_price' => 10000.00,
            'subtotal' => 10000.00,
            'iva_rate' => 21.00,
            'net_amount' => 8264.46,
            'iva_amount' => 1735.54,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
            'doc_type' => AfipHelper::DOC_CUIT,
            'doc_number' => '27388231497',
            'receiver_name' => 'Supermercado Los Primos SRL',
            'receiver_tax_condition' => 'responsable_inscripto',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '247.93')
            ->assertJsonPath('invoice.total_amount', '10247.93');
    }

    /**
     * 12. Deferred Factura A respects explicit 0% IIBB override sent from Sales History dialog.
     */
    public function test_deferred_factura_a_respects_explicit_zero_iibb_override_from_sales_history(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);

        $interceptedXml = null;
        $this->mockAfipSuccess(cbteNro: 112, cae: '86461089624791', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $shift = $this->createOpenShift();
        $product = Product::create([
            'name' => 'Refrescante Bucal',
            'internal_code' => 'REF-03',
            'cost_price' => 4000.00,
            'selling_price' => 10000.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 10000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => null,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 4000.00,
            'unit_price' => 10000.00,
            'subtotal' => 10000.00,
            'iva_rate' => 21.00,
            'net_amount' => 8264.46,
            'iva_amount' => 1735.54,
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
            'doc_type' => AfipHelper::DOC_CUIT,
            'doc_number' => '27388231497',
            'receiver_name' => 'Supermercado Los Primos SRL',
            'receiver_tax_condition' => 'responsable_inscripto',
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => 0.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '10000.00');

        $this->assertNotNull($interceptedXml);
        $this->assertStringNotContainsString('<Tributos>', $interceptedXml);
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>10000.00</ImpTotal>', $interceptedXml);

        $freshSale = $sale->fresh();
        $this->assertEquals(10000.00, (float) $freshSale->total);
        $this->assertSame('0.00', (string) $freshSale->iibb_perception_rate);
    }

    /**
     * 13. Respects customer explicitly disabled with prior custom rate, and computes amount when only rate is provided.
     */
    public function test_deferred_factura_a_respects_customer_explicitly_disabled_custom_rate_and_explicit_rate_only(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);

        $cbteNroSeq = 112;
        Http::fake(function (HttpClientRequest $request) use (&$cbteNroSeq) {
            $body = $request->body();
            if (str_contains($body, 'FECompUltimoAutorizado')) {
                $last = $cbteNroSeq;
                $cbteNroSeq++;

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>1</CbteTipo>
        <CbteNro>'.$last.'</CbteNro>
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
          <Cuit>'.$this->testCuit.'</Cuit>
          <PtoVta>1</PtoVta>
          <CbteTipo>1</CbteTipo>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Concepto>1</Concepto>
            <DocTipo>80</DocTipo>
            <DocNro>30500010912</DocNro>
            <CbteDesde>'.$cbteNroSeq.'</CbteDesde>
            <CbteHasta>'.$cbteNroSeq.'</CbteHasta>
            <CbteFch>'.date('Ymd').'</CbteFch>
            <Resultado>A</Resultado>
            <CAE>86461089624'.$cbteNroSeq.'</CAE>
            <CAEFchVto>'.date('Ymd', strtotime('+10 days')).'</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });
        $shift = $this->createOpenShift();
        $product = Product::create([
            'name' => 'Refrescante Bucal',
            'internal_code' => 'REF-04',
            'cost_price' => 4000.00,
            'selling_price' => 10000.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);

        // Subcase A: customer explicitly has applies_iibb_perception=false AND custom rate 4.00 stored
        $disabledCustomer = Customer::create([
            'name' => 'Cliente Exento IIBB',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '27388231497',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => false,
            'iibb_perception_rate' => 4.00,
        ]);

        $saleA = Sale::create([
            'total' => 10000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $disabledCustomer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);
        $saleA->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 4000.00,
            'unit_price' => 10000.00,
            'subtotal' => 10000.00,
            'iva_rate' => 21.00,
            'net_amount' => 8264.46,
            'iva_amount' => 1735.54,
        ]);

        $respA = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleA->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);
        $respA->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '10000.00');

        // Subcase B: only iibb_perception_rate=1.5 is sent (without iibb_perception_amount) for a customer without flag
        $plainCustomer = Customer::create([
            'name' => 'Cliente Sin Flag',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => false,
            'iibb_perception_rate' => null,
        ]);

        $saleB = Sale::create([
            'total' => 10000.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $plainCustomer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);
        $saleB->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 4000.00,
            'unit_price' => 10000.00,
            'subtotal' => 10000.00,
            'iva_rate' => 21.00,
            'net_amount' => 8264.46,
            'iva_amount' => 1735.54,
        ]);

        $respB = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleB->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
            'iibb_perception_rate' => 1.5,
        ]);
        // round(8264.46 * 0.015, 2) = 123.97 -> total = 10123.97
        $respB->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '123.97')
            ->assertJsonPath('invoice.total_amount', '10123.97');
    }

    /**
     * 16. Settings API: Persiste iibb_calculation_base y sanitiza iibb_subject_tax_conditions
     */
    public function test_business_settings_api_persists_iibb_calculation_base_and_sanitizes_subject_conditions(): void
    {
        $putResponse = $this->actingAsAdmin($this->adminUser)->putJson('/api/settings', [
            'is_iibb_perception_agent' => '1',
            'default_iibb_perception_rate' => '3.00',
            'iibb_calculation_base' => 'gross_with_vat',
            'iibb_subject_tax_conditions' => ['responsable_inscripto', 'monotributo', 'consumidor_final', 'hack_invalid'],
        ]);

        $putResponse->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'key' => 'iibb_calculation_base',
            'value' => BusinessSetting::IIBB_BASE_GROSS_WITH_VAT,
        ]);

        // Consumidor Final y valores inválidos jamás deben guardarse en las condiciones alcanzadas
        $storedConditions = json_decode(
            (string) BusinessSetting::where('key', 'iibb_subject_tax_conditions')->value('value'),
            true
        );
        $this->assertSame(['responsable_inscripto', 'monotributo'], $storedConditions);

        // Valores desconocidos de base caen al default seguro net_without_vat
        $this->actingAsAdmin($this->adminUser)->putJson('/api/settings', [
            'iibb_calculation_base' => 'anything_unexpected',
            'iibb_subject_tax_conditions' => [],
        ])->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'key' => 'iibb_calculation_base',
            'value' => BusinessSetting::IIBB_BASE_NET_WITHOUT_VAT,
        ]);
        $this->assertSame([], BusinessSetting::getIibbSubjectTaxConditions());
    }

    /**
     * 17. AfipWsfeService: Ambas bases (net_without_vat y gross_with_vat) con alícuotas mixtas 21% / 10.5% / 0%
     *     envían BaseImp correcta en XML y preservan el BaseImp original al anular con Nota de Crédito.
     */
    public function test_afip_wsfe_supports_net_and_gross_iibb_bases_across_mixed_aliquots_and_preserves_credit_note_base(): void
    {
        $capturedXmls = [];
        $cbteSeq = 200;
        $this->mockAfipSuccess(cbteNro: 200, cae: '74382910499901', requestInspector: function (HttpClientRequest $req) use (&$capturedXmls, &$cbteSeq) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $capturedXmls[] = $body;
                $cbteSeq++;
            }
        });

        $shift = $this->createOpenShift();
        $prod21 = Product::create([
            'name' => 'Pintura Látex 20L',
            'internal_code' => 'P21-20',
            'cost_price' => 5000,
            'selling_price' => 12100.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);
        $prod105 = Product::create([
            'name' => 'Motor Monofásico',
            'internal_code' => 'M105-1',
            'cost_price' => 5000,
            'selling_price' => 11050.00,
            'iva_rate' => 10.50,
            'stock' => 20,
            'active' => true,
        ]);
        $prod0 = Product::create([
            'name' => 'Manual Técnico Exento',
            'internal_code' => 'EX0-1',
            'cost_price' => 500,
            'selling_price' => 1000.00,
            'iva_rate' => 0.00,
            'stock' => 20,
            'active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Constructora Mixta SRL',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);

        // Subtotal bruto = 12100 + 11050 + 1000 = 24150.00
        // ImpNeto = 10000 + 10000 + 1000 = 21000.00 | ImpIVA = 2100 + 1050 = 3150.00
        // Caso A: Base Sobre Subtotal con IVA (gross_with_vat) => BaseImp = 24150.00, IIBB 3% = 724.50, ImpTotal = 24874.50
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'iibb_calculation_base'], ['value' => BusinessSetting::IIBB_BASE_GROSS_WITH_VAT]);

        $saleGross = Sale::create([
            'total' => 24150.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $customer->id,
        ]);
        $saleGross->items()->create(['product_id' => $prod21->id, 'product_name' => $prod21->name, 'quantity' => 1, 'unit_cost_price' => 5000, 'unit_price' => 12100, 'subtotal' => 12100, 'iva_rate' => 21.00, 'net_amount' => 10000, 'iva_amount' => 2100]);
        $saleGross->items()->create(['product_id' => $prod105->id, 'product_name' => $prod105->name, 'quantity' => 1, 'unit_cost_price' => 5000, 'unit_price' => 11050, 'subtotal' => 11050, 'iva_rate' => 10.50, 'net_amount' => 10000, 'iva_amount' => 1050]);
        $saleGross->items()->create(['product_id' => $prod0->id, 'product_name' => $prod0->name, 'quantity' => 1, 'unit_cost_price' => 500, 'unit_price' => 1000, 'subtotal' => 1000, 'iva_rate' => 0.00, 'net_amount' => 1000, 'iva_amount' => 0]);

        $respGross = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleGross->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);

        $respGross->assertStatus(200)
            ->assertJsonPath('invoice.net_amount', '21000.00')
            ->assertJsonPath('invoice.iva_amount', '3150.00')
            ->assertJsonPath('invoice.tribute_amount', '724.50')
            ->assertJsonPath('invoice.total_amount', '24874.50');

        $grossXml = $capturedXmls[0];
        $this->assertStringContainsString('<ImpNeto>21000.00</ImpNeto>', $grossXml);
        $this->assertStringContainsString('<ImpIVA>3150.00</ImpIVA>', $grossXml);
        $this->assertStringContainsString('<ImpTrib>724.50</ImpTrib>', $grossXml);
        $this->assertStringContainsString('<ImpTotal>24874.50</ImpTotal>', $grossXml);
        $this->assertStringContainsString('<BaseImp>24150.00</BaseImp><Alic>3.00</Alic><Importe>724.50</Importe>', $grossXml);

        // Si ahora cambiamos la configuración global a net_without_vat y anulamos la venta anterior,
        // la Nota de Crédito debe preservar BaseImp=24150.00 e Importe=724.50 originales.
        BusinessSetting::updateOrCreate(['key' => 'iibb_calculation_base'], ['value' => BusinessSetting::IIBB_BASE_NET_WITHOUT_VAT]);

        $cashMethod = PaymentMethod::create(['name' => 'Efectivo', 'code' => 'cash', 'is_cash' => true, 'active' => true]);
        $saleGross->payments()->create(['payment_method_id' => $cashMethod->id, 'base_amount' => 24874.50, 'surcharge_amount' => 0, 'total_amount' => 24874.50]);

        $voidResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleGross->id}/void", [
            'reason' => 'Prueba preservación BaseImp en NC',
        ]);
        $voidResp->assertStatus(200);
        $ncXml = $capturedXmls[1];
        $this->assertStringContainsString('<BaseImp>24150.00</BaseImp><Alic>3.00</Alic><Importe>724.50</Importe>', $ncXml);
        $this->assertStringContainsString('<ImpTotal>24874.50</ImpTotal>', $ncXml);
    }

    /**
     * 18. AfipWsfeService: Sujetos alcanzados configurables (Monotributo / Exento opcionales, Consumidor Final nunca)
     *     y guarda de contingencia cuando cambia iibb_calculation_base tras cobrar.
     */
    public function test_afip_wsfe_applies_iibb_to_configured_subject_conditions_and_never_consumidor_final(): void
    {
        $nextCbte = 300;
        Http::fake(function (HttpClientRequest $req) use (&$nextCbte) {
            $body = $req->body();
            if (str_contains($body, 'FECompUltimoAutorizado')) {
                $lastAuthorized = $nextCbte - 1;

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult>
        <PtoVta>1</PtoVta>
        <CbteTipo>1</CbteTipo>
        <CbteNro>'.$lastAuthorized.'</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }
            if (str_contains($body, 'FECAESolicitar')) {
                $issued = $nextCbte++;

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp><Cuit>'.$this->testCuit.'</Cuit><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><Resultado>A</Resultado></FeCabResp>
        <FeDetResp><FECAEDetResponse><Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>20123456786</DocNro><CbteDesde>'.$issued.'</CbteDesde><CbteHasta>'.$issued.'</CbteHasta><CbteFch>'.date('Ymd').'</CbteFch><Resultado>A</Resultado><CAE>74382910499902</CAE><CAEFchVto>'.date('Ymd', strtotime('+10 days')).'</CAEFchVto></FECAEDetResponse></FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });
        $shift = $this->createOpenShift();
        $product = Product::create([
            'name' => 'Cemento x50kg',
            'internal_code' => 'CEM-MIX',
            'cost_price' => 5000,
            'selling_price' => 12100.00,
            'iva_rate' => 21.00,
            'stock' => 20,
            'active' => true,
        ]);

        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'iibb_calculation_base'], ['value' => BusinessSetting::IIBB_BASE_NET_WITHOUT_VAT]);

        // Caso 1: Solo RI habilitado -> Monotributista en Factura A NO percibe automáticamente
        BusinessSetting::updateOrCreate(['key' => 'iibb_subject_tax_conditions'], ['value' => json_encode(['responsable_inscripto'])]);

        $monoCustomer = Customer::create([
            'name' => 'Taller Monotributo',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '20123456786',
            'tax_condition' => 'monotributo',
            'applies_iibb_perception' => false,
        ]);

        $saleMono1 = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $monoCustomer->id,
        ]);
        $saleMono1->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_cost_price' => 5000, 'unit_price' => 12100, 'subtotal' => 12100, 'iva_rate' => 21.00, 'net_amount' => 10000, 'iva_amount' => 2100]);

        $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleMono1->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ])->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '12100.00');

        // Caso 2: Al habilitar monotributo y exento en config -> Monotributista percibe automático (3% s/ Neto $10.000 = $300), Consumidor Final NUNCA
        BusinessSetting::updateOrCreate(['key' => 'iibb_subject_tax_conditions'], ['value' => json_encode(['responsable_inscripto', 'monotributo', 'exento'])]);

        $saleMono2 = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $monoCustomer->id,
        ]);
        $saleMono2->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_cost_price' => 5000, 'unit_price' => 12100, 'subtotal' => 12100, 'iva_rate' => 21.00, 'net_amount' => 10000, 'iva_amount' => 2100]);

        $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleMono2->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ])->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '300.00')
            ->assertJsonPath('invoice.total_amount', '12400.00');

        $cfCustomer = Customer::create([
            'name' => 'Consumidor Final DNI',
            'document_type' => AfipHelper::DOC_DNI,
            'document_number' => '33445566',
            'tax_condition' => 'consumidor_final',
            'applies_iibb_perception' => true, // Incluso con flag viejo sucio, Consumidor Final queda bloqueado
            'iibb_perception_rate' => 3.00,
        ]);

        $saleCf = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $cfCustomer->id,
        ]);
        $saleCf->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_cost_price' => 5000, 'unit_price' => 12100, 'subtotal' => 12100, 'iva_rate' => 21.00, 'net_amount' => 10000, 'iva_amount' => 2100]);

        $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleCf->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_B,
        ])->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '12100.00');
    }

    /**
     * 19. Pactar / Redondear Total Final + Guarda de Contingencia:
     *     Despeje inverso exacto al centavo con artículos 21%, 10.5% y 0% en ambas bases.
     */
    public function test_agreed_total_rounding_after_iibb_closes_exact_to_the_cent_and_contingency_auto_detects_base(): void
    {
        $capturedXml = null;
        $this->mockAfipSuccess(cbteNro: 400, cae: '74382910499903', requestInspector: function (HttpClientRequest $req) use (&$capturedXml) {
            if (str_contains($req->body(), 'FECAESolicitar')) {
                $capturedXml = $req->body();
            }
        });

        $shift = $this->createOpenShift();
        $cashMethod = PaymentMethod::create(['name' => 'Efectivo', 'code' => 'cash', 'is_cash' => true, 'active' => true]);
        $prod21 = Product::create(['name' => 'Taladro 21%', 'internal_code' => 'T21', 'cost_price' => 5000, 'selling_price' => 12100, 'iva_rate' => 21.00, 'stock' => 20, 'active' => true]);
        $prod105 = Product::create(['name' => 'Compresor 10.5%', 'internal_code' => 'C105', 'cost_price' => 5000, 'selling_price' => 11050, 'iva_rate' => 10.50, 'stock' => 20, 'active' => true]);
        $prod0 = Product::create(['name' => 'Guía Exenta 0%', 'internal_code' => 'G0', 'cost_price' => 500, 'selling_price' => 1000, 'iva_rate' => 0.00, 'stock' => 20, 'active' => true]);
        $customer = Customer::create(['name' => 'Cliente Pacto SA', 'document_type' => 80, 'document_number' => '30500010912', 'tax_condition' => 'responsable_inscripto', 'applies_iibb_perception' => true, 'iibb_perception_rate' => 3.00]);

        // Objetivo pactado cerrado: $23.000,00 en modo net_without_vat (alícuota mixta 21% + 10.5% + 0%)
        // Mercadería despejada = $22.416,52:
        //   Item 21% = 11231.38 (Net 9282.13, IVA 1949.25)
        //   Item 10.5% = 10256.92 (Net 9282.28, IVA 974.64)
        //   Item 0% = 928.22 (Net 928.22, IVA 0.00)
        //   Suma Neto = 19492.63, IVA = 2923.89 => Net+IVA = 22416.52
        //   IIBB 3% s/ 19492.63 = 584.78 => 22416.52 + 584.78 = 23001.30 (probamos target 23000.00 real)
        // Ajuste exacto con 22415.25: 11230.74 + 10256.34 + 928.17 = 22415.25
        //   Neto = 9281.60 + 9281.76 + 928.17 = 19491.53 | IVA = 1949.14 + 974.58 = 2923.72 | Net+IVA = 22415.25
        //   IIBB 3% s/ 19491.53 = 584.75 -> 22415.25 + 584.75 = 23000.00 EXACTO
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);
        BusinessSetting::updateOrCreate(['key' => 'iibb_calculation_base'], ['value' => BusinessSetting::IIBB_BASE_NET_WITHOUT_VAT]);

        $saleResp = $this->actingAsAdmin($this->adminUser)->postJson('/api/pos/sales', [
            'total' => 23000.00,
            'total_surcharge' => 0.00,
            'shipping_cost' => 0.00,
            'cash_shift_id' => $shift->id,
            'customer_id' => $customer->id,
            'iibb_perception_amount' => 584.75,
            'iibb_perception_rate' => 3.00,
            'payments' => [[
                'payment_method_id' => $cashMethod->id,
                'base_amount' => 23000.00,
                'surcharge_amount' => 0.00,
                'total_amount' => 23000.00,
            ]],
            'items' => [
                ['product_id' => $prod21->id, 'quantity' => 1, 'unit_price' => 11230.74, 'subtotal' => 11230.74],
                ['product_id' => $prod105->id, 'quantity' => 1, 'unit_price' => 10256.34, 'subtotal' => 10256.34],
                ['product_id' => $prod0->id, 'quantity' => 1, 'unit_price' => 928.17, 'subtotal' => 928.17],
            ],
        ])->assertCreated();

        $saleId = $saleResp->json('sale.id');

        // Simulamos que el comercio cambió la base global a gross_with_vat antes de que contingencia emita el CAE:
        // la guarda de AfipWsfeService debe detectar que $584,75 corresponde a BaseImp = 19491.53 (net_without_vat)
        // y emitir ImpTotal = 23000.00 con ImpNeto + ImpIVA + ImpTrib cerrado al centavo.
        BusinessSetting::updateOrCreate(['key' => 'iibb_calculation_base'], ['value' => BusinessSetting::IIBB_BASE_GROSS_WITH_VAT]);

        $invResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$saleId}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
        ]);

        $invResp->assertStatus(200)
            ->assertJsonPath('invoice.net_amount', '19491.53')
            ->assertJsonPath('invoice.iva_amount', '2923.72')
            ->assertJsonPath('invoice.tribute_amount', '584.75')
            ->assertJsonPath('invoice.total_amount', '23000.00');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<BaseImp>19491.53</BaseImp><Alic>3.00</Alic><Importe>584.75</Importe>', $capturedXml);
        $this->assertStringContainsString('<ImpTotal>23000.00</ImpTotal>', $capturedXml);
    }

    /**
     * 20. Hallazgo #1: Factura B sobre Consumidor Final fuerza percepción IIBB = $0 y omite <Tributos>
     *     incluso si el cliente tiene applies_iibb_perception = true o se envían options explícitas.
     */
    public function test_factura_b_consumidor_final_forces_zero_tributos_even_with_explicit_perception_option(): void
    {
        $capturedXml = null;
        $this->mockAfipSuccess(cbteNro: 401, cae: '74382910499904', requestInspector: function (HttpClientRequest $req) use (&$capturedXml) {
            if (str_contains($req->body(), 'FECAESolicitar')) {
                $capturedXml = $req->body();
            }
        });

        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);

        $shift = $this->createOpenShift();
        $product = Product::create(['name' => 'Producto Test FB', 'internal_code' => 'FB-01', 'cost_price' => 5000, 'selling_price' => 12100, 'iva_rate' => 21.00, 'stock' => 10, 'active' => true]);
        $riDirtyCustomer = Customer::create([
            'name' => 'Cliente Switch Factura B',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);

        $sale = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $riDirtyCustomer->id,
            'iibb_perception_amount' => 0.00,
            'iibb_perception_rate' => null,
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 5000,
            'unit_price' => 12100,
            'subtotal' => 12100,
            'iva_rate' => 21.00,
            'net_amount' => 10000,
            'iva_amount' => 2100,
        ]);

        $invResp = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_B,
            'doc_type' => AfipHelper::DOC_DNI,
            'doc_number' => '33445566',
            'receiver_tax_condition' => 'consumidor_final',
            'iibb_perception_amount' => 300.00,
            'iibb_perception_rate' => 3.00,
        ]);

        $invResp->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '12100.00');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<CondicionIVAReceptorId>5</CondicionIVAReceptorId>', $capturedXml);
        $this->assertStringNotContainsString('<Tributos>', $capturedXml);
    }

    /**
     * 21. Seguridad fiscal IIBB v2.2.0:
     *     - Rechaza 'Consumidor Final' como tax_condition del comercio emisor (422).
     *     - Monotributo / IVA Exento fuerzan is_iibb_perception_agent = '0' al guardar settings.
     *     - AfipWsfeService fuerza $0 de percepción si is_iibb_perception_agent = '0' o emisor no RI.
     */
    public function test_issuer_tax_condition_restrictions_and_iibb_agent_gate_on_settings_and_invoicing(): void
    {
        // A) Consumidor Final rechazado en PUT /api/settings
        $this->actingAsAdmin($this->adminUser)
            ->putJson('/api/settings', [
                'tax_condition' => 'Consumidor Final',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_condition']);

        // B) Monotributo / Exento fuerzan is_iibb_perception_agent = '0' aunque se envíe '1'
        $this->actingAsAdmin($this->adminUser)
            ->putJson('/api/settings', [
                'tax_condition' => 'Responsable Monotributo',
                'is_iibb_perception_agent' => '1',
                'default_iibb_perception_rate' => '3.00',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('business_settings', [
            'key' => 'tax_condition',
            'value' => 'Responsable Monotributo',
        ]);
        $this->assertDatabaseHas('business_settings', [
            'key' => 'is_iibb_perception_agent',
            'value' => '0',
        ]);

        // C) Facturar como IVA Responsable Inscripto con is_iibb_perception_agent = '0'
        //    fuerza tribute_amount = 0.00 incluso si el request envía montos/tasas explícitas.
        $capturedXml = null;
        $this->mockAfipSuccess(cbteNro: 505, cae: '74382910499955', requestInspector: function (HttpClientRequest $req) use (&$capturedXml) {
            if (str_contains($req->body(), 'FECAESolicitar')) {
                $capturedXml = $req->body();
            }
        });

        BusinessSetting::updateOrCreate(['key' => 'tax_condition'], ['value' => 'IVA Responsable Inscripto']);
        BusinessSetting::updateOrCreate(['key' => 'is_iibb_perception_agent'], ['value' => '0']);
        BusinessSetting::updateOrCreate(['key' => 'default_iibb_perception_rate'], ['value' => '3.00']);

        $shift = $this->createOpenShift();
        $product = Product::create([
            'name' => 'Producto Sin Agente',
            'internal_code' => 'NOAG-01',
            'cost_price' => 5000,
            'selling_price' => 12100.00,
            'iva_rate' => 21.00,
            'stock' => 10,
            'active' => true,
        ]);
        $riCustomer = Customer::create([
            'name' => 'Empresa Receptora RI',
            'document_type' => AfipHelper::DOC_CUIT,
            'document_number' => '30500010912',
            'tax_condition' => 'responsable_inscripto',
            'applies_iibb_perception' => true,
            'iibb_perception_rate' => 3.00,
        ]);
        $sale = Sale::create([
            'total' => 12100.00,
            'total_surcharge' => 0.00,
            'payment_status' => 'paid',
            'amount_due' => 0.00,
            'status' => 'completed',
            'invoice_status' => 'none',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->adminUser->id,
            'cashier_id' => $this->adminUser->id,
            'customer_id' => $riCustomer->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 5000,
            'unit_price' => 12100,
            'subtotal' => 12100,
            'iva_rate' => 21.00,
            'net_amount' => 10000,
            'iva_amount' => 2100,
        ]);

        $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => AfipHelper::VOUCHER_FACTURA_A,
            'iibb_perception_amount' => 300.00,
            'iibb_perception_rate' => 3.00,
        ])->assertStatus(200)
            ->assertJsonPath('invoice.tribute_amount', '0.00')
            ->assertJsonPath('invoice.total_amount', '12100.00');

        $this->assertNotNull($capturedXml);
        $this->assertStringNotContainsString('<Tributos>', $capturedXml);
    }
}
