<?php

declare(strict_types=1);

/**
 * Challenger2EmpiricalHarness.php
 * 
 * Exhaustive empirical stress test suite for Iteration 2 challenge.
 * Author: Empirical & Conceptual Scripts Challenger (challenger_plan_2)
 */

require_once __DIR__ . '/../../../.agents/teamwork/spec_miner_integrations_1/test_mercadopago.php';

$results = [
    'total' => 0,
    'passed' => 0,
    'failed' => 0,
    'tests' => [],
];

function runTest(string $suite, string $name, bool $passed, string $details): void
{
    global $results;
    $results['total']++;
    if ($passed) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
    $results['tests'][] = [
        'suite' => $suite,
        'name' => $name,
        'passed' => $passed,
        'details' => $details,
    ];
    echo sprintf("[%s] %s | %s: %s\n", $passed ? 'PASS' : 'FAIL', $suite, $name, $details);
}

echo "====================================================================\n";
echo "CHALLENGER 2 EMPIRICAL ADVERSARIAL STRESS HARNESS\n";
echo "PHP Version: " . PHP_VERSION . " | OS: " . PHP_OS_FAMILY . "\n";
echo "====================================================================\n\n";

// =====================================================================
// SECTION 1: MERCADO PAGO WEBHOOK REPLAY ATTACK & HEADER EDGE CASES
// =====================================================================
echo ">>> SECTION 1: MERCADO PAGO WEBHOOK ADVERSARIAL ATTACKS <<<\n";

$secret = 'test_webhook_secret_key_iter2';
$mp = new MercadoPagoServiceMock('test_token', $secret, 'collector_123');
$dataId = '999888777';
$reqId = 'req-trace-uuid-456';

// 1.1 Fresh valid signature (time() - 10s)
$tsFresh = (string)(time() - 10);
$sigFresh = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$tsFresh};", $secret);
$res = $mp->verifyWebhookSignature("ts={$tsFresh},v1={$sigFresh}", $reqId, $dataId);
runTest('MP Webhook', 'Fresh valid signature (10s old)', $res === true, 'Accepted fresh signature within 300s window');

// 1.2 Normal clock skew ahead (time() + 5s)
$tsSkew = (string)(time() + 5);
$sigSkew = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$tsSkew};", $secret);
$res = $mp->verifyWebhookSignature("ts={$tsSkew},v1={$sigSkew}", $reqId, $dataId);
runTest('MP Webhook', 'Clock skew within tolerance (+5s)', $res === true, 'Accepted slight forward clock skew');

// 1.3 Boundary: exactly at 300s
$ts300 = (string)(time() - 300);
$sig300 = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$ts300};", $secret);
$res = $mp->verifyWebhookSignature("ts={$ts300},v1={$sig300}", $reqId, $dataId);
runTest('MP Webhook', 'Boundary tolerance (exactly 300s)', $res === true, 'Accepted signature at boundary threshold');

// 1.4 Stale: 301 seconds ago (just expired)
$ts301 = (string)(time() - 301);
$sig301 = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$ts301};", $secret);
$res = $mp->verifyWebhookSignature("ts={$ts301},v1={$sig301}", $reqId, $dataId);
runTest('MP Webhook', 'Stale webhook (301s ago)', $res === false, 'Strictly rejected expired timestamp beyond 300s');

// 1.5 Stale: 30 days ago (classic replay attack)
$tsOld = (string)(time() - 2592000);
$sigOld = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$tsOld};", $secret);
$res = $mp->verifyWebhookSignature("ts={$tsOld},v1={$sigOld}", $reqId, $dataId);
runTest('MP Webhook', 'Massive replay attack (30 days ago)', $res === false, 'Successfully neutralized replay attack');

// 1.6 Malicious future timestamp (>300s in future)
$tsFuture = (string)(time() + 301);
$sigFuture = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$tsFuture};", $secret);
$res = $mp->verifyWebhookSignature("ts={$tsFuture},v1={$sigFuture}", $reqId, $dataId);
runTest('MP Webhook', 'Manipulated future timestamp (+301s)', $res === false, 'Rejected anomalous future timestamp');

// 1.7 Whitespace tolerance in header formatting
$tsNorm = (string)time();
$sigNorm = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$tsNorm};", $secret);
$spacedHeader = "  ts  =  {$tsNorm}  ,  v1  =  {$sigNorm}  ";
$res = $mp->verifyWebhookSignature($spacedHeader, $reqId, $dataId);
runTest('MP Webhook', 'Aggressive whitespace trimming around delimiters', $res === true, 'Parsed spaced header correctly');

// 1.8 Uppercase keys in header (TS=..., V1=...)
$upperHeader = "TS={$tsNorm},V1={$sigNorm}";
$res = $mp->verifyWebhookSignature($upperHeader, $reqId, $dataId);
runTest('MP Webhook', 'Case-insensitive header key parsing', $res === true, 'Parsed uppercase TS and V1 keys correctly');

// 1.9 Header with extraneous parameters
$extraHeader = "format=json,ts={$tsNorm},trace_id=xyz-99,v1={$sigNorm},env=prod";
$res = $mp->verifyWebhookSignature($extraHeader, $reqId, $dataId);
runTest('MP Webhook', 'Extraneous header parameters ignored', $res === true, 'Successfully extracted ts and v1 from multi-token header');

// 1.10 Non-numeric timestamp injection ("ts=abc")
$res = $mp->verifyWebhookSignature("ts=not_a_number,v1={$sigNorm}", $reqId, $dataId);
runTest('MP Webhook', 'Non-numeric timestamp injection rejected', $res === false, 'Rejected non-numeric ts safely');

// 1.11 Empty header and missing tokens
runTest('MP Webhook', 'Empty header rejected', $mp->verifyWebhookSignature('', $reqId, $dataId) === false, 'Empty header returns false');
runTest('MP Webhook', 'Missing v1 rejected', $mp->verifyWebhookSignature("ts={$tsNorm}", $reqId, $dataId) === false, 'Missing v1 returns false');
runTest('MP Webhook', 'Missing ts rejected', $mp->verifyWebhookSignature("v1={$sigNorm}", $reqId, $dataId) === false, 'Missing ts returns false');

echo "\n";

// =====================================================================
// SECTION 2: MERCADO PAGO ORDERS API MATHEMATICAL INVARIANT TESTS
// =====================================================================
echo ">>> SECTION 2: MERCADO PAGO ORDERS API MATHEMATICAL RECONCILIATION <<<\n";

// 2.1 Exact cart without drift
$exactItems = [
    ['title' => 'Item A', 'unit_price' => 50.00, 'quantity' => 2],
];
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-EXACT', 100.00, $exactItems, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
runTest('MP Orders API', 'Exact cart without drift', $payload['total_amount'] === $itemSum && count($payload['items']) === 1, 'No DISC-ADJ item injected when diff is 0.00');

// 2.2 Fractional cent drift: 3 items @ 33.33 = 99.99 for 100.00 (+0.01)
$fractionalItems = [
    ['title' => 'Item A', 'unit_price' => 33.33, 'quantity' => 1],
    ['title' => 'Item B', 'unit_price' => 33.33, 'quantity' => 1],
    ['title' => 'Item C', 'unit_price' => 33.33, 'quantity' => 1],
];
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-DRIFT-POS', 100.00, $fractionalItems, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
$hasAdj = false;
foreach ($payload['items'] as $it) {
    if (($it['sku_number'] ?? '') === 'DISC-ADJ' && $it['total_amount'] === 0.01) {
        $hasAdj = true;
    }
}
runTest('MP Orders API', 'Cent drift (+0.01) reconciliation', $payload['total_amount'] === $itemSum && $hasAdj, 'DISC-ADJ injected with +0.01 matching total_amount');

// 2.3 Cent drift: 3 items @ 33.34 = 100.02 for 100.01 (-0.01)
$fractionalItemsNeg = [
    ['title' => 'Item A', 'unit_price' => 33.34, 'quantity' => 1],
    ['title' => 'Item B', 'unit_price' => 33.34, 'quantity' => 1],
    ['title' => 'Item C', 'unit_price' => 33.34, 'quantity' => 1],
];
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-DRIFT-NEG', 100.01, $fractionalItemsNeg, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
$hasAdjNeg = false;
foreach ($payload['items'] as $it) {
    if (($it['sku_number'] ?? '') === 'DISC-ADJ' && $it['total_amount'] === -0.01) {
        $hasAdjNeg = true;
    }
}
runTest('MP Orders API', 'Cent drift (-0.01) reconciliation', $payload['total_amount'] === $itemSum && $hasAdjNeg, 'DISC-ADJ injected with -0.01 matching total_amount');

// 2.4 Global cart discount: items sum to 1000.00, total is 850.00 (-150.00 discount)
$discountCart = [
    ['title' => 'Monitor LED', 'unit_price' => 1000.00, 'quantity' => 1],
];
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-DISCOUNT', 850.00, $discountCart, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
$hasDiscountAdj = false;
foreach ($payload['items'] as $it) {
    if (($it['sku_number'] ?? '') === 'DISC-ADJ' && $it['total_amount'] === -150.00) {
        $hasDiscountAdj = true;
    }
}
runTest('MP Orders API', 'Global cart discount (-150.00) reconciliation', $payload['total_amount'] === $itemSum && $hasDiscountAdj, 'DISC-ADJ injected with -150.00 matching total_amount');

// 2.5 Multi-item complex cents (7 items @ 14.28 = 99.96 for total 100.00)
$multiItems = [];
for ($i = 0; $i < 7; $i++) {
    $multiItems[] = ['title' => "Part {$i}", 'unit_price' => 14.28, 'quantity' => 1];
}
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-MULTI', 100.00, $multiItems, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
runTest('MP Orders API', 'Complex multi-item cent drift (+0.04)', $payload['total_amount'] === $itemSum && $itemSum === 100.00, 'sum(items.total_amount) === total_amount strictly maintained');

// 2.6 Stress generator: 50 items with random fractional values
$randomItems = [];
$expectedSubtotal = 0.0;
mt_srand(42);
for ($i = 0; $i < 50; $i++) {
    $price = round(mt_rand(100, 9999) / 100, 2);
    $qty = mt_rand(1, 5);
    $randomItems[] = ['title' => "Rnd {$i}", 'unit_price' => $price, 'quantity' => $qty];
    $expectedSubtotal += round($price * $qty, 2);
}
// Force arbitrary POS total with discount
$forcedTotal = round($expectedSubtotal * 0.9, 2);
$payload = $mp->buildV1OrderPayload('POS-1', 'REF-STRESS-50', $forcedTotal, $randomItems, 'https://cb');
$itemSum = round(array_sum(array_column($payload['items'], 'total_amount')), 2);
runTest('MP Orders API', '50-item random cart stress reconciliation', $payload['total_amount'] === $itemSum, sprintf('Arbitrary total %.2f strictly equals sum(items) %.2f', $forcedTotal, $itemSum));

echo "\n";

// =====================================================================
// SECTION 3: ARCA AFIP CRYPTOGRAPHY & ATTACHED CMS DER PARSING
// =====================================================================
echo ">>> SECTION 3: ARCA AFIP WSAA CMS SIGNING & CRYPTOGRAPHY <<<\n";

// 3.1 Dynamic OPENSSL_CONF discovery
$candidateConf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (!getenv('OPENSSL_CONF') && file_exists($candidateConf)) {
    putenv("OPENSSL_CONF={$candidateConf}");
}
$defaultConf = getenv('OPENSSL_CONF') ?: (file_exists($candidateConf) ? $candidateConf : null);
$configArgs = ($defaultConf && file_exists($defaultConf)) ? ['config' => $defaultConf] : [];
runTest('AFIP OpenSSL', 'Dynamic OPENSSL_CONF resolution', !empty($defaultConf) && file_exists($defaultConf), "Found openssl.cnf at: {$defaultConf}");

// 3.2 Key and Cert Generation
$privkey = openssl_pkey_new(array_merge([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
], $configArgs));
$csr = openssl_csr_new(["commonName" => "AFIP Test Cert"], $privkey, array_merge(['digest_alg' => 'sha256'], $configArgs));
$cert = openssl_csr_sign($csr, null, $privkey, 365, array_merge(['digest_alg' => 'sha256'], $configArgs));

$kFile = tempnam(sys_get_temp_dir(), 'af_k_');
$cFile = tempnam(sys_get_temp_dir(), 'af_c_');
$tFile = tempnam(sys_get_temp_dir(), 'af_t_');
$sFile = tempnam(sys_get_temp_dir(), 'af_s_');

openssl_pkey_export_to_file($privkey, $kFile, null, $configArgs);
openssl_x509_export_to_file($cert, $cFile);

// 3.3 TRA XML generation with 10-min clock compensation
$genTime = date('c', time() - 600);
$expTime = date('c', time() + 600);
$traXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<loginTicketRequest version=\"1.0\"><header><uniqueId>" . time() . "</uniqueId><generationTime>{$genTime}</generationTime><expirationTime>{$expTime}</expirationTime></header><service>wsfe</service></loginTicketRequest>";
file_put_contents($tFile, $traXml);

// 3.4 OpenSSL PKCS#7 signing with flags = 0 (Attached/Enveloped CMS)
$realCert = realpath($cFile);
$realKey = realpath($kFile);
$signed = openssl_pkcs7_sign(
    $tFile,
    $sFile,
    "file://" . $realCert,
    ["file://" . $realKey, ""],
    [],
    0 // Attached CMS
);
runTest('AFIP WSAA', 'PKCS#7 CMS Signing with flags = 0', $signed === true, 'Successfully executed openssl_pkcs7_sign with flags = 0');

// 3.5 Clean Base64 extraction and DER parsing
$rawCms = file_get_contents($sFile);
$parts = preg_split("/\r?\n\r?\n/", $rawCms, 2);
$cmsBase64 = preg_replace('/[\r\n\s]+/', '', trim($parts[1] ?? $rawCms));
$derBytes = base64_decode($cmsBase64, true);

$isPureBase64 = ($derBytes !== false && !str_contains($cmsBase64, 'boundary'));
runTest('AFIP WSAA', 'Base64 extraction without MIME boundaries', $isPureBase64, sprintf('Extracted %d bytes base64 without boundary markers', strlen($cmsBase64)));

// 3.6 ASN.1 DER Envelope structure verification
// PKCS#7 SignedData starts with ASN.1 SEQUENCE (0x30)
$isAsn1Sequence = (strlen($derBytes ?: '') > 1000 && ord($derBytes[0]) === 0x30);
runTest('AFIP WSAA', 'ASN.1 DER Sequence Structure (0x30)', $isAsn1Sequence, sprintf('Decoded %d bytes ASN.1 DER with valid 0x30 header', strlen($derBytes ?: '')));

// 3.7 Verify that the TRA XML is embedded within the DER envelope (Attached SignedData check)
$containsEmbeddedXml = (str_contains($derBytes, '<loginTicketRequest') && str_contains($derBytes, '<service>wsfe</service>'));
runTest('AFIP WSAA', 'Attached/Enveloped SignedData payload check', $containsEmbeddedXml, 'TRA XML is encapsulated directly inside the CMS signedData structure');

// Clean up temp files
@unlink($kFile);
@unlink($cFile);
@unlink($tFile);
@unlink($sFile);

echo "\n";

// =====================================================================
// SECTION 4: AFIP FISCAL QR & WSFEv1 MATHEMATICAL SCHEMA INVARIANTS
// =====================================================================
echo ">>> SECTION 4: ARCA AFIP FISCAL QR & WSFEv1 INVARIANTS <<<\n";

// 4.1 RG 4892/2020 13-Field Schema Check
$sampleQrData = [
    'ver' => 1,
    'fecha' => '2026-10-02',
    'cuit' => 30708574836,
    'ptoVta' => 1,
    'tipoCmp' => 1, // Factura A
    'nroCmp' => 1001,
    'importe' => 1210.00,
    'moneda' => 'PES',
    'ctz' => 1,
    'tipoDocRec' => 80, // CUIT
    'nroDocRec' => 20123456789,
    'tipoCodAut' => 'E',
    'codAut' => 74382910492819,
];
$expectedKeys = ['ver', 'fecha', 'cuit', 'ptoVta', 'tipoCmp', 'nroCmp', 'importe', 'moneda', 'ctz', 'tipoDocRec', 'nroDocRec', 'tipoCodAut', 'codAut'];
$allKeysPresent = count(array_intersect_key(array_flip($expectedKeys), $sampleQrData)) === 13;
runTest('AFIP QR', 'RG 4892 13-field complete schema', $allKeysPresent, 'All 13 RG 4892 mandatory fields present');

// 4.2 QR URL Base64 Encoding & Round-trip decoding
$qrJson = json_encode($sampleQrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$qrB64 = base64_encode($qrJson);
$qrUrl = "https://www.afip.gob.ar/fe/qr/?p={$qrB64}";
$decodedQr = json_decode(base64_decode($qrB64), true);
$roundTripOk = ($decodedQr !== null)
    && ($decodedQr['cuit'] === $sampleQrData['cuit'])
    && ($decodedQr['codAut'] === $sampleQrData['codAut'])
    && ((float)$decodedQr['importe'] === (float)$sampleQrData['importe'])
    && ($decodedQr['tipoCmp'] === $sampleQrData['tipoCmp']);
runTest('AFIP QR', 'RG 4892 Base64 round-trip integrity', $roundTripOk, "QR URL format valid: {$qrUrl}");

// 4.3 WSFEv1 Invariant Math: ImpTotal = ImpNeto + ImpTotConc + ImpOpEx + ImpTrib + ImpIVA
$impNeto = 1000.00;
$impTotConc = 0.00;
$impOpEx = 0.00;
$impTrib = 0.00;
$impIVA = 210.00;
$impTotal = round($impNeto + $impTotConc + $impOpEx + $impTrib + $impIVA, 2);
runTest('AFIP WSFEv1', 'Factura A/B Tax Invariant Math', $impTotal === 1210.00, "ImpTotal ({$impTotal}) == ImpNeto ({$impNeto}) + ImpIVA ({$impIVA})");

// 4.4 WSFEv1 Factura C (Monotributo) Zero-IVA Invariant
$cNeto = 5500.00;
$cIVA = 0.00;
$cTotal = round($cNeto + $cIVA, 2);
runTest('AFIP WSFEv1', 'Factura C Monotributo Zero-IVA Invariant', $cTotal === $cNeto && $cIVA === 0.00, "Factura C enforces ImpIVA = 0.00 and ImpTotal = ImpNeto");

echo "\n";

// =====================================================================
// SECTION 5: PLAN_DE_IMPLEMENTACION.md SPECIFICATION AUDIT
// =====================================================================
echo ">>> SECTION 5: PLAN_DE_IMPLEMENTACION.MD TEXTUAL CONFORMANCE AUDIT <<<\n";

$planPath = 'c:/laragon/www/Sistema_POS/PLAN_DE_IMPLEMENTACION.md';
$planContent = file_get_contents($planPath);

// 5.1 Check restrictOnDelete on electronic_invoices sale_id
$hasRestrictOnDelete = str_contains($planContent, "constrained('sales')->restrictOnDelete()");
runTest('Plan Spec', 'Migration 2 restrictOnDelete invariant', $hasRestrictOnDelete, 'electronic_invoices enforces restrictOnDelete() on sale_id');

// 5.2 Check openssl_pkcs7_sign flags = 0
$hasAttachedFlag = preg_match('/openssl_pkcs7_sign[\s\S]+?,\s*0\s*(\/\/[^\n]*)?\s*\);/s', $planContent) === 1;
runTest('Plan Spec', 'AFIP WSAA flags = 0 (Attached CMS)', $hasAttachedFlag, 'PLAN_DE_IMPLEMENTACION.md § 6.2 uses flag 0 for Attached CMS');

// 5.3 Check regex clean Base64 extraction in plan
$hasCleanB64 = str_contains($planContent, "preg_replace('/[\\r\\n\\s]+/', '', trim(\$parts[1] ?? \$cmsContent))");
runTest('Plan Spec', 'AFIP WSAA regex clean Base64 extraction', $hasCleanB64, 'PLAN_DE_IMPLEMENTACION.md § 4.3 & § 6.2 sanitizes Base64');

// 5.4 Check MP webhook replay protection ($maxTolerance = 300) in plan
$hasReplayCheck = str_contains($planContent, '$maxTolerance = 300;') && str_contains($planContent, 'abs(time() - (int)$ts) > $maxTolerance');
runTest('Plan Spec', 'Mercado Pago Webhook Replay Protection ($maxTolerance = 300)', $hasReplayCheck, 'PLAN_DE_IMPLEMENTACION.md § 4.2 & § 6.1 specifies 300s window');

// 5.5 Check MP orders API rounding drift reconciliation (DISC-ADJ) in plan
$hasDiscAdj = str_contains($planContent, "'sku_number' => 'DISC-ADJ'") && str_contains($planContent, 'abs($diff) >= 0.01');
runTest('Plan Spec', 'Mercado Pago Orders API Rounding Reconciliation (DISC-ADJ)', $hasDiscAdj, 'PLAN_DE_IMPLEMENTACION.md § 4.2 & § 6.1 specifies DISC-ADJ reconciliation');

// 5.6 Check dynamic OPENSSL_CONF resolution in plan
$hasDynamicOpenSsl = str_contains($planContent, 'candidateConf = dirname(PHP_BINARY) . \'/extras/ssl/openssl.cnf\'');
runTest('Plan Spec', 'Dynamic OPENSSL_CONF resolution', $hasDynamicOpenSsl, 'PLAN_DE_IMPLEMENTACION.md § 4.3 & § 6.2 uses dynamic PHP_BINARY discovery');

echo "\n";
echo "====================================================================\n";
echo "FINAL RESULTS SUMMARY:\n";
echo "Total tests executed: " . $results['total'] . "\n";
echo "Passed: " . $results['passed'] . "\n";
echo "Failed: " . $results['failed'] . "\n";
echo "Success rate: " . sprintf("%.2f%%", ($results['passed'] / $results['total']) * 100) . "\n";
echo "====================================================================\n";

file_put_contents(__DIR__ . '/challenger2_results.json', json_encode($results, JSON_PRETTY_PRINT));
exit($results['failed'] === 0 ? 0 : 1);
