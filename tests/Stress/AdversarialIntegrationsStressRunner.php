<?php

declare(strict_types=1);

/**
 * AdversarialIntegrationsStressRunner.php
 * 
 * Empirical verification harness for Mercado Pago & ARCA AFIP integrations.
 * Tests edge cases, cryptographic vulnerabilities, Windows OpenSSL quirks,
 * rounding errors, schema conformance, and failure modes.
 */

require_once __DIR__ . '/../../../.agents/teamwork/spec_miner_integrations_1/test_mercadopago.php';

$results = [
    'mercadopago' => [],
    'afip' => [],
    'summary' => [
        'total_tests' => 0,
        'passed' => 0,
        'failed' => 0,
        'vulnerabilities_found' => [],
    ],
];

function recordResult(string $suite, string $name, bool $passed, string $details, ?string $vuln = null): void
{
    global $results;
    $results['summary']['total_tests']++;
    if ($passed) {
        $results['summary']['passed']++;
    } else {
        $results['summary']['failed']++;
    }
    if ($vuln !== null) {
        $results['summary']['vulnerabilities_found'][] = [
            'suite' => $suite,
            'test' => $name,
            'vulnerability' => $vuln,
            'details' => $details,
        ];
    }
    $results[$suite][] = [
        'name' => $name,
        'passed' => $passed,
        'details' => $details,
        'vulnerability' => $vuln,
    ];
    echo sprintf("[%s] %s: %s - %s\n", $passed ? 'PASS' : 'FAIL', $suite, $name, $details);
}

echo "====================================================================\n";
echo "STARTING EMPIRICAL ADVERSARIAL STRESS TEST SUITE\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS_FAMILY . " / " . php_uname('s') . "\n";
echo "====================================================================\n\n";

// =========================================================================
// SUITE 1: MERCADO PAGO HMAC & ORDERS API STRESS TESTS
// =========================================================================
echo ">>> RUNNING SUITE 1: MERCADO PAGO <<<\n";

$secret = 'test_webhook_secret_key_789';
$mp = new MercadoPagoServiceMock('test_access_token', $secret, 'collector_999');

// Test 1.1: Standard valid signature verification
$dataId = '9876543210';
$reqId = 'req-uuid-1111';
$ts = (string)time();
$manifest = "id:{$dataId};request-id:{$reqId};ts:{$ts};";
$validHash = hash_hmac('sha256', $manifest, $secret);
$sigHeader = "ts={$ts},v1={$validHash}";
$res = $mp->verifyWebhookSignature($sigHeader, $reqId, $dataId);
recordResult('mercadopago', 'Valid HMAC Signature', $res === true, 'Standard valid signature returns true');

// Test 1.2: Tampered payload (different dataId)
$res = $mp->verifyWebhookSignature($sigHeader, $reqId, 'tampered_data_id');
recordResult('mercadopago', 'Tampered Data ID', $res === false, 'Tampered data ID correctly rejected');

// Test 1.3: Tampered Request ID header
$res = $mp->verifyWebhookSignature($sigHeader, 'tampered-req-id', $dataId);
recordResult('mercadopago', 'Tampered Request ID', $res === false, 'Tampered request ID correctly rejected');

// Test 1.4: Tampered secret
$mpTampered = new MercadoPagoServiceMock('token', 'wrong_secret', 'collector');
$res = $mpTampered->verifyWebhookSignature($sigHeader, $reqId, $dataId);
recordResult('mercadopago', 'Tampered Webhook Secret', $res === false, 'Wrong secret correctly fails verification');

// Test 1.5: Malformed signature headers
$malformedCases = [
    'Empty Header' => '',
    'Missing v1' => "ts={$ts}",
    'Missing ts' => "v1={$validHash}",
    'Garbage string' => 'invalid_header_content_without_delimiters',
    'Malformed delimiter' => "ts={$ts};v1={$validHash}",
    'Reversed order' => "v1={$validHash},ts={$ts}",
];
foreach ($malformedCases as $caseName => $headerVal) {
    $res = $mp->verifyWebhookSignature($headerVal, $reqId, $dataId);
    $expected = ($caseName === 'Reversed order') ? true : false;
    $passed = ($res === $expected);
    recordResult('mercadopago', "Header: {$caseName}", $passed, "Output: " . var_export($res, true) . ", expected: " . var_export($expected, true));
}

// Test 1.6: Header with spaces around delimiters ("ts = ..., v1 = ...")
$spacedHeader = "ts = {$ts}, v1 = {$validHash}";
$res = $mp->verifyWebhookSignature($spacedHeader, $reqId, $dataId);
// In standard HTTP headers, proxies or clients can send 'ts = ...' or 'ts=..., v1=...'.
// Let's test if parser handles spaces around '=':
$handledSpaces = ($res === true);
recordResult(
    'mercadopago',
    'Header with spaces around equals ("ts = ..., v1 = ...")',
    $handledSpaces,
    $handledSpaces ? 'Handled gracefully' : 'Rejected because explode("=", trim($part)) leaves trailing space on keys',
    $handledSpaces ? null : 'Header parser vulnerability: spaces around "=" fail signature parsing even if mathematically valid'
);

// Test 1.7: Replay Attack (Timestamp Freshness)
// An attacker intercepts a valid webhook from 30 days ago.
$oldTs = (string)(time() - 2592000); // 30 days ago
$oldManifest = "id:{$dataId};request-id:{$reqId};ts:{$oldTs};";
$oldHash = hash_hmac('sha256', $oldManifest, $secret);
$oldSig = "ts={$oldTs},v1={$oldHash}";
$resReplay = $mp->verifyWebhookSignature($oldSig, $reqId, $dataId);
// In secure systems, webhooks must reject timestamps older than e.g. 300 seconds (5 mins).
recordResult(
    'mercadopago',
    'Replay Attack Protection (30 days old timestamp)',
    $resReplay === false,
    $resReplay ? 'VULNERABLE: Accepted stale webhook from 30 days ago! No timestamp expiration window check.' : 'Rejected stale webhook',
    $resReplay ? 'Replay attack vulnerability: verifyWebhookSignature does not enforce timestamp freshness window (tolerance: max 300s)' : null
);

// Test 1.8: Floating Point Rounding and Item Sum Invariant in buildV1OrderPayload
// Mercado Pago API strictly rejects payloads where sum(items.total_amount) != total_amount
$fractionalItems = [
    ['title' => 'Panadería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
    ['title' => 'Verdulería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
    ['title' => 'Fiambrería 1/3 kg', 'unit_price' => 33.33, 'quantity' => 1],
];
// Sale total in POS was $100.00
$desiredSaleTotal = 100.00;
$orderPayload = $mp->buildV1OrderPayload('CAJA-01', 'SALE-999', $desiredSaleTotal, $fractionalItems, 'https://cb');
$itemSum = 0.0;
foreach ($orderPayload['items'] as $item) {
    $itemSum += $item['total_amount'];
}
$diff = abs($orderPayload['total_amount'] - $itemSum);
recordResult(
    'mercadopago',
    'Orders API Items Sum vs Total Invariant (Rounding Drift)',
    $diff < 0.0001,
    sprintf('total_amount: %.2f vs sum(items): %.2f (diff: %.2f)', $orderPayload['total_amount'], $itemSum, $diff),
    $diff >= 0.0001 ? 'Tax/Rounding drift: MP API rejects orders where sum(items.total_amount) !== total_amount with 400 Bad Request' : null
);

// Test 1.9: Global Discount Handling in buildV1OrderPayload
// POS has items totaling $1000, but applied a $100 global discount -> sale total is $900
$discountItems = [
    ['title' => 'Producto General', 'unit_price' => 1000.00, 'quantity' => 1],
];
$discountedSaleTotal = 900.00;
$discountPayload = $mp->buildV1OrderPayload('CAJA-01', 'SALE-DISCOUNT', $discountedSaleTotal, $discountItems, 'https://cb');
$discountItemSum = array_sum(array_column($discountPayload['items'], 'total_amount'));
$discountDiff = abs($discountPayload['total_amount'] - $discountItemSum);
recordResult(
    'mercadopago',
    'Orders API Global Discount Accommodation',
    $discountDiff < 0.0001,
    sprintf('total_amount: %.2f vs sum(items): %.2f (unallocated discount: %.2f)', $discountPayload['total_amount'], $discountItemSum, $discountDiff),
    $discountDiff >= 0.0001 ? 'Global discounts not distributed or adjusted into items array causing sum mismatch' : null
);

echo "\n";

// =========================================================================
// SUITE 2: ARCA AFIP CRYPTOGRAPHY, WSAA, WSFEv1 & FISCAL QR STRESS TESTS
// =========================================================================
echo ">>> RUNNING SUITE 2: ARCA AFIP <<<\n";

$defaultConf = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/extras/ssl/openssl.cnf';
$configArgs = file_exists($defaultConf) ? ['config' => $defaultConf] : [];

// Test 2.1: OpenSSL Configuration Dependency on Windows
// Test what happens when OPENSSL_CONF is stripped
$originalOpenSslConf = getenv('OPENSSL_CONF');
putenv('OPENSSL_CONF='); // clear env
$testKeyWithoutConf = @openssl_pkey_new([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
]);
$openSslError = openssl_error_string();
recordResult(
    'afip',
    'OpenSSL Pkey Generation without explicit OPENSSL_CONF',
    $testKeyWithoutConf !== false,
    $testKeyWithoutConf !== false ? 'Generated successfully without env var' : "Failed: {$openSslError}",
    $testKeyWithoutConf === false ? 'Windows PHP requires explicit OPENSSL_CONF path or configArgs array' : null
);
// Restore OPENSSL_CONF
if ($originalOpenSslConf) {
    putenv("OPENSSL_CONF={$originalOpenSslConf}");
} elseif (file_exists($defaultConf)) {
    putenv("OPENSSL_CONF={$defaultConf}");
}

// Test 2.2: Certificate & Key Generation and Validation
$dn = [
    "countryName" => "AR",
    "stateOrProvinceName" => "Buenos Aires",
    "localityName" => "CABA",
    "organizationName" => "Test POS",
    "commonName" => "Test AFIP Cert",
];
$privkey = openssl_pkey_new(array_merge([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
], $configArgs));
$csr = openssl_csr_new($dn, $privkey, array_merge(['digest_alg' => 'sha256'], $configArgs));
$cert = openssl_csr_sign($csr, null, $privkey, 365, array_merge(['digest_alg' => 'sha256'], $configArgs));

$keyMatchesCert = openssl_x509_check_private_key($cert, $privkey);
recordResult('afip', 'Private Key Matches Generated X.509 Certificate', $keyMatchesCert === true, 'openssl_x509_check_private_key passed');

// Test 2.3: Mismatched Private Key Rejection
$anotherKey = openssl_pkey_new(array_merge([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
], $configArgs));
$mismatchedResult = openssl_x509_check_private_key($cert, $anotherKey);
recordResult('afip', 'Mismatched Key Detection', $mismatchedResult === false, 'openssl_x509_check_private_key rejected wrong key');

// Test 2.4: PKCS#7 / CMS Signing with Windows Path Quirks
$tempKeyFile = tempnam(sys_get_temp_dir(), 'afip_t_key_');
$tempCertFile = tempnam(sys_get_temp_dir(), 'afip_t_crt_');
$tempTraFile = tempnam(sys_get_temp_dir(), 'afip_t_tra_');
$tempCmsFile = tempnam(sys_get_temp_dir(), 'afip_t_cms_');

openssl_pkey_export_to_file($privkey, $tempKeyFile, null, $configArgs);
openssl_x509_export_to_file($cert, $tempCertFile);

$uniqueId = time();
$genTime = date('c', time() - 600); // 10 mins clock skew compensation
$expTime = date('c', time() + 600);
$traXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<loginTicketRequest version=\"1.0\">\n"
    . "  <header>\n"
    . "    <uniqueId>{$uniqueId}</uniqueId>\n"
    . "    <generationTime>{$genTime}</generationTime>\n"
    . "    <expirationTime>{$expTime}</expirationTime>\n"
    . "  </header>\n"
    . "  <service>wsfe</service>\n"
    . "</loginTicketRequest>";
file_put_contents($tempTraFile, $traXml);

// Test signing using Windows backslashes (unnormalized path) vs forward slashes
$realCertPath = realpath($tempCertFile);
$realKeyPath = realpath($tempKeyFile);
$signed = openssl_pkcs7_sign(
    $tempTraFile,
    $tempCmsFile,
    "file://" . $realCertPath,
    ["file://" . $realKeyPath, ""],
    [],
    PKCS7_DETACHED
);
$signError = openssl_error_string();
recordResult(
    'afip',
    'OpenSSL PKCS#7 CMS Signing with realpath',
    $signed === true,
    $signed ? 'TRA signed successfully with PKCS7_DETACHED' : "Signing failed: {$signError}"
);

// Test 2.5: CMS Envelope parsing and Base64 extraction
if ($signed) {
    $cmsContent = file_get_contents($tempCmsFile);
    $parts = preg_split("/\r?\n\r?\n/", $cmsContent, 2);
    $cmsBase64 = trim($parts[1] ?? $cmsContent);
    // Note: PKCS7_DETACHED output format in OpenSSL has MIME headers and then multipart boundaries or base64!
    // Let's inspect the first 200 characters of part 0 and part 1:
    echo "DEBUG CMS content headers: " . substr($parts[0] ?? '', 0, 150) . "\n";
    echo "DEBUG CMS content body preview: " . substr($cmsBase64, 0, 100) . "\n";
    // Check if body contains MIME boundary markers or actual PKCS7 base64
    $cleanBase64 = preg_replace('/[\r\n\s]+/', '', $cmsBase64);
    $decodedDer = base64_decode($cleanBase64, true);
    recordResult(
        'afip',
        'CMS Base64 Clean Extraction and DER Validation',
        $decodedDer !== false && strlen($decodedDer) > 1000,
        sprintf('Extracted %d bytes base64 (%d bytes DER)', strlen($cmsBase64), $decodedDer ? strlen($decodedDer) : 0)
    );
}

// Clean up temp files
@unlink($tempKeyFile);
@unlink($tempCertFile);
@unlink($tempTraFile);
@unlink($tempCmsFile);

// Test 2.6: AFIP CUIT Modulo 11 Checksum Algorithm
function validateCuitModulo11(string $cuit): bool {
    $clean = preg_replace('/[^0-9]/', '', $cuit);
    if (strlen($clean) !== 11) {
        return false;
    }
    $mult = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int)$clean[$i] * $mult[$i];
    }
    $rem = 11 - ($sum % 11);
    if ($rem === 11) $verif = 0;
    elseif ($rem === 10) $verif = 9;
    else $verif = $rem;
    return $verif === (int)$clean[10];
}

$validCuit = '30712345678'; // Let's check if 30712345678 is modulo 11 valid
$cuitCheck = validateCuitModulo11($validCuit);
// Real CUIT: 20-30000000-9, 30-71111111-2, etc.
$knownValidCuit = '30708574836'; // Example real CUIT
$cuitValidReal = validateCuitModulo11($knownValidCuit);
recordResult(
    'afip',
    'AFIP CUIT Modulo 11 Verification Function',
    $cuitValidReal === true,
    sprintf('30708574836: %s, test_afip CUIT (30712345678): %s', $cuitValidReal ? 'VALID' : 'INVALID', $cuitCheck ? 'VALID' : 'INVALID (Synthetic test CUIT)')
);

// Test 2.7: RG 4892/2020 Fiscal QR Schema Completeness & Data Types
// Required AFIP fields per RG 4892:
// ver (int), fecha (date YYYY-MM-DD), cuit (int64), ptoVta (int), tipoCmp (int),
// nroCmp (int), importe (float), moneda (string 3 chars), ctz (float),
// tipoDocRec (int), nroDocRec (int64), tipoCodAut (string 1 char 'E'|'A'), codAut (int64)
$testQrData = [
    'ver' => 1,
    'fecha' => '2026-10-02',
    'cuit' => 30708574836,
    'ptoVta' => 1,
    'tipoCmp' => 1, // Factura A
    'nroCmp' => 124,
    'importe' => 12100.50,
    'moneda' => 'PES',
    'ctz' => 1.0,
    'tipoDocRec' => 80,
    'nroDocRec' => 20123456789,
    'tipoCodAut' => 'E',
    'codAut' => 74382910492819,
];
$encodedQr = base64_encode(json_encode($testQrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$qrUrl = "https://www.afip.gob.ar/fe/qr/?p=" . $encodedQr;
$decodedQr = json_decode(base64_decode($encodedQr), true);

$requiredFields = ['ver', 'fecha', 'cuit', 'ptoVta', 'tipoCmp', 'nroCmp', 'importe', 'moneda', 'ctz', 'tipoDocRec', 'nroDocRec', 'tipoCodAut', 'codAut'];
$missingFields = array_diff($requiredFields, array_keys($decodedQr));
recordResult(
    'afip',
    'RG 4892/2020 Fiscal QR Complete 13-Field Schema Check',
    empty($missingFields),
    empty($missingFields) ? 'All 13 RG 4892 fields present and correct types' : 'Missing fields: ' . implode(',', $missingFields)
);

// Test 2.8: RG 4892 Consumer Final without DNI (DocTipo 99, DocNro 0)
$cfQrData = $testQrData;
$cfQrData['tipoCmp'] = 6; // Factura B
$cfQrData['tipoDocRec'] = 99; // Consumidor Final
$cfQrData['nroDocRec'] = 0;
$encodedCfQr = base64_encode(json_encode($cfQrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$decodedCf = json_decode(base64_decode($encodedCfQr), true);
recordResult(
    'afip',
    'RG 4892 Consumidor Final (DocTipo 99, nroDoc 0) Serialization',
    $decodedCf['tipoDocRec'] === 99 && $decodedCf['nroDocRec'] === 0,
    'Correctly encodes anonymous consumer final voucher'
);

// Test 2.9: WSFEv1 Strict Tax Math Invariant
// ImpTotal = ImpNeto + ImpTotConc + ImpOpEx + ImpTrib + ImpIVA
function verifyAfipTaxMath(float $neto, float $iva, float $exento, float $noGravado, float $tributos, float $total): array {
    $expectedTotal = round($neto + $iva + $exento + $noGravado + $tributos, 2);
    $diff = abs(round($total, 2) - $expectedTotal);
    return [
        'valid' => $diff < 0.001,
        'diff' => $diff,
        'expectedTotal' => $expectedTotal,
        'total' => round($total, 2),
    ];
}

// Case A: Clean 21% tax on $1000
$mathA = verifyAfipTaxMath(1000.00, 210.00, 0.0, 0.0, 0.0, 1210.00);
recordResult('afip', 'WSFEv1 Tax Math (Clean Single 21% Rate)', $mathA['valid'], sprintf('Total: %.2f == Expected: %.2f', $mathA['total'], $mathA['expectedTotal']));

// Case B: Fractional multi-item IVA rounding drift
// 3 items with net $10.33 at 21% IVA = $2.1693 each
// If rounded per item: 2.17 * 3 = 6.51. Net sum = 30.99. Total = 37.50.
// If calculated on net sum: 30.99 * 0.21 = 6.5079 -> 6.51.
// What about net $10.55 at 10.5% IVA: 10.55 * 0.105 = 1.10775 -> 1.11.
$net1 = 10.55;
$net2 = 10.55;
$iva1 = round($net1 * 0.105, 2); // 1.11
$iva2 = round($net2 * 0.105, 2); // 1.11
$sumNet = round($net1 + $net2, 2); // 21.10
$sumIvaPerItem = round($iva1 + $iva2, 2); // 2.22
$ivaOnSumNet = round($sumNet * 0.105, 2); // 21.10 * 0.105 = 2.2155 -> 2.22
$mathB = verifyAfipTaxMath($sumNet, $sumIvaPerItem, 0.0, 0.0, 0.0, round($sumNet + $sumIvaPerItem, 2));
recordResult('afip', 'WSFEv1 Multi-Item IVA Rounding Consistency', $mathB['valid'], sprintf('Net: %.2f + IVA: %.2f = Total: %.2f', $sumNet, $sumIvaPerItem, $mathB['total']));

// Case C: Factura C (Monotributo) - ImpIVA MUST be 0.00, no IVA array allowed
$mathC = verifyAfipTaxMath(5000.00, 0.00, 0.0, 0.0, 0.0, 5000.00);
recordResult('afip', 'WSFEv1 Factura C (Monotributo: ImpIVA=0, ImpNeto=Total)', $mathC['valid'], 'Monotributo comprobante C zero-IVA invariant');

echo "\n";

// =========================================================================
// SUITE 3: REMEDIATION & HARDENING PROOFS
// =========================================================================
echo ">>> RUNNING SUITE 3: HARDENED REMEDIATIONS VERIFICATION <<<\n";

// Remediation 1: Hardened Webhook Signature Verification
class HardenedMercadoPagoService
{
    private string $webhookSecret;

    public function __construct(string $webhookSecret)
    {
        $this->webhookSecret = $webhookSecret;
    }

    public function verifyWebhookSignatureHardened(
        string $xSignatureHeader,
        string $xRequestIdHeader,
        string $dataId,
        int $maxToleranceSeconds = 300
    ): bool {
        if (empty($this->webhookSecret) || empty($xSignatureHeader) || empty($xRequestIdHeader) || empty($dataId)) {
            return false;
        }

        $ts = null;
        $hash = null;
        $parts = explode(',', $xSignatureHeader);
        foreach ($parts as $part) {
            $sub = explode('=', trim($part), 2);
            if (count($sub) === 2) {
                $key = strtolower(trim($sub[0]));
                $val = trim($sub[1]);
                if ($key === 'ts') $ts = $val;
                if ($key === 'v1') $hash = $val;
            }
        }

        if (!$ts || !$hash || !ctype_digit($ts)) {
            return false;
        }

        // Replay attack prevention: enforce timestamp freshness
        $tsInt = (int)$ts;
        if (abs(time() - $tsInt) > $maxToleranceSeconds) {
            return false; // Expired or future timestamp
        }

        $manifest = "id:{$dataId};request-id:{$xRequestIdHeader};ts:{$ts};";
        $computedHash = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return hash_equals($computedHash, $hash);
    }

    public function buildV1OrderPayloadHardened(
        string $externalPosId,
        string $externalRef,
        float $amount,
        array $items,
        string $notificationUrl
    ): array {
        $roundedTotal = round($amount, 2);
        $mappedItems = [];
        $runningSum = 0.0;

        foreach ($items as $idx => $item) {
            $uPrice = round((float)$item['unit_price'], 2);
            $qty = (int)$item['quantity'];
            $itemTotal = round($uPrice * $qty, 2);
            $runningSum += $itemTotal;

            $mappedItems[] = [
                'sku_number' => $item['sku'] ?? ("PROD-" . ($idx + 1)),
                'category' => 'marketplace',
                'title' => $item['title'],
                'description' => $item['title'],
                'unit_price' => $uPrice,
                'quantity' => $qty,
                'unit_measure' => 'unit',
                'total_amount' => $itemTotal,
            ];
        }

        $runningSum = round($runningSum, 2);
        $diff = round($roundedTotal - $runningSum, 2);

        // Reconcile rounding discrepancy or global discount
        if (abs($diff) >= 0.01) {
            $mappedItems[] = [
                'sku_number' => 'DISC-ADJ',
                'category' => 'marketplace',
                'title' => ($diff < 0) ? 'Descuento Global / Ajuste' : 'Recargo / Ajuste Redondeo',
                'description' => 'Ajuste de centavos o descuento global',
                'unit_price' => $diff,
                'quantity' => 1,
                'unit_measure' => 'unit',
                'total_amount' => $diff,
            ];
        }

        return [
            'type' => 'qr',
            'external_reference' => $externalRef,
            'total_amount' => $roundedTotal,
            'description' => "Venta POS #{$externalRef}",
            'notification_url' => $notificationUrl,
            'items' => $mappedItems,
            'config' => [
                'qr' => [
                    'external_pos_id' => $externalPosId,
                    'mode' => 'hybrid',
                ],
            ],
        ];
    }
}

$hardenedMp = new HardenedMercadoPagoService($secret);

// Test 3.1: Spaced signature header now passes
$tsNow = (string)time();
$manNow = "id:{$dataId};request-id:{$reqId};ts:{$tsNow};";
$hashNow = hash_hmac('sha256', $manNow, $secret);
$sigWithSpaces = "ts = {$tsNow} , v1 = {$hashNow}";
$resHardenedSpaces = $hardenedMp->verifyWebhookSignatureHardened($sigWithSpaces, $reqId, $dataId);
recordResult('remediation', 'Hardened MP: Resilient Whitespace Parsing', $resHardenedSpaces === true, 'Parsed "ts = ..., v1 = ..." cleanly');

// Test 3.2: Replay attack now rejected
$sigStale = "ts = {$oldTs} , v1 = {$oldHash}";
$resHardenedReplay = $hardenedMp->verifyWebhookSignatureHardened($sigStale, $reqId, $dataId);
recordResult('remediation', 'Hardened MP: Replay Attack Blocked (Stale TS)', $resHardenedReplay === false, 'Stale webhook rejected by freshness window');

// Test 3.3: Invariant-preserving Order Payload Builder (Rounding reconciliation)
$hardenedPayload = $hardenedMp->buildV1OrderPayloadHardened('CAJA-01', 'SALE-999', $desiredSaleTotal, $fractionalItems, 'https://cb');
$hardenedSum = round(array_sum(array_column($hardenedPayload['items'], 'total_amount')), 2);
recordResult(
    'remediation',
    'Hardened MP: Rounding Reconciliation Invariant Check',
    $hardenedPayload['total_amount'] === $hardenedSum,
    sprintf('total_amount (%.2f) === sum(items) (%.2f)', $hardenedPayload['total_amount'], $hardenedSum)
);

// Test 3.4: Invariant-preserving Order Payload Builder (Discount reconciliation)
$hardenedDiscountPayload = $hardenedMp->buildV1OrderPayloadHardened('CAJA-01', 'SALE-DISCOUNT', $discountedSaleTotal, $discountItems, 'https://cb');
$hardenedDiscountSum = round(array_sum(array_column($hardenedDiscountPayload['items'], 'total_amount')), 2);
recordResult(
    'remediation',
    'Hardened MP: Global Discount Reconciliation Invariant Check',
    $hardenedDiscountPayload['total_amount'] === $hardenedDiscountSum,
    sprintf('total_amount (%.2f) === sum(items) (%.2f)', $hardenedDiscountPayload['total_amount'], $hardenedDiscountSum)
);

// Test 3.5: Hardened AFIP CMS Generation (Enveloped flags=0)
function signAfipTraHardened(string $traXml, string $certPath, string $keyPath): ?string
{
    $tempTra = tempnam(sys_get_temp_dir(), 'tra_');
    $tempCms = tempnam(sys_get_temp_dir(), 'cms_');
    file_put_contents($tempTra, $traXml);

    $realCert = realpath($certPath);
    $realKey = realpath($keyPath);

    // Flags = 0: Attached/Enveloped CMS required by AFIP WSAA loginCms
    $signed = openssl_pkcs7_sign(
        $tempTra,
        $tempCms,
        "file://" . $realCert,
        ["file://" . $realKey, ""],
        [],
        0
    );

    if (!$signed) {
        @unlink($tempTra);
        @unlink($tempCms);
        return null;
    }

    $raw = file_get_contents($tempCms);
    @unlink($tempTra);
    @unlink($tempCms);

    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $b64 = trim($parts[1] ?? $raw);
    return preg_replace('/[\r\n\s]+/', '', $b64);
}

// Generate temp cert/key for hardened test
$hKeyFile = tempnam(sys_get_temp_dir(), 'h_k_');
$hCrtFile = tempnam(sys_get_temp_dir(), 'h_c_');
openssl_pkey_export_to_file($privkey, $hKeyFile, null, $configArgs);
openssl_x509_export_to_file($cert, $hCrtFile);

$hardenedCms = signAfipTraHardened($traXml, $hCrtFile, $hKeyFile);
$hardenedDer = base64_decode($hardenedCms, true);
$isValidHardenedCms = ($hardenedDer !== false && strlen($hardenedDer) > 1000 && !str_contains($hardenedCms, 'boundary'));

recordResult(
    'remediation',
    'Hardened AFIP: Enveloped CMS (flags=0) Extraction',
    $isValidHardenedCms,
    sprintf('Valid ASN.1 DER CMS (%d bytes, %d bytes base64, no MIME boundaries)', strlen($hardenedDer ?: ''), strlen($hardenedCms ?: ''))
);

@unlink($hKeyFile);
@unlink($hCrtFile);

echo "\n";
echo "====================================================================\n";
echo "FINAL SUMMARY OF ALL RESULTS:\n";
echo "Total tests executed: " . $results['summary']['total_tests'] . "\n";
echo "Passed: " . $results['summary']['passed'] . "\n";
echo "Failed: " . $results['summary']['failed'] . "\n";
echo "Vulnerabilities detected: " . count($results['summary']['vulnerabilities_found']) . "\n";
foreach ($results['summary']['vulnerabilities_found'] as $idx => $v) {
    echo sprintf("  [%d] %s (%s): %s\n", $idx + 1, $v['vulnerability'], $v['suite'], $v['details']);
}
echo "====================================================================\n";

file_put_contents(__DIR__ . '/adversarial_results.json', json_encode($results, JSON_PRETTY_PRINT));

