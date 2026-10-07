<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Afip\AfipHelper;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfipChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected string $testIssuerCuit = '30712345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);

        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->testIssuerCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);

        Cache::put("afip_ta_{$this->testIssuerCuit}_wsfe_testing", [
            'token'           => 'CHALLENGER_TOKEN_WSAA',
            'sign'            => 'CHALLENGER_SIGN_WSAA',
            'expiration_time' => date('c', time() + 36000),
        ], 36000);
    }

    /**
     * Helper to mock successful AFIP responses.
     */
    protected function mockAfipSuccess(int $cbteNro = 100, string $cae = '99887766554433', ?callable $requestInspector = null): void
    {
        Http::fake(function (HttpClientRequest $request) use ($cbteNro, $cae, $requestInspector) {
            $body = $request->body();

            if ($requestInspector) {
                $requestInspector($request);
            }

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                $last = $cbteNro - 1;
                return Http::response("<?xml version=\"1.0\" encoding=\"utf-8\"?>
<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns=\"http://ar.gov.afip.dif.FEV1/\">
      <FECompUltimoAutorizadoResult>
        <CbteNro>{$last}</CbteNro>
      </FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>", 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                return Http::response("<?xml version=\"1.0\" encoding=\"utf-8\"?>
<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\">
  <soap:Body>
    <FECAESolicitarResponse xmlns=\"http://ar.gov.afip.dif.FEV1/\">
      <FECAESolicitarResult>
        <FeCabResp><Resultado>A</Resultado></FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>A</Resultado>
            <CAE>{$cae}</CAE>
            <CAEFchVto>20261020</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>", 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });
    }

    /**
     * Helper to create a sale with multiple arbitrary items and tax rates.
     */
    protected function createSaleWithItems(array $itemDefinitions, ?Customer $customer = null): Sale
    {
        $shift = $this->crearTurnoAbierto(user: $this->adminUser);

        $totalSale = 0.0;
        foreach ($itemDefinitions as $def) {
            $subtotal = round((float) ($def['subtotal'] ?? ($def['price'] * ($def['qty'] ?? 1))), 2);
            $totalSale += $subtotal;
        }
        $totalSale = round($totalSale, 2);

        $sale = Sale::create([
            'total'           => $totalSale,
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

        foreach ($itemDefinitions as $idx => $def) {
            $price = (float) ($def['price'] ?? $def['subtotal']);
            $qty = (float) ($def['qty'] ?? 1);
            $subtotal = round((float) ($def['subtotal'] ?? ($price * $qty)), 2);
            $rate = (float) ($def['iva_rate'] ?? 21.00);

            $calc = AfipHelper::calculateNetAndIva($subtotal, $rate);

            $product = Product::create([
                'name'          => 'Product Item ' . ($idx + 1),
                'internal_code' => 'P-CHALLENGE-' . ($idx + 1) . '-' . uniqid(),
                'cost_price'    => round($price * 0.5, 2),
                'selling_price' => $price,
                'iva_rate'      => $rate,
                'stock'         => 100,
                'active'        => true,
            ]);

            $sale->items()->create([
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'quantity'        => $qty,
                'unit_cost_price' => round($price * 0.5, 2),
                'unit_price'      => $price,
                'subtotal'        => $subtotal,
                'iva_rate'        => $rate,
                'net_amount'      => $calc['net'],
                'iva_amount'      => $calc['iva'],
            ]);
        }

        return $sale;
    }

    // =========================================================================
    // SECTION 1: MODULO 11 ADVERSARIAL STRESS TESTING
    // =========================================================================

    /**
     * CHALLENGE 1.1: Modulo 11 Remainder 0 Edge Case.
     * When sum % 11 == 0, the expected digit must be 0, and any other digit (1..9) MUST be rejected.
     */
    public function test_cuit_modulo_11_remainder_0_edge_case(): void
    {
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $testedCount = 0;

        // Search for 50 distinct 10-digit prefixes that yield sum % 11 == 0
        foreach (['20', '23', '24', '27', '30', '33', '34'] as $prefix) {
            for ($doc = 10000000; $doc <= 10000300; $doc++) {
                $base = $prefix . (string) $doc;
                $sum = 0;
                for ($i = 0; $i < 10; $i++) {
                    $sum += ((int) $base[$i]) * $multipliers[$i];
                }

                if ($sum % 11 === 0) {
                    $cuitWith0 = $base . '0';
                    $this->assertTrue(
                        AfipHelper::validateCuit($cuitWith0),
                        "CUIT {$cuitWith0} should be valid with remainder 0 and check digit 0"
                    );

                    // Adversarial attack: digits 1..9 with same base MUST be invalid
                    for ($badDigit = 1; $badDigit <= 9; $badDigit++) {
                        $badCuit = $base . $badDigit;
                        $this->assertFalse(
                            AfipHelper::validateCuit($badCuit),
                            "CUIT {$badCuit} with remainder 0 but check digit {$badDigit} must be invalid"
                        );
                    }

                    $testedCount++;
                    if ($testedCount >= 10) {
                        break 2;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(5, $testedCount, 'Should have tested multiple remainder 0 CUITs');
    }

    /**
     * CHALLENGE 1.2: Modulo 11 Remainder 1 Invalid Edge Case.
     * When sum % 11 == 1, 11 - 1 = 10 (not representable as a single digit).
     * The algorithm MUST return false for ALL digits 0..9.
     */
    public function test_cuit_modulo_11_remainder_1_strictly_invalid_for_all_check_digits(): void
    {
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $testedCount = 0;

        foreach (['20', '27', '30', '33'] as $prefix) {
            for ($doc = 20000000; $doc <= 20000300; $doc++) {
                $base = $prefix . (string) $doc;
                $sum = 0;
                for ($i = 0; $i < 10; $i++) {
                    $sum += ((int) $base[$i]) * $multipliers[$i];
                }

                if ($sum % 11 === 1) {
                    // Test all possible digits 0..9
                    for ($d = 0; $d <= 9; $d++) {
                        $cuit = $base . $d;
                        $this->assertFalse(
                            AfipHelper::validateCuit($cuit),
                            "CUIT {$cuit} with remainder 1 must be strictly invalid regardless of digit"
                        );
                    }

                    $testedCount++;
                    if ($testedCount >= 5) {
                        break 2;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, $testedCount, 'Should have tested multiple remainder 1 CUITs');
    }

    /**
     * CHALLENGE 1.3: Real Argentine Corporate and Entity CUITs.
     */
    public function test_cuit_modulo_11_authoritative_corporate_and_commercial_cuits(): void
    {
        $corporateCuits = [
            'AFIP'                   => '33-69345023-9',
            'Banco de la Nación'     => '30-50001091-2',
            'YPF S.A.'               => '30-54668997-9',
            'Telecom Argentina S.A.' => '30-63945373-8',
            'MercadoLibre S.R.L.'    => '30-70308853-4',
            'Coto C.I.C.S.A.'        => '30-54808315-6',
            'Arcor S.A.I.C.'         => '30-50279317-5',
            'Molinos Río de la Plata'=> '30-50085862-8',
            'Pan American Energy'    => '30-57487054-9',
        ];

        foreach ($corporateCuits as $entity => $cuit) {
            // Unformatted and formatted
            $this->assertTrue(AfipHelper::validateCuit($cuit), "Failed for {$entity} formatted: {$cuit}");
            $raw = preg_replace('/\D/', '', $cuit);
            $this->assertTrue(AfipHelper::validateCuit($raw), "Failed for {$entity} raw: {$raw}");
            // With spaces
            $this->assertTrue(AfipHelper::validateCuit("  {$cuit}  "), "Failed for {$entity} with whitespace");
            // Integer representation if fits
            $this->assertTrue(AfipHelper::validateCuit((int) $raw), "Failed for {$entity} as integer");
        }
    }

    /**
     * CHALLENGE 1.4: Bad Prefixes (Rejection of Invalid AFIP Prefix Entities).
     */
    public function test_cuit_modulo_11_rejects_all_invalid_prefixes(): void
    {
        $invalidPrefixes = [
            '00', '01', '10', '11', '12', '19', '21', '22',
            '25', '26', '28', '29', '31', '32', '35', '36',
            '40', '50', '60', '70', '80', '90', '99',
        ];

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

        foreach ($invalidPrefixes as $badPrefix) {
            $base = $badPrefix . '12345678';
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $sum += ((int) $base[$i]) * $multipliers[$i];
            }
            $rem = $sum % 11;
            $digit = ($rem === 0) ? 0 : (($rem === 1) ? 9 : 11 - $rem);
            $cuit = $base . $digit;

            $this->assertFalse(
                AfipHelper::validateCuit($cuit),
                "CUIT with invalid prefix '{$badPrefix}' must be rejected even if checksum matches"
            );
        }
    }

    /**
     * CHALLENGE 1.5: Non-digit Characters, Malformed Lengths, and Types.
     */
    public function test_cuit_modulo_11_dirty_inputs_and_edge_types(): void
    {
        $this->assertFalse(AfipHelper::validateCuit(null));
        $this->assertFalse(AfipHelper::validateCuit(''));
        $this->assertFalse(AfipHelper::validateCuit('   '));
        $this->assertFalse(AfipHelper::validateCuit('30-50001091'));      // 10 digits
        $this->assertFalse(AfipHelper::validateCuit('30-50001091-2-9'));  // 12 digits
        $this->assertFalse(AfipHelper::validateCuit('30-50001091-A'));    // Letter check digit (10 digits left)
        $this->assertFalse(AfipHelper::validateCuit('30-5000109A-2'));    // Letter in body (10 digits left)
        $this->assertFalse(AfipHelper::validateCuit('ABCDEFGHIJK'));      // All letters
        $this->assertFalse(AfipHelper::validateCuit('!@#$%^&*()_+'));     // Symbols
        $this->assertFalse(AfipHelper::validateCuit(-1));                 // Small negative integer
        $this->assertFalse(AfipHelper::validateCuit(-3050001091));        // Negative 10-digit number
        $this->assertFalse(AfipHelper::validateCuit(0));
    }

    /**
     * CHALLENGE 1.6: Single-digit and Transposition Error Detection.
     * Modulo 11 is mathematically designed to catch all single-digit errors and most transpositions.
     */
    public function test_cuit_modulo_11_detects_mutations_and_transpositions(): void
    {
        $validCuit = '30500010912'; // Banco Nación
        $this->assertTrue(AfipHelper::validateCuit($validCuit));

        // 1. Single digit mutation across all 11 positions
        for ($pos = 0; $pos < 11; $pos++) {
            $origDigit = (int) $validCuit[$pos];
            for ($newDigit = 0; $newDigit <= 9; $newDigit++) {
                if ($newDigit === $origDigit) {
                    continue;
                }
                $mutated = $validCuit;
                $mutated[$pos] = (string) $newDigit;
                $this->assertFalse(
                    AfipHelper::validateCuit($mutated),
                    "Mutated CUIT at pos {$pos} from {$origDigit} to {$newDigit} ({$mutated}) should be invalid"
                );
            }
        }

        // 2. Transposition of adjacent digits
        $validMercadoLibre = '30703088534';
        for ($pos = 2; $pos < 9; $pos++) {
            if ($validMercadoLibre[$pos] === $validMercadoLibre[$pos + 1]) {
                continue;
            }
            $swapped = $validMercadoLibre;
            $tmp = $swapped[$pos];
            $swapped[$pos] = $swapped[$pos + 1];
            $swapped[$pos + 1] = $tmp;

            $this->assertFalse(
                AfipHelper::validateCuit($swapped),
                "Transposed CUIT at pos {$pos}-" . ($pos + 1) . " ({$swapped}) should be invalid"
            );
        }
    }

    // =========================================================================
    // SECTION 2: IVA CALCULATIONS AND MATHEMATICAL INVARIANTS
    // =========================================================================

    /**
     * CHALLENGE 2.1: Single-Item Fractional Cent Invariants.
     * For any subtotal $gross, net + iva == gross down to the exact cent.
     */
    public function test_single_item_fractional_cent_invariants(): void
    {
        $testPrices = [
            0.01, 0.02, 0.03, 0.05, 0.07, 0.09, 0.10, 0.15, 0.33, 0.50, 0.99,
            1.00, 1.01, 9.99, 10.00, 10.50, 19.99, 33.33, 49.99, 99.99, 100.00,
            123.45, 333.33, 777.77, 999.99, 1234.56, 9999.99, 12345.67, 340000.00,
        ];

        $rates = [21.00, 10.50, 0.00, 27.00, 5.00, 2.50];

        foreach ($rates as $rate) {
            foreach ($testPrices as $price) {
                $calc = AfipHelper::calculateNetAndIva($price, $rate);

                $this->assertArrayHasKey('net', $calc);
                $this->assertArrayHasKey('iva', $calc);

                $sum = round($calc['net'] + $calc['iva'], 2);
                $this->assertSame(
                    $price,
                    $sum,
                    "Math invariant failed for price \${$price} at rate {$rate}%: net={$calc['net']}, iva={$calc['iva']}, sum={$sum}"
                );

                if ($rate === 0.00) {
                    $this->assertSame(0.00, $calc['iva']);
                    $this->assertSame($price, $calc['net']);
                }

                $this->assertGreaterThanOrEqual(0.00, $calc['net']);
                $this->assertGreaterThanOrEqual(0.00, $calc['iva']);
            }
        }
    }

    /**
     * CHALLENGE 2.2: Multi-Item Baskets with Mixed VAT Rates (21%, 10.5%, 0%).
     * Stress-test authorization payload:
     * ImpTotal == ImpNeto + ImpIVA invariant MUST hold exactly.
     */
    public function test_multi_item_basket_with_mixed_aliquots_preserves_invariant(): void
    {
        $this->mockAfipSuccess(cbteNro: 200, cae: '12345678901234');

        $items = [
            ['price' => 121.00, 'qty' => 1, 'iva_rate' => 21.00],   // Net: 100, IVA: 21
            ['price' => 221.00, 'qty' => 1, 'iva_rate' => 10.50],   // 10.5% rate
            ['price' => 50.00,  'qty' => 1, 'iva_rate' => 0.00],    // Exento 0%
            ['price' => 19.99,  'qty' => 3, 'iva_rate' => 21.00],   // Fractional qty subtotal 59.97
            ['price' => 33.33,  'qty' => 2, 'iva_rate' => 10.50],   // Subtotal 66.66
            ['price' => 100.00, 'qty' => 1, 'iva_rate' => 27.00],   // 27% rate
        ];

        $sale = $this->createSaleWithItems($items);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6, // Factura B
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->firstOrFail();

        $net = (float) $invoice->net_amount;
        $iva = (float) $invoice->iva_amount;
        $total = (float) $invoice->total_amount;

        // Invariant check
        $this->assertEquals(
            $total,
            round($net + $iva, 2),
            "ImpTotal ({$total}) must equal ImpNeto ({$net}) + ImpIVA ({$iva})"
        );

        // Verify breakdown items sum up to net and iva
        $breakdown = $invoice->iva_breakdown;
        $this->assertNotEmpty($breakdown);

        $sumBreakdownNet = 0.0;
        $sumBreakdownIva = 0.0;
        foreach ($breakdown as $item) {
            $sumBreakdownNet += (float) $item['BaseImp'];
            $sumBreakdownIva += (float) $item['Importe'];
        }

        $this->assertEquals($net, round($sumBreakdownNet, 2), 'Sum of BaseImp must match net_amount');
        $this->assertEquals($iva, round($sumBreakdownIva, 2), 'Sum of Importe must match iva_amount');
    }

    /**
     * CHALLENGE 2.3: Monte Carlo Stress Test — 200 Randomized Complex Baskets.
     * Verifies that the math invariant holds across unpredictable combinations.
     */
    public function test_monte_carlo_randomized_baskets_preserve_invariant(): void
    {
        $wsfeService = new AfipWsfeService();

        for ($trial = 0; $trial < 200; $trial++) {
            $numItems = rand(1, 15);
            $itemsData = [];
            $totalGross = 0.0;

            for ($i = 0; $i < $numItems; $i++) {
                // Random gross price with cents
                $price = rand(1, 50000) / 100.0;
                $rates = [21.00, 10.50, 0.00, 27.00, 5.00, 2.50];
                $rate = $rates[array_rand($rates)];
                $itemsData[] = [
                    'price'    => $price,
                    'qty'      => 1,
                    'iva_rate' => $rate,
                    'subtotal' => $price,
                ];
                $totalGross += $price;
            }
            $totalGross = round($totalGross, 2);

            // Group by aliquot as AfipWsfeService does
            $groupedByAliquot = [];
            foreach ($itemsData as $item) {
                $rate = $item['iva_rate'];
                $aliquotId = AfipHelper::getAliquotId($rate);
                $subtotal = $item['subtotal'];
                if (! isset($groupedByAliquot[$aliquotId])) {
                    $groupedByAliquot[$aliquotId] = ['rate' => $rate, 'subtotal' => 0.0];
                }
                $groupedByAliquot[$aliquotId]['subtotal'] += $subtotal;
            }

            $totalNet = 0.0;
            $totalIva = 0.0;
            $aliquotResults = [];
            foreach ($groupedByAliquot as $id => $data) {
                $calc = AfipHelper::calculateNetAndIva((float) $data['subtotal'], (float) $data['rate']);
                $totalNet += $calc['net'];
                $totalIva += $calc['iva'];
                $aliquotResults[$id] = [
                    'Id'      => $id,
                    'BaseImp' => $calc['net'],
                    'Importe' => $calc['iva'],
                ];
            }

            $netAmount = round($totalNet, 2);
            $ivaAmount = round($totalIva, 2);

            // AFIP exact cent adjustment
            $diff = round($totalGross - ($netAmount + $ivaAmount), 2);
            if ($diff !== 0.00) {
                $netAmount = round($totalGross - $ivaAmount, 2);
                $firstKey = array_key_first($aliquotResults);
                if ($firstKey !== null) {
                    $aliquotResults[$firstKey]['BaseImp'] = round($aliquotResults[$firstKey]['BaseImp'] + $diff, 2);
                }
            }

            // Math invariant check
            $this->assertEquals(
                $totalGross,
                round($netAmount + $ivaAmount, 2),
                "Monte Carlo Trial #{$trial} failed: total={$totalGross}, net={$netAmount}, iva={$ivaAmount}, diff={$diff}"
            );

            // Aliquot breakdown consistency
            $sumBase = 0.0;
            $sumIva = 0.0;
            foreach ($aliquotResults as $al) {
                $sumBase += $al['BaseImp'];
                $sumIva += $al['Importe'];
            }
            $this->assertEquals($netAmount, round($sumBase, 2), "Trial #{$trial}: Base sum mismatch");
            $this->assertEquals($ivaAmount, round($sumIva, 2), "Trial #{$trial}: IVA sum mismatch");
        }
    }

    // =========================================================================
    // SECTION 3: FACTURA C STRICT MONOTRIBUTO RULES
    // =========================================================================

    /**
     * CHALLENGE 3.1: Factura C strictly omits <Iva> and sets ImpIVA = 0.00 in SOAP payload.
     * Even if sale items have products with 21% or 10.5% tax configured.
     */
    public function test_factura_c_strictly_omits_iva_node_and_forces_zero_iva(): void
    {
        $interceptedXml = null;

        $this->mockAfipSuccess(cbteNro: 50, cae: '55667788990011', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        // Items with different VAT rates
        $items = [
            ['price' => 1000.00, 'qty' => 1, 'iva_rate' => 21.00],
            ['price' => 500.00,  'qty' => 1, 'iva_rate' => 10.50],
            ['price' => 250.00,  'qty' => 1, 'iva_rate' => 0.00],
        ];

        $sale = $this->createSaleWithItems($items);
        $this->assertEquals(1750.00, (float) $sale->total);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 11, // Factura C
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'C')
            ->assertJsonPath('invoice.voucher_type', 11)
            ->assertJsonPath('invoice.iva_amount', '0.00')
            ->assertJsonPath('invoice.net_amount', '1750.00')
            ->assertJsonPath('invoice.total_amount', '1750.00');

        $this->assertNotNull($interceptedXml, 'FECAESolicitar request must have been captured');

        // MONOTRIBUTO LEGAL INVARIANTS IN SOAP PAYLOAD:
        // 1. <Iva> node MUST NOT exist
        $this->assertStringNotContainsString('<Iva>', $interceptedXml, 'Factura C must NOT contain <Iva> node');
        $this->assertStringNotContainsString('<AlicIva>', $interceptedXml, 'Factura C must NOT contain <AlicIva> node');

        // 2. <ImpIVA> must be strictly 0.00
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $interceptedXml);

        // 3. <ImpNeto> must equal <ImpTotal>
        $this->assertStringContainsString('<ImpNeto>1750.00</ImpNeto>', $interceptedXml);
        $this->assertStringContainsString('<ImpTotal>1750.00</ImpTotal>', $interceptedXml);

        // 4. <CbteTipo> must be 11
        $this->assertStringContainsString('<CbteTipo>11</CbteTipo>', $interceptedXml);

        // Database record verification
        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->firstOrFail();
        $this->assertEquals(0.00, (float) $invoice->iva_amount);
        $this->assertEquals(1750.00, (float) $invoice->net_amount);
        $this->assertEquals(1750.00, (float) $invoice->total_amount);
        $this->assertEmpty($invoice->iva_breakdown, 'iva_breakdown must be empty for Factura C');
    }

    /**
     * CHALLENGE 3.2: Factura C via voucher_letter 'c' or 'C'.
     */
    public function test_factura_c_via_voucher_letter_parameter(): void
    {
        $this->mockAfipSuccess(cbteNro: 51, cae: '55667788990022');

        $sale = $this->createSaleWithItems([
            ['price' => 300.00, 'qty' => 1, 'iva_rate' => 21.00],
        ]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_letter' => 'c',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'C')
            ->assertJsonPath('invoice.voucher_type', 11)
            ->assertJsonPath('invoice.iva_amount', '0.00')
            ->assertJsonPath('invoice.net_amount', '300.00');
    }

    // =========================================================================
    // SECTION 4: CONTINGENCY AND AUTHORIZATION MATRIX ATTACKS
    // =========================================================================

    /**
     * CHALLENGE 4.1: Factura A requires valid CUIT; anonymous DocTipo 99 is blocked.
     */
    public function test_factura_a_rejects_anonymous_or_non_cuit_receivers(): void
    {
        $sale = $this->createSaleWithItems([['price' => 1210.00, 'iva_rate' => 21.00]]);

        // Attempt 1: Factura A without CUIT
        $res1 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 99, // Consumidor Final
            'doc_number'   => '0',
        ]);

        $res1->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');

        // Attempt 2: Factura A with DNI instead of CUIT
        $res2 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 96,
            'doc_number'   => '35123456',
        ]);

        $res2->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * CHALLENGE 4.2: Anonymous Consumidor Final exceeding threshold ($340,000) is blocked.
     */
    public function test_anonymous_consumidor_final_exceeding_threshold_is_blocked(): void
    {
        $sale = $this->createSaleWithItems([['price' => 350000.00, 'iva_rate' => 21.00]]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
            'doc_type'     => 99,
            'doc_number'   => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');
        $this->assertStringContainsString('límite de consumidor final anónimo', $response->json('message'));
    }

    /**
     * CHALLENGE 4.3: Contingency mode triggers HTTP 504 and status 'pending' on cURL timeout.
     */
    public function test_contingency_triggers_504_and_pending_status_on_network_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out after 12000 milliseconds');
        });

        $sale = $this->createSaleWithItems([['price' => 1000.00, 'iva_rate' => 21.00]]);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'pending');

        $this->assertEquals('pending', $sale->fresh()->invoice_status);
    }

    /**
     * CHALLENGE 4.4: Invoicing already voided sale is strictly blocked with 422.
     */
    public function test_cannot_invoice_voided_sale(): void
    {
        $sale = $this->createSaleWithItems([['price' => 500.00, 'iva_rate' => 21.00]]);
        $sale->update(['status' => 'voided']);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No es posible emitir factura electrónica para una venta anulada.');
    }

    /**
     * CHALLENGE 4.5: Idempotency — issuing invoice for already authorized sale returns existing without AFIP re-call.
     */
    public function test_idempotent_invoice_issuance(): void
    {
        $this->mockAfipSuccess(cbteNro: 10, cae: '77889900112233');

        $sale = $this->createSaleWithItems([['price' => 1210.00, 'iva_rate' => 21.00]]);

        // First call
        $res1 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);
        $res1->assertStatus(200);

        // Second call should return existing invoice
        $res2 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);
        $res2->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'La venta ya posee una factura electrónica autorizada previamente.')
            ->assertJsonPath('invoice.cae', '77889900112233');
    }

    /**
     * CHALLENGE 4.6: Exact SOAP Envelope XML Inspection for Multi-Aliquot Basket (21%, 10.5%, 0%).
     * Asserts that in the outgoing SOAP payload:
     * 1. ImpTotal == ImpNeto + ImpIVA
     * 2. Sum(BaseImp) in <Iva> == ImpNeto
     * 3. Sum(Importe) in <Iva> == ImpIVA
     * 4. Aliquot IDs match AFIP spec (5, 4, 3)
     */
    public function test_soap_envelope_xml_structure_and_mathematical_consistency_on_complex_basket(): void
    {
        $interceptedXml = null;

        $this->mockAfipSuccess(cbteNro: 150, cae: '11223344556677', requestInspector: function (HttpClientRequest $req) use (&$interceptedXml) {
            $body = $req->body();
            if (str_contains($body, 'FECAESolicitar')) {
                $interceptedXml = $body;
            }
        });

        $items = [
            ['price' => 1210.00, 'qty' => 1, 'iva_rate' => 21.00], // 1000 net, 210 iva
            ['price' => 1105.00, 'qty' => 1, 'iva_rate' => 10.50], // 1000 net, 105 iva
            ['price' => 500.00,  'qty' => 1, 'iva_rate' => 0.00],  // 500 net, 0 iva
            ['price' => 19.99,   'qty' => 1, 'iva_rate' => 21.00],  // Fractional
            ['price' => 33.33,   'qty' => 1, 'iva_rate' => 10.50],  // Fractional
        ];

        $sale = $this->createSaleWithItems($items);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($interceptedXml);

        // Parse intercepted XML
        preg_match('/<ImpTotal>([\d\.]+)<\/ImpTotal>/', $interceptedXml, $mTotal);
        preg_match('/<ImpNeto>([\d\.]+)<\/ImpNeto>/', $interceptedXml, $mNeto);
        preg_match('/<ImpIVA>([\d\.]+)<\/ImpIVA>/', $interceptedXml, $mIva);

        $xmlTotal = (float) ($mTotal[1] ?? 0.0);
        $xmlNeto = (float) ($mNeto[1] ?? 0.0);
        $xmlIva = (float) ($mIva[1] ?? 0.0);

        // 1. Math invariant in SOAP XML
        $this->assertEquals(
            $xmlTotal,
            round($xmlNeto + $xmlIva, 2),
            "SOAP XML invariant failed: ImpTotal ({$xmlTotal}) != ImpNeto ({$xmlNeto}) + ImpIVA ({$xmlIva})"
        );

        // 2. Parse <AlicIva> nodes
        preg_match_all('/<AlicIva>(.*?)<\/AlicIva>/s', $interceptedXml, $aliquotNodes);
        $this->assertNotEmpty($aliquotNodes[1], 'Must contain <AlicIva> entries');

        $sumBaseImp = 0.0;
        $sumImporte = 0.0;
        $foundIds = [];

        foreach ($aliquotNodes[1] as $nodeXml) {
            preg_match('/<Id>(\d+)<\/Id>/', $nodeXml, $mId);
            preg_match('/<BaseImp>([\d\.]+)<\/BaseImp>/', $nodeXml, $mBase);
            preg_match('/<Importe>([\d\.]+)<\/Importe>/', $nodeXml, $mImp);

            $id = (int) ($mId[1] ?? 0);
            $base = (float) ($mBase[1] ?? 0.0);
            $imp = (float) ($mImp[1] ?? 0.0);

            $foundIds[] = $id;
            $sumBaseImp += $base;
            $sumImporte += $imp;
        }

        // Sum(BaseImp) == ImpNeto
        $this->assertEquals($xmlNeto, round($sumBaseImp, 2), 'Sum of BaseImp in <Iva> must equal ImpNeto');

        // Sum(Importe) == ImpIVA
        $this->assertEquals($xmlIva, round($sumImporte, 2), 'Sum of Importe in <Iva> must equal ImpIVA');

        // Verify aliquot IDs 5 (21%), 4 (10.5%), and 3 (0%) were sent
        $this->assertContains(AfipHelper::ALIQUOT_21_PERCENT, $foundIds);
        $this->assertContains(AfipHelper::ALIQUOT_10_5_PERCENT, $foundIds);
        $this->assertContains(AfipHelper::ALIQUOT_0_PERCENT, $foundIds);
    }

    /**
     * CHALLENGE 4.7: DNI and QR RG 4892 Canonical Field Structure.
     */
    public function test_dni_and_qr_canonical_fields_integrity(): void
    {
        $this->mockAfipSuccess(cbteNro: 77, cae: '98765432101234');

        $customer = Customer::create([
            'name'            => 'Juan Pérez',
            'document_type'   => 96, // DNI
            'document_number' => '32123456',
            'tax_condition'   => 'consumidor_final',
        ]);

        $sale = $this->createSaleWithItems([['price' => 500.00, 'iva_rate' => 21.00]], $customer);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
            'doc_type'     => 96,
            'doc_number'   => '32123456',
        ]);

        $response->assertStatus(200);

        $qrData = $response->json('invoice.qr_data');
        $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $qrData);

        $b64 = str_replace('https://www.afip.gob.ar/fe/qr/?p=', '', $qrData);
        $decoded = json_decode((string) base64_decode($b64), true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['ver']);
        $this->assertSame(1, $decoded['ptoVta']);
        $this->assertSame(6, $decoded['tipoCmp']);
        $this->assertSame(77, $decoded['nroCmp']);
        $this->assertEquals(500.00, (float) $decoded['importe']);
        $this->assertSame(96, $decoded['tipoDocRec']);
        $this->assertSame(32123456, $decoded['nroDocRec']);
        $this->assertSame('E', $decoded['tipoCodAut']);
        $this->assertSame(98765432101234, $decoded['codAut']);
    }
}
