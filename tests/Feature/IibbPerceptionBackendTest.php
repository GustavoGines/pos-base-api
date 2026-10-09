<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
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
            'token'           => 'FAKE_TOKEN_WSAA_123456',
            'sign'            => 'FAKE_SIGN_WSAA_789012',
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
        <CbteNro>' . ($cbteNro - 1) . '</CbteNro>
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
          <Cuit>' . $this->testCuit . '</Cuit>
          <PtoVta>1</PtoVta>
          <CbteTipo>6</CbteTipo>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Concepto>1</Concepto>
            <DocTipo>80</DocTipo>
            <DocNro>20301234567</DocNro>
            <CbteDesde>' . $cbteNro . '</CbteDesde>
            <CbteHasta>' . $cbteNro . '</CbteHasta>
            <CbteFch>' . date('Ymd') . '</CbteFch>
            <Resultado>A</Resultado>
            <CAE>' . $cae . '</CAE>
            <CAEFchVto>' . date('Ymd', strtotime('+10 days')) . '</CAEFchVto>
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
     * 7. AfipWsfeService: Factura C (Monotributo) with perception
     */
    public function test_afip_wsfe_factura_c_supports_perception_and_omits_iva_node(): void
    {
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
            ->assertJsonPath('invoice.tribute_amount', '30.00')
            ->assertJsonPath('invoice.total_amount', '1030.00');

        $this->assertNotNull($interceptedXml);

        // Factura C must NOT have <Iva>
        $this->assertStringNotContainsString('<Iva>', $interceptedXml);
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $interceptedXml);

        // Factura C with perception must have <Tributos>
        $this->assertStringContainsString('<Tributos>', $interceptedXml);
        $this->assertStringContainsString('<ImpTrib>30.00</ImpTrib>', $interceptedXml);
        $this->assertStringContainsString('<ImpNeto>1000.00</ImpNeto>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>1030.00</ImpTotal>', $interceptedXml);
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
}
