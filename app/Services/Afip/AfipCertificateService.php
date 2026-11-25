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

        if (empty($certContent)) {
            throw new DomainException('MISSING_CERT_OR_KEY:Debe adjuntar el certificado (.crt) emitido por ARCA.');
        }

        if (empty($keyContent)) {
            $existingKeyPath = storage_path('app/private/afip/'.$cuit.'/cert.key');
            if (file_exists($existingKeyPath)) {
                $keyContent = @file_get_contents($existingKeyPath);
            }
        }

        if (empty($keyContent)) {
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

    /**
     * Genera una Solicitud de Certificado Digital (CSR - PKCS#10) para ARCA/AFIP.
     * Reutiliza la clave privada existente del CUIT si ya existe (salvo que $forceNewKey sea true),
     * o genera una nueva clave privada RSA de 2048 bits y la almacena de forma segura en el servidor.
     *
     * @throws DomainException
     */
    public function generateCsr(?string $rawCuit, ?string $companyName = null, bool $forceNewKey = false): array
    {
        $cuit = preg_replace('/\D/', '', $rawCuit ?? '');
        if (empty($cuit) || strlen($cuit) !== 11) {
            throw new DomainException('INVALID_CUIT:El CUIT comercial debe tener exactamente 11 dígitos numéricos.');
        }

        $this->ensureOpenSslConfig();

        $targetDir = storage_path('app/private/afip/'.$cuit);
        if (! file_exists($targetDir)) {
            mkdir($targetDir, 0700, true);
        }

        $keyPath = $targetDir.DIRECTORY_SEPARATOR.'cert.key';
        $pkey = false;
        $keyContent = '';

        if (! $forceNewKey && file_exists($keyPath)) {
            $existingKey = @file_get_contents($keyPath);
            if ($existingKey) {
                $pkey = @openssl_pkey_get_private($existingKey);
                if ($pkey) {
                    $keyContent = $existingKey;
                }
            }
        }

        $openSslConfig = $this->buildOpenSslConfig();

        if (! $pkey) {
            $pkey = @openssl_pkey_new($openSslConfig);
            if (! $pkey) {
                throw new DomainException('OPENSSL_KEY_ERROR:No se pudo generar la clave privada RSA 2048 bits en el servidor.');
            }
            if (! @openssl_pkey_export($pkey, $keyContent, null, $openSslConfig)) {
                throw new DomainException('OPENSSL_KEY_EXPORT_ERROR:No se pudo exportar la clave privada generada.');
            }
            file_put_contents($keyPath, $keyContent);
            @chmod($keyPath, 0600);
        }

        $orgName = trim((string) ($companyName ?? BusinessSetting::where('key', 'company_name')->value('value') ?? 'Empresa POS'));
        $orgName = preg_replace('/[^\w\s\.\-]/u', '', $orgName) ?: 'Empresa POS';

        $dn = [
            'countryName' => 'AR',
            'organizationName' => mb_substr($orgName, 0, 64),
            'commonName' => 'SistemaPOS',
            'serialNumber' => "CUIT {$cuit}",
        ];

        $csr = @openssl_csr_new($dn, $pkey, $openSslConfig);
        if (! $csr || ! @openssl_csr_export($csr, $csrContent)) {
            throw new DomainException('OPENSSL_CSR_ERROR:No se pudo generar la solicitud CSR (PKCS#10).');
        }

        $csrPath = $targetDir.DIRECTORY_SEPARATOR."solicitud_arca_{$cuit}.csr";
        file_put_contents($csrPath, $csrContent);
        @chmod($csrPath, 0600);

        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => $cuit]);
        BusinessSetting::setSecret('afip_key_path', "afip/{$cuit}/cert.key");

        return [
            'afip_cuit' => $cuit,
            'afip_has_key' => true,
            'csr_filename' => "solicitud_arca_{$cuit}.csr",
            'csr_content' => $csrContent,
        ];
    }

    protected function ensureOpenSslConfig(): void
    {
        $resolved = $this->resolveOpenSslConfigPath();
        if ($resolved !== null) {
            putenv("OPENSSL_CONF={$resolved}");
            $_ENV['OPENSSL_CONF'] = $resolved;
        }
    }

    protected function buildOpenSslConfig(): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resolved = $this->resolveOpenSslConfigPath();
        if ($resolved !== null) {
            $config['config'] = $resolved;
        }

        return $config;
    }

    /**
     * Resuelve una ruta válida de openssl.cnf compatible tanto con PHP CLI como con Apache mod_php en Windows/Laragon.
     * Si el entorno no provee ningún archivo openssl.cnf accesible, genera uno minimalista válido en storage privado.
     */
    protected function resolveOpenSslConfigPath(): ?string
    {
        $candidates = [];

        $envConf = getenv('OPENSSL_CONF');
        if (! empty($envConf)) {
            $candidates[] = $envConf;
        }

        $loadedIni = php_ini_loaded_file();
        if (! empty($loadedIni)) {
            $candidates[] = dirname($loadedIni).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        }

        if (defined('PHP_BINDIR') && ! empty(PHP_BINDIR)) {
            $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        }

        if (defined('PHP_BINARY') && ! empty(PHP_BINARY)) {
            $candidates[] = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                return $candidate;
            }
        }

        $fallbackDir = storage_path('app/private/afip');
        if (! is_dir($fallbackDir)) {
            @mkdir($fallbackDir, 0700, true);
        }

        $fallbackConf = $fallbackDir.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (! file_exists($fallbackConf)) {
            $minimalConfig = implode("\n", [
                '[ req ]',
                'default_bits = 2048',
                'default_md = sha256',
                'distinguished_name = req_distinguished_name',
                'prompt = no',
                '',
                '[ req_distinguished_name ]',
                'C = AR',
                'O = SistemaPOS',
                'CN = SistemaPOS',
                'serialNumber = CUIT 00000000000',
                '',
            ]);
            @file_put_contents($fallbackConf, $minimalConfig);
        }

        return file_exists($fallbackConf) ? $fallbackConf : null;
    }
}
