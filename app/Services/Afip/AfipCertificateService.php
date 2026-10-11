<?php

namespace App\Services\Afip;

use App\Models\BusinessSetting;
use DomainException;

class AfipCertificateService
{
    /**
     * Valida criptográficamente y almacena los certificados X.509 y claves privadas para AFIP/ARCA.
     *
     *
     * @throws DomainException
     */
    public function validateAndStore(?string $rawCuit, ?string $certContent, ?string $keyContent, string $passphrase = ''): array
    {
        $cuit = preg_replace('/\D/', '', $rawCuit ?? '');
        if (empty($cuit)) {
            throw new DomainException('INVALID_CUIT:El CUIT es obligatorio y debe contener dígitos numéricos.');
        }

        if (empty($certContent) || empty($keyContent)) {
            throw new DomainException('MISSING_CERT_OR_KEY:Debe adjuntar tanto el certificado (.crt) como la clave privada (.key).');
        }

        $x509 = @openssl_x509_read($certContent);
        if (! $x509) {
            throw new DomainException('INVALID_CERTIFICATE:El certificado X.509 es inválido o no pudo ser interpretado.');
        }

        $certData = @openssl_x509_parse($x509);
        $pkey = @openssl_pkey_get_private($keyContent, $passphrase);
        if (! $pkey) {
            throw new DomainException('INVALID_PRIVATE_KEY:La clave privada es inválida o la contraseña de desbloqueo es incorrecta.');
        }

        if (! @openssl_x509_check_private_key($x509, $pkey)) {
            throw new DomainException('CERT_KEY_MISMATCH:El par criptográfico no coincide: el certificado no corresponde a la clave privada.');
        }

        $targetDir = storage_path('app/private/afip/'.$cuit);
        if (! file_exists($targetDir)) {
            mkdir($targetDir, 0700, true);
        }

        $certPath = $targetDir.DIRECTORY_SEPARATOR.'cert.crt';
        $keyPath = $targetDir.DIRECTORY_SEPARATOR.'cert.key';

        file_put_contents($certPath, $certContent);
        file_put_contents($keyPath, $keyContent);

        @chmod($certPath, 0600);
        @chmod($keyPath, 0600);

        $expiresAt = null;
        if (! empty($certData['validTo_time_t'])) {
            $expiresAt = date('Y-m-d H:i:s', $certData['validTo_time_t']);
        }

        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => $cuit]);
        BusinessSetting::setSecret('afip_cert_path', "afip/{$cuit}/cert.crt");
        BusinessSetting::setSecret('afip_key_path', "afip/{$cuit}/cert.key");
        if ($expiresAt) {
            BusinessSetting::updateOrCreate(['key' => 'afip_cert_expires_at'], ['value' => $expiresAt]);
        }

        return [
            'afip_cuit' => $cuit,
            'afip_has_cert' => true,
            'afip_has_key' => true,
            'afip_cert_expires_at' => $expiresAt,
        ];
    }
}
