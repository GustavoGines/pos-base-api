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
use App\Services\Afip\AfipWsaaService;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AfipAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected string $testCuit = '30712345678';
    protected ?string $tempCertPath = null;
    protected ?string $tempKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['role' => 'admin']);

        // Settings de negocio para AFIP
        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $this->testCuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);

        // Generar certificado x509 y clave privada RSA válidos en disco para pruebas de firma OpenSSL
        $this->generateSelfSignedCertAndKey();
    }

    protected function tearDown(): void
    {
        if ($this->tempCertPath && file_exists($this->tempCertPath)) {
            @unlink($this->tempCertPath);
        }
        if ($this->tempKeyPath && file_exists($this->tempKeyPath)) {
            @unlink($this->tempKeyPath);
        }

        parent::tearDown();
    }

    /**
     * Genera un par de certificado X.509 y clave privada RSA temporales para testing de OpenSSL CMS.
     */
    protected function generateSelfSignedCertAndKey(): void
    {
        $wsaa = new AfipWsaaService();
        $wsaa->ensureOpenSslConfig();

        $cnfPath = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        $configArgs = file_exists($cnfPath) ? ['config' => $cnfPath] : [];

        $dn = [
            'countryName'            => 'AR',
            'stateOrProvinceName'    => 'Buenos Aires',
            'localityName'           => 'CABA',
            'organizationName'       => 'Sistema POS Test Org',
            'organizationalUnitName' => 'QA Adversarial',
            'commonName'             => 'test-afip-pos',
        ];

        $privKeyArgs = array_merge($configArgs, [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privKey = openssl_pkey_new($privKeyArgs);
        if ($privKey === false) {
            throw new RuntimeException('Error generating private key: ' . openssl_error_string());
        }

        $csrArgs = array_merge($configArgs, ['digest_alg' => 'sha256']);
        $csr = openssl_csr_new($dn, $privKey, $csrArgs);
        if ($csr === false) {
            throw new RuntimeException('Error generating CSR: ' . openssl_error_string());
        }

        $x509 = openssl_csr_sign($csr, null, $privKey, 365, $csrArgs);
        if ($x509 === false) {
            throw new RuntimeException('Error signing CSR: ' . openssl_error_string());
        }

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privKey, $keyOut, null, $configArgs);

        $tempDir = sys_get_temp_dir();
        $this->tempCertPath = tempnam($tempDir, 'afip_cert_') . '.crt';
        $this->tempKeyPath = tempnam($tempDir, 'afip_key_') . '.key';

        file_put_contents($this->tempCertPath, $certOut);
        file_put_contents($this->tempKeyPath, $keyOut);

        // Colocar también en storage/app/private/afip/{cuit}/cert.crt y cert.key
        // para que resolveCertPath() y resolveKeyPath() los encuentren automáticamente
        $afipDir = storage_path("app/private/afip/{$this->testCuit}");
        if (! is_dir($afipDir)) {
            @mkdir($afipDir, 0777, true);
        }
        copy($this->tempCertPath, "{$afipDir}/cert.crt");
        copy($this->tempKeyPath, "{$afipDir}/cert.key");
    }

    /**
     * Helper para crear una venta con items de prueba.
     */
    protected function createTestSale(float $total = 1210.00, ?Customer $customer = null): Sale
    {
        $shift = $this->crearTurnoAbierto(user: $this->adminUser);

        $product = Product::create([
            'name'          => 'Producto Adversarial Test',
            'internal_code' => 'ADV-01',
            'cost_price'    => 500.00,
            'selling_price' => $total,
            'iva_rate'      => 21.00,
            'stock'         => 100,
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
            'net_amount'      => round($total / 1.21, 2),
            'iva_amount'      => round($total - ($total / 1.21), 2),
        ]);

        return $sale;
    }

    // =========================================================================
    // 1. ADVERSARIAL TESTS: WSAA TA CACHING & TOKEN RESILIENCE
    // =========================================================================

    /**
     * Test 1: TA caching — assert subsequent calls use cache without re-invoking loginCms.
     */
    public function test_wsaa_caching_subsequent_calls_hit_cache_without_reinvoking_logincms(): void
    {
        $wsaaService = new AfipWsaaService();
        $cacheKey = "afip_ta_{$this->testCuit}_wsfe_testing";
        Cache::forget($cacheKey);

        $mockWsaaResponse = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <loginCmsResponse xmlns="http://wsaa.view.sua.dvad.ar.gov.afip.biz/">
      <loginCmsReturn>&lt;loginTicketResponse version="1.0"&gt;
        &lt;header&gt;
          &lt;source&gt;CN=wsaahomo, O=AFIP&lt;/source&gt;
          &lt;destination&gt;C=AR, O=Test&lt;/destination&gt;
          &lt;uniqueId&gt;99887766&lt;/uniqueId&gt;
          &lt;generationTime&gt;2026-10-07T04:00:00Z&lt;/generationTime&gt;
          &lt;expirationTime&gt;2026-10-07T14:00:00Z&lt;/expirationTime&gt;
        &lt;/header&gt;
        &lt;credentials&gt;
          &lt;token&gt;TOKEN_AUTH_CACHED_M1_VERIFICATION&lt;/token&gt;
          &lt;sign&gt;SIGN_AUTH_CACHED_M1_VERIFICATION&lt;/sign&gt;
        &lt;/credentials&gt;
      &lt;/loginTicketResponse&gt;</loginCmsReturn>
    </loginCmsResponse>
  </soapenv:Body>
</soapenv:Envelope>';

        Http::fake([
            AfipWsaaService::URL_WSAA_HOMO => Http::response($mockWsaaResponse, 200, ['Content-Type' => 'text/xml']),
        ]);

        // Primera llamada: Debe invocar WSAA via HTTP
        $firstTicket = $wsaaService->getAccessTicket('wsfe');
        $this->assertEquals('TOKEN_AUTH_CACHED_M1_VERIFICATION', $firstTicket['token']);
        $this->assertEquals('SIGN_AUTH_CACHED_M1_VERIFICATION', $firstTicket['sign']);
        Http::assertSentCount(1);

        // Verificar que quedó cacheado en Laravel Cache
        $this->assertTrue(Cache::has($cacheKey));
        $cachedData = Cache::get($cacheKey);
        $this->assertEquals('TOKEN_AUTH_CACHED_M1_VERIFICATION', $cachedData['token']);

        // Segunda llamada: DEBE consumir caché SIN re-invocar loginCms vía HTTP
        $secondTicket = $wsaaService->getAccessTicket('wsfe');
        $this->assertEquals('TOKEN_AUTH_CACHED_M1_VERIFICATION', $secondTicket['token']);
        $this->assertEquals('SIGN_AUTH_CACHED_M1_VERIFICATION', $secondTicket['sign']);

        // Assert CRÍTICO: El contador HTTP permanece estrictamente en 1 (0 llamadas adicionales)
        Http::assertSentCount(1);

        // Tercera llamada: Sigue en caché
        $thirdTicket = $wsaaService->getAccessTicket('wsfe');
        $this->assertEquals($firstTicket, $thirdTicket);
        Http::assertSentCount(1);
    }

    /**
     * Test 2: Invalidador clearCache fuerza una re-autenticación limpia.
     */
    public function test_wsaa_clear_cache_forces_reauthentication(): void
    {
        $wsaaService = new AfipWsaaService();
        $cacheKey = "afip_ta_{$this->testCuit}_wsfe_testing";

        // Pre-cargar caché
        Cache::put($cacheKey, [
            'token' => 'OLD_TOKEN',
            'sign'  => 'OLD_SIGN',
        ], 36000);

        $this->assertTrue(Cache::has($cacheKey));

        // Limpiar caché
        $wsaaService->clearCache($this->testCuit, 'wsfe', 'testing');
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test 3: Caché parcial o corrupta (sin token o sign) no bloquea y gatilla refresh limpio.
     */
    public function test_wsaa_corrupted_or_partial_cache_triggers_fresh_authentication(): void
    {
        $wsaaService = new AfipWsaaService();
        $cacheKey = "afip_ta_{$this->testCuit}_wsfe_testing";

        // Simular caché corrupta
        Cache::put($cacheKey, [
            'token' => '',
            'sign'  => null,
        ], 36000);

        Http::fake([
            AfipWsaaService::URL_WSAA_HOMO => Http::response('<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <loginCmsResponse xmlns="http://wsaa.view.sua.dvad.ar.gov.afip.biz/">
      <loginCmsReturn>&lt;loginTicketResponse version="1.0"&gt;
        &lt;credentials&gt;
          &lt;token&gt;FRESH_RECOVERED_TOKEN&lt;/token&gt;
          &lt;sign&gt;FRESH_RECOVERED_SIGN&lt;/sign&gt;
        &lt;/credentials&gt;
      &lt;/loginTicketResponse&gt;</loginCmsReturn>
    </loginCmsResponse>
  </soapenv:Body>
</soapenv:Envelope>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $ticket = $wsaaService->getAccessTicket('wsfe');
        $this->assertEquals('FRESH_RECOVERED_TOKEN', $ticket['token']);
        $this->assertEquals('FRESH_RECOVERED_SIGN', $ticket['sign']);
        Http::assertSentCount(1);
    }

    // =========================================================================
    // 2. ADVERSARIAL TESTS: OPENSSL CMS SIGNING & DER INSPECTION
    // =========================================================================

    /**
     * Test 4: Firma CMS con OpenSSL — inspección de DER y ausencia total de boundaries multipart/mime.
     */
    public function test_openssl_cms_signing_der_inspection_and_absence_of_mime_multipart_boundaries(): void
    {
        $wsaaService = new AfipWsaaService();
        $traXml = $wsaaService->createTraXml('wsfe');

        $this->assertNotEmpty($traXml);
        $this->assertStringContainsString('<loginTicketRequest', $traXml);
        $this->assertStringContainsString('<service>wsfe</service>', $traXml);

        // Firmar TRA
        $cmsBase64 = $wsaaService->signTra($traXml, $this->tempCertPath, $this->tempKeyPath);

        // 1. Validar que la cadena retornada no esté vacía
        $this->assertNotEmpty($cmsBase64);

        // 2. Validar ausencia absoluta de encabezados y boundaries MIME
        $this->assertStringNotContainsString('MIME-Version', $cmsBase64);
        $this->assertStringNotContainsString('Content-Type', $cmsBase64);
        $this->assertStringNotContainsString('multipart/signed', $cmsBase64);
        $this->assertStringNotContainsString('boundary', $cmsBase64);
        $this->assertStringNotContainsString('-----BEGIN', $cmsBase64);
        $this->assertStringNotContainsString('-----END', $cmsBase64);
        $this->assertStringNotContainsString("\r", $cmsBase64);
        $this->assertStringNotContainsString("\n", $cmsBase64);

        // 3. Validar que es Base64 estrictamente decodificable
        $derBytes = base64_decode($cmsBase64, true);
        $this->assertNotFalse($derBytes, 'El resultado de signTra() debe ser Base64 válido');

        // 4. Inspección profunda de ASN.1 DER:
        // En codificación ASN.1 DER, una estructura PKCS#7 SignedData (ContentInfo) SIEMPRE inicia con el tag SEQUENCE (0x30 / byte decimal 48).
        $firstByte = ord($derBytes[0]);
        $this->assertSame(0x30, $firstByte, 'El byte inicial del DER firmado debe ser 0x30 (ASN.1 SEQUENCE)');
        $this->assertGreaterThan(500, strlen($derBytes), 'El tamaño de la estructura DER firmada con RSA-2048 debe ser > 500 bytes');
    }

    /**
     * Test 5: Fallo controlado si faltan archivos de clave o certificado.
     */
    public function test_openssl_cms_signing_fails_if_cert_or_key_missing(): void
    {
        $wsaaService = new AfipWsaaService();
        $traXml = $wsaaService->createTraXml('wsfe');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El archivo de certificado AFIP no existe');
        $wsaaService->signTra($traXml, 'c:/ruta/inexistente/cert.crt', $this->tempKeyPath);
    }

    // =========================================================================
    // 3. ADVERSARIAL TESTS: RG 4892 FISCAL QR DECODING & EXACT 13 FIELDS
    // =========================================================================

    /**
     * Test 6: Generación de QR RG 4892 — decodificar Base64 de qr_data URL y verificar los 13 campos canónicos exactos.
     */
    public function test_rg_4892_qr_generation_decodes_base64_and_verifies_all_13_canonical_fields_match_input_exactly(): void
    {
        $wsfeService = new AfipWsfeService();

        $inputParams = [
            'fecha'      => '2026-10-07',
            'cuit'       => 30712345678,
            'ptoVta'     => 5,
            'tipoCmp'    => 1, // Factura A
            'nroCmp'     => 9988,
            'importe'    => 24680.75,
            'tipoDocRec' => 80, // CUIT
            'nroDocRec'  => 30500010912,
            'codAut'     => 74382910492819, // CAE 14 dígitos
        ];

        $qrUrl = $wsfeService->buildQrUrl($inputParams);

        // 1. Debe iniciar con el prefijo oficial de AFIP
        $prefix = 'https://www.afip.gob.ar/fe/qr/?p=';
        $this->assertStringStartsWith($prefix, $qrUrl);

        // 2. Extraer parámetro Base64 y decodificar
        $base64Part = substr($qrUrl, strlen($prefix));
        $jsonString = base64_decode($base64Part, true);
        $this->assertNotFalse($jsonString, 'El parámetro ?p= no es Base64 válido');

        // 3. Parsear JSON
        $decoded = json_decode($jsonString, true);
        $this->assertIsArray($decoded);

        // 4. Verificar que contiene EXACTAMENTE 13 campos (ni más ni menos)
        $this->assertCount(13, $decoded, 'El payload RG 4892 debe contener exactamente 13 campos canónicos');

        // 5. Verificación campo por campo con tipos canónicos estrictos
        $this->assertSame(1, $decoded['ver'], 'Campo [1] ver debe ser int 1');
        $this->assertSame('2026-10-07', $decoded['fecha'], 'Campo [2] fecha debe coincidir exactamente');
        $this->assertSame(30712345678, $decoded['cuit'], 'Campo [3] cuit debe coincidir');
        $this->assertSame(5, $decoded['ptoVta'], 'Campo [4] ptoVta debe coincidir');
        $this->assertSame(1, $decoded['tipoCmp'], 'Campo [5] tipoCmp debe coincidir');
        $this->assertSame(9988, $decoded['nroCmp'], 'Campo [6] nroCmp debe coincidir');
        $this->assertSame(24680.75, $decoded['importe'], 'Campo [7] importe debe coincidir');
        $this->assertSame('PES', $decoded['moneda'], 'Campo [8] moneda debe ser estrictamente PES');
        $this->assertSame(1, $decoded['ctz'], 'Campo [9] ctz debe ser 1');
        $this->assertSame(80, $decoded['tipoDocRec'], 'Campo [10] tipoDocRec debe coincidir');
        $this->assertSame(30500010912, $decoded['nroDocRec'], 'Campo [11] nroDocRec debe coincidir');
        $this->assertSame('E', $decoded['tipoCodAut'], 'Campo [12] tipoCodAut debe ser E (CAE)');
        $this->assertSame(74382910492819, $decoded['codAut'], 'Campo [13] codAut debe coincidir con el CAE de 14 dígitos');
    }

    /**
     * Test 7: QR RG 4892 en límites adversos (Consumidor final anónimo con doc 0, importes millonarios, tipos mixtos).
     */
    public function test_rg_4892_qr_boundary_conditions(): void
    {
        $wsfeService = new AfipWsfeService();

        // Escenario: Consumidor final sin documento (doc 0) e importe millonario con 2 decimales
        $qrUrl = $wsfeService->buildQrUrl([
            'fecha'      => '2026-12-31',
            'cuit'       => 30712345678,
            'ptoVta'     => 1,
            'tipoCmp'    => 6, // Factura B
            'nroCmp'     => 1,
            'importe'    => 150000.50,
            'tipoDocRec' => 99, // Consumidor Final
            'nroDocRec'  => 0,
            'codAut'     => '98765432101234', // Entregado como string numérico
        ]);

        $base64 = str_replace('https://www.afip.gob.ar/fe/qr/?p=', '', $qrUrl);
        $payload = json_decode(base64_decode($base64), true);

        $this->assertSame(99, $payload['tipoDocRec']);
        $this->assertSame(0, $payload['nroDocRec']);
        $this->assertSame(150000.50, $payload['importe']);
        $this->assertSame(98765432101234, $payload['codAut']); // Debe haber sido normalizado a entero
    }

    // =========================================================================
    // 4. ADVERSARIAL TESTS: TIMEOUT, CONTINGENCY & HTTP 504 RESILIENCE
    // =========================================================================

    /**
     * Test 8: Timeout en FECompUltimoAutorizado retorna HTTP 504, activa contingencia y marca invoice_status = 'pending'.
     */
    public function test_timeout_in_fecompultimoautorizado_returns_504_and_sets_pending_status(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function (HttpClientRequest $request) {
            if (str_contains($request->body(), 'FECompUltimoAutorizado')) {
                throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds');
            }
            return Http::response('', 200);
        });

        $sale = $this->createTestSale(1500.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        // Verificaciones HTTP y JSON
        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'pending')
            ->assertJsonPath('message', 'Servicio de AFIP no disponible. Venta registrada en contingencia.');

        // Verificación estricta en Base de Datos: La venta DEBE quedar en pending (no bloqueada ni borrada)
        $this->assertEquals('pending', $sale->fresh()->invoice_status);
        $this->assertDatabaseMissing('electronic_invoices', [
            'sale_id' => $sale->id,
            'status'  => 'authorized',
        ]);
    }

    /**
     * Test 9: Timeout en FECAESolicitar retorna HTTP 504, activa contingencia y marca invoice_status = 'pending'.
     */
    public function test_timeout_in_fecaesolicitar_returns_504_and_sets_pending_status(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult><CbteNro>100</CbteNro></FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                throw new ConnectionException('Operation timed out after 12000 milliseconds with 0 bytes received');
            }

            return Http::response('', 200);
        });

        $sale = $this->createTestSale(2500.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'pending');

        $this->assertEquals('pending', $sale->fresh()->invoice_status);
    }

    /**
     * Test 10: Error HTTP 504 Gateway Timeout devuelto por los proxies de AFIP activa contingencia.
     */
    public function test_afip_reverse_proxy_504_gateway_timeout_triggers_contingency(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function () {
            return Http::response('<html><body><h1>504 Gateway Time-out</h1></body></html>', 504);
        });

        $sale = $this->createTestSale(1800.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'pending');

        $this->assertEquals('pending', $sale->fresh()->invoice_status);
    }

    /**
     * Test 11: Idempotencia estricta — Factura ya autorizada no vuelve a invocar a AFIP ni genera duplicados.
     */
    public function test_electronic_invoice_emission_is_idempotent_and_does_not_reinvoke_afip(): void
    {
        $sale = $this->createTestSale(3000.00);

        // Factura existente ya autorizada
        $existingInvoice = ElectronicInvoice::create([
            'sale_id'         => $sale->id,
            'voucher_type'    => 6,
            'voucher_letter'  => 'B',
            'point_of_sale'   => 1,
            'voucher_number'  => 555,
            'cae'             => '99887766554433',
            'cae_expiration'  => '2026-10-25',
            'doc_type'        => 99,
            'doc_number'      => '0',
            'net_amount'      => 2479.34,
            'iva_amount'      => 520.66,
            'total_amount'    => 3000.00,
            'qr_data'         => 'https://www.afip.gob.ar/fe/qr/?p=EXISTING',
            'status'          => 'authorized',
            'issued_at'       => now(),
        ]);

        $sale->update(['invoice_status' => 'invoiced']);

        // Mock Http que fallaría si se hace alguna llamada
        Http::fake(function () {
            $this->fail('No debe realizarse ninguna llamada HTTP si la factura ya está autorizada');
        });

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.cae', '99887766554433')
            ->assertJsonPath('invoice.voucher_number', 555);

        // Verificar que no se crearon registros duplicados en la base de datos
        $this->assertEquals(1, ElectronicInvoice::where('sale_id', $sale->id)->count());
    }

    /**
     * Test 12: Venta anulada no puede ser facturada fiscalmente (422).
     */
    public function test_voided_sale_cannot_be_invoiced(): void
    {
        $sale = $this->createTestSale(1000.00);
        $sale->update(['status' => 'voided']);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed')
            ->assertJsonPath('message', 'No es posible emitir factura electrónica para una venta anulada.');
    }

    // =========================================================================
    // 5. ADVERSARIAL TESTS: MONOTRIBUTO FACTURA C & MATH INVARIANTS
    // =========================================================================

    /**
     * Test 13: Factura C omite <Iva>, ImpIVA = 0.00, ImpNeto = ImpTotal en el request SOAP y en base de datos.
     */
    public function test_factura_c_soap_request_strictly_omits_iva_and_zeroes_imp_iva(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult><CbteNro>7</CbteNro></FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                // Assertions exhaustivas sobre el XML SOAP enviado
                $this->assertStringNotContainsString('<Iva>', $body, 'Factura C JAMÁS debe enviar el nodo <Iva>');
                $this->assertStringNotContainsString('<AlicIva>', $body);
                $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $body);
                $this->assertStringContainsString('<ImpNeto>7500.00</ImpNeto>', $body);
                $this->assertStringContainsString('<ImpTotal>7500.00</ImpTotal>', $body);
                $this->assertStringContainsString('<CbteTipo>11</CbteTipo>', $body);

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp><Resultado>A</Resultado></FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>A</Resultado>
            <CAE>65432198765432</CAE>
            <CAEFchVto>20261020</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        $sale = $this->createTestSale(7500.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 11, // Factura C
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.voucher_letter', 'C')
            ->assertJsonPath('invoice.voucher_number', 8)
            ->assertJsonPath('invoice.iva_amount', '0.00')
            ->assertJsonPath('invoice.net_amount', '7500.00');

        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertEquals(0.00, (float) $invoice->iva_amount);
        $this->assertEquals(7500.00, (float) $invoice->net_amount);
        $this->assertEquals(7500.00, (float) $invoice->total_amount);
        $this->assertEmpty($invoice->iva_breakdown);
    }

    /**
     * Test 14: Preservación estricta del invariante matemático con centavos impares (ej. $99.99 con 21% IVA).
     */
    public function test_strict_mathematical_invariant_on_odd_cents(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function (HttpClientRequest $request) {
            $body = $request->body();

            if (str_contains($body, 'FECompUltimoAutorizado')) {
                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECompUltimoAutorizadoResult><CbteNro>1</CbteNro></FECompUltimoAutorizadoResult>
    </FECompUltimoAutorizadoResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            if (str_contains($body, 'FECAESolicitar')) {
                // Extraer ImpTotal, ImpNeto, ImpIVA del request SOAP
                preg_match('/<ImpTotal>([\d\.]+)<\/ImpTotal>/', $body, $tot);
                preg_match('/<ImpNeto>([\d\.]+)<\/ImpNeto>/', $body, $net);
                preg_match('/<ImpIVA>([\d\.]+)<\/ImpIVA>/', $body, $iva);

                $impTotal = (float) $tot[1];
                $impNeto = (float) $net[1];
                $impIva = (float) $iva[1];

                // El invariante de AFIP ImpTotal = ImpNeto + ImpIVA debe cumplirse al centavo exacto
                $this->assertEquals($impTotal, round($impNeto + $impIva, 2));

                return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp><Resultado>A</Resultado></FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Resultado>A</Resultado>
            <CAE>12345678901234</CAE>
            <CAEFchVto>20261020</CAEFchVto>
          </FECAEDetResponse>
        </FeDetResp>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
            }

            return Http::response('', 200);
        });

        // Venta con centavos impares propensos a errores de redondeo
        $sale = $this->createTestSale(99.99);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(200);
        $invoice = ElectronicInvoice::where('sale_id', $sale->id)->first();
        $this->assertEquals(99.99, round((float) $invoice->net_amount + (float) $invoice->iva_amount, 2));
    }

    /**
     * Test 15: Factura A rechaza receptor Consumidor Final o sin CUIT válido antes de llamar a AFIP.
     */
    public function test_factura_a_strictly_requires_valid_cuit_and_rejects_consumidor_final(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function () {
            $this->fail('No debe llamar a AFIP si la validación previa del comprobante falla');
        });

        $sale = $this->createTestSale(2000.00);

        // Intento 1: Factura A con DocTipo 99 (Consumidor Final)
        $resp1 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 99,
        ]);
        $resp1->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');

        // Intento 2: Factura A con CUIT alterado (fallo Módulo 11)
        $resp2 = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 1,
            'doc_type'     => 80,
            'doc_number'   => '30500010915', // Dígito verificador adulterado
        ]);
        $resp2->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');
    }

    /**
     * Test 16: Límite legal de Consumidor Final anónimo ($340.000) exige DNI o CUIT.
     */
    public function test_anonymous_consumidor_final_limit_enforces_buyer_identification(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function () {
            $this->fail('No debe llamar a AFIP si se supera el tope anónimo sin identificar');
        });

        // Venta por $350.000 (supera el límite de $340.000)
        $sale = $this->createTestSale(350000.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6, // Factura B
            'doc_type'     => 99, // Consumidor Final anónimo
            'doc_number'   => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');
        $this->assertStringContainsString('límite de consumidor final anónimo', $response->json('message'));
    }

    /**
     * Test 17: Falla de SOAP Fault devuelta por AFIP WSFE no tumba la aplicación y retorna 422 failed.
     */
    public function test_afip_soap_fault_returns_422_with_clear_error_description(): void
    {
        Cache::put("afip_ta_{$this->testCuit}_wsfe_testing", [
            'token' => 'VALID_TOKEN',
            'sign'  => 'VALID_SIGN',
        ], 36000);

        Http::fake(function (HttpClientRequest $request) {
            return Http::response('<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <soap:Fault>
      <faultcode>soap:Server</faultcode>
      <faultstring>Error de autenticación: Certificado revocado o no habilitado para WSFEv1</faultstring>
    </soap:Fault>
  </soap:Body>
</soap:Envelope>', 200, ['Content-Type' => 'text/xml']);
        });

        $sale = $this->createTestSale(1000.00);

        $response = $this->actingAsAdmin($this->adminUser)->postJson("/api/sales/{$sale->id}/invoice", [
            'voucher_type' => 6,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('invoice_status', 'failed');
        $this->assertStringContainsString('Certificado revocado', $response->json('message'));
        $this->assertEquals('failed', $sale->fresh()->invoice_status);
    }
}
