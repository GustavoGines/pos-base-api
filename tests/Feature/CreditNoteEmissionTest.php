<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Afip\AfipHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Emisión de Notas de Crédito Electrónicas al anular ventas con CAE.
 * Cubre hallazgos H-01 (NC C Monotributo) y H-02 (CUIT en CbteAsoc) de audit_report.md.
 */
class CreditNoteEmissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected string $issuerCuit = '30712345678';
    protected string $buyerCuit = '30500010912';

    /** @var array<int, string> Cuerpos SOAP enviados a FECAESolicitar */
    protected array $sentCaeRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);

        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->issuerCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);

        Cache::put("afip_ta_{$this->issuerCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    protected function fakeAfip(bool $approve = true, int $lastNumber = 41): void
    {
        $this->sentCaeRequests = [];

        Http::fake(function (HttpClientRequest $request) use ($approve, $lastNumber) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
                    . '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompUltimoAutorizadoResult>'
                    . "<CbteNro>{$lastNumber}</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse>"
                    . '</soap:Body></soap:Envelope>',
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            if (str_contains($body, 'FECAESolicitar')) {
                $this->sentCaeRequests[] = $body;

                $detail = $approve
                    ? '<Resultado>A</Resultado><CAE>71234567890123</CAE><CAEFchVto>20261019</CAEFchVto>'
                    : '<Resultado>R</Resultado><Observaciones><Obs><Code>10040</Code><Msg>Rechazo simulado</Msg></Obs></Observaciones>';

                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
                    . '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECAESolicitarResult>'
                    . '<FeCabResp><Resultado>' . ($approve ? 'A' : 'R') . '</Resultado></FeCabResp>'
                    . "<FeDetResp><FECAEDetResponse>{$detail}</FECAEDetResponse></FeDetResp>"
                    . '</FECAESolicitarResult></FECAESolicitarResponse></soap:Body></soap:Envelope>',
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            return Http::response('', 200);
        });
    }

    /**
     * Crea una venta completada con su factura electrónica ya autorizada (CAE).
     *
     * @return array{0: Sale, 1: Product, 2: int}
     */
    protected function createInvoicedSale(int $voucherType, int $docType, string $docNumber, string $taxCondition): array
    {
        $shift = $this->crearTurnoAbierto(user: $this->adminUser);

        $product = Product::create([
            'name'          => 'Producto NC Test',
            'internal_code' => 'NC-01',
            'cost_price'    => 500.00,
            'selling_price' => 1210.00,
            'iva_rate'      => 21.00,
            'stock'         => 100,
            'active'        => true,
        ]);

        $sale = Sale::create([
            'total'           => 1210.00,
            'total_surcharge' => 0.00,
            'payment_status'  => 'paid',
            'amount_due'      => 0.00,
            'status'          => 'completed',
            'invoice_status'  => 'invoiced',
            'cash_shift_id'   => $shift->id,
            'user_id'         => $this->adminUser->id,
            'cashier_id'      => $this->adminUser->id,
        ]);

        $sale->items()->create([
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'quantity'        => 1,
            'unit_cost_price' => 500.00,
            'unit_price'      => 1210.00,
            'subtotal'        => 1210.00,
            'iva_rate'        => 21.00,
            'net_amount'      => 1000.00,
            'iva_amount'      => 210.00,
        ]);

        $isC = AfipHelper::getVoucherLetter($voucherType) === 'C';

        ElectronicInvoice::create([
            'sale_id'                => $sale->id,
            'voucher_type'           => $voucherType,
            'voucher_letter'         => AfipHelper::getVoucherLetter($voucherType),
            'point_of_sale'          => 1,
            'voucher_number'         => 77,
            'cae'                    => '69999999999999',
            'cae_expiration'         => '2026-10-15',
            'doc_type'               => $docType,
            'doc_number'             => $docNumber,
            'receiver_tax_condition' => $taxCondition,
            'net_amount'             => $isC ? 1210.00 : 1000.00,
            'iva_amount'             => $isC ? 0.00 : 210.00,
            'total_amount'           => 1210.00,
            'status'                 => 'authorized',
            'issued_at'              => '2026-10-05 10:00:00',
        ]);

        return [$sale, $product, $shift->id];
    }

    // ─── Tests ──────────────────────────────────────────────────────────────

    /**
     * H-01: Nota de Crédito C (13) debe omitir <Iva>, ImpIVA = 0 e ImpNeto = ImpTotal.
     */
    public function test_nota_credito_c_omits_iva_node_and_zeroes_imp_iva(): void
    {
        $this->fakeAfip();
        [$sale, , $shiftId] = $this->createInvoicedSale(
            AfipHelper::VOUCHER_FACTURA_C,
            AfipHelper::DOC_CONSUMIDOR_FINAL,
            '0',
            'consumidor_final'
        );

        $response = $this->actingAsAdmin($this->adminUser)
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shiftId]);

        $response->assertOk();
        $this->assertCount(1, $this->sentCaeRequests);
        $body = $this->sentCaeRequests[0];

        $this->assertStringContainsString('<CbteTipo>13</CbteTipo>', $body);
        $this->assertStringNotContainsString('<Iva>', $body);
        $this->assertStringNotContainsString('<AlicIva>', $body);
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $body);
        $this->assertStringContainsString('<ImpNeto>1210.00</ImpNeto>', $body);
        $this->assertStringContainsString('<ImpTotal>1210.00</ImpTotal>', $body);
        $this->assertStringContainsString(
            "<CbtesAsoc><CbteAsoc><Tipo>11</Tipo><PtoVta>1</PtoVta><Nro>77</Nro><Cuit>{$this->issuerCuit}</Cuit><CbteFch>20261005</CbteFch></CbteAsoc></CbtesAsoc>",
            $body
        );

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertSame('71234567890123', $invoice->credit_note_cae);
        $this->assertSame(42, (int) $invoice->credit_note_number);
        $this->assertSame(AfipHelper::VOUCHER_NOTA_CREDITO_C, (int) $invoice->credit_note_voucher_type);
        $this->assertSame('voided', $sale->fresh()->status);
    }

    /**
     * H-02: CbteAsoc debe informar el CUIT del EMISOR, nunca el CUIT del receptor.
     * Además la NC A conserva el desglose de IVA y exige CUIT del receptor.
     */
    public function test_nota_credito_a_uses_issuer_cuit_in_cbte_asoc_and_keeps_iva(): void
    {
        $this->fakeAfip();
        [$sale, , $shiftId] = $this->createInvoicedSale(
            AfipHelper::VOUCHER_FACTURA_A,
            AfipHelper::DOC_CUIT,
            $this->buyerCuit,
            'responsable_inscripto'
        );

        $response = $this->actingAsAdmin($this->adminUser)
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shiftId]);

        $response->assertOk();
        $this->assertCount(1, $this->sentCaeRequests);
        $body = $this->sentCaeRequests[0];

        $this->assertStringContainsString('<CbteTipo>3</CbteTipo>', $body);
        $this->assertStringContainsString('<DocTipo>80</DocTipo>', $body);
        $this->assertStringContainsString("<DocNro>{$this->buyerCuit}</DocNro>", $body);
        $this->assertStringContainsString('<Iva><AlicIva><Id>5</Id>', $body);

        preg_match('/<CbtesAsoc>(.*?)<\/CbtesAsoc>/s', $body, $asoc);
        $this->assertNotEmpty($asoc, 'La NC debe incluir el nodo <CbtesAsoc>');
        $this->assertStringContainsString('<Tipo>1</Tipo>', $asoc[1]);
        $this->assertStringContainsString("<Cuit>{$this->issuerCuit}</Cuit>", $asoc[1]);
        $this->assertStringNotContainsString($this->buyerCuit, $asoc[1], 'CbteAsoc jamás debe llevar el CUIT del receptor');

        $this->assertSame('voided', $sale->fresh()->status);
    }

    /**
     * Si AFIP rechaza la NC, la venta NO se anula y el stock NO se reintegra.
     */
    public function test_afip_rejection_keeps_sale_active_and_stock_untouched(): void
    {
        $this->fakeAfip(approve: false);
        [$sale, $product, $shiftId] = $this->createInvoicedSale(
            AfipHelper::VOUCHER_FACTURA_B,
            AfipHelper::DOC_CONSUMIDOR_FINAL,
            '0',
            'consumidor_final'
        );

        $response = $this->actingAsAdmin($this->adminUser)
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shiftId]);

        $response->assertStatus(422)->assertJsonPath('error', true);
        $this->assertStringContainsString('Nota de Crédito', (string) $response->json('message'));

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertEquals(100, (float) $product->fresh()->stock);
        $this->assertNull(ElectronicInvoice::where('sale_id', $sale->id)->first()->credit_note_cae);
    }

    /**
     * Una venta que ya tiene NC emitida no vuelve a invocar a AFIP (sin NC duplicadas).
     */
    public function test_sale_with_existing_credit_note_does_not_reinvoke_afip(): void
    {
        $this->fakeAfip();
        [$sale, , $shiftId] = $this->createInvoicedSale(
            AfipHelper::VOUCHER_FACTURA_B,
            AfipHelper::DOC_CONSUMIDOR_FINAL,
            '0',
            'consumidor_final'
        );
        ElectronicInvoice::where('sale_id', $sale->id)->update(['credit_note_cae' => '70000000000001']);

        $response = $this->actingAsAdmin($this->adminUser)
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shiftId]);

        $response->assertOk();
        $this->assertCount(0, $this->sentCaeRequests);
        $this->assertSame('voided', $sale->fresh()->status);
    }
}
