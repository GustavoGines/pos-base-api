<?php

declare(strict_types=1);

/**
 * AfipCmsInspection.php
 * Deep inspection of OpenSSL PKCS#7 signing output for AFIP WSAA.
 */

$defaultConf = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/extras/ssl/openssl.cnf';
putenv("OPENSSL_CONF={$defaultConf}");

$dn = ["commonName" => "Test AFIP Cert"];
$privkey = openssl_pkey_new(["private_key_bits" => 2048, "config" => $defaultConf]);
$csr = openssl_csr_new($dn, $privkey, ["digest_alg" => "sha256", "config" => $defaultConf]);
$cert = openssl_csr_sign($csr, null, $privkey, 365, ["digest_alg" => "sha256", "config" => $defaultConf]);

$kFile = tempnam(sys_get_temp_dir(), 'k_');
$cFile = tempnam(sys_get_temp_dir(), 'c_');
$tFile = tempnam(sys_get_temp_dir(), 't_');
$sFileDetached = tempnam(sys_get_temp_dir(), 'sd_');
$sFileAttached = tempnam(sys_get_temp_dir(), 'sa_');

openssl_pkey_export_to_file($privkey, $kFile, null, ["config" => $defaultConf]);
openssl_x509_export_to_file($cert, $cFile);

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<loginTicketRequest version=\"1.0\"><header><uniqueId>123</uniqueId></header><service>wsfe</service></loginTicketRequest>";
file_put_contents($tFile, $xml);

// Case 1: PKCS7_DETACHED (as in test_afip.php)
openssl_pkcs7_sign(
    $tFile,
    $sFileDetached,
    "file://" . realpath($cFile),
    ["file://" . realpath($kFile), ""],
    [],
    PKCS7_DETACHED
);
$contentDetached = file_get_contents($sFileDetached);

// Case 2: WITHOUT PKCS7_DETACHED (i.e. flags = 0)
openssl_pkcs7_sign(
    $tFile,
    $sFileAttached,
    "file://" . realpath($cFile),
    ["file://" . realpath($kFile), ""],
    [],
    0
);
$contentAttached = file_get_contents($sFileAttached);

echo "--- 1. PKCS7_DETACHED (test_afip.php) ---\n";
echo "Full raw length: " . strlen($contentDetached) . " bytes\n";
echo "RAW CONTENT:\n" . $contentDetached . "\n\n";

echo "--- 2. FLAGS = 0 (Attached / Enveloped) ---\n";
echo "Full raw length: " . strlen($contentAttached) . " bytes\n";
echo "RAW CONTENT:\n" . $contentAttached . "\n\n";

// Let's analyze how test_afip.php parsed PKCS7_DETACHED:
$partsDetached = preg_split("/\r?\n\r?\n/", $contentDetached, 2);
$testAfipOutput = trim($partsDetached[1] ?? $contentDetached);
echo "--- WHAT test_afip.php EXTRACTS: ---\n";
echo "Length: " . strlen($testAfipOutput) . "\n";
echo "First 200 chars:\n" . substr($testAfipOutput, 0, 200) . "\n";
$isTestAfipPureBase64 = (base64_decode(preg_replace('/[\r\n\s]+/', '', $testAfipOutput), true) !== false && !str_contains($testAfipOutput, 'boundary'));
echo "Is test_afip extracted string pure Base64? " . ($isTestAfipPureBase64 ? "YES" : "NO - CONTAINS MIME MULTIPART JUNK!") . "\n\n";

// Let's analyze what AFIP WSAA documentation says:
// AFIP WSAA requires the CMS envelope (loginCms argument) to contain the TRA XML itself (attached/enveloped) OR 
// when using flags = 0:
$partsAttached = preg_split("/\r?\n\r?\n/", $contentAttached, 2);
$attachedBase64 = trim($partsAttached[1] ?? $contentAttached);
echo "--- WHAT flags = 0 EXTRACTS: ---\n";
echo "Length: " . strlen($attachedBase64) . "\n";
$cleanAtt = preg_replace('/[\r\n\s]+/', '', $attachedBase64);
$derAttached = base64_decode($cleanAtt, true);
$isAttachedPureBase64 = ($derAttached !== false && !str_contains($attachedBase64, 'boundary'));
echo "Is flags=0 extracted string pure Base64? " . ($isAttachedPureBase64 ? "YES (" . strlen($derAttached) . " bytes DER)" : "NO") . "\n";

@unlink($kFile);
@unlink($cFile);
@unlink($tFile);
@unlink($sFileDetached);
@unlink($sFileAttached);
