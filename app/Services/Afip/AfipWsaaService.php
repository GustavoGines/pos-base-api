<?php

declare(strict_types=1);

namespace App\Services\Afip;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AfipWsaaService
{
    public const URL_WSAA_HOMO = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    public const URL_WSAA_PROD = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';

    public function __construct()
    {
        $this->ensureOpenSslConfig();
    }

    /**
     * Detección dinámica y configuración de OPENSSL_CONF para entornos Windows / Laragon.
     */
    public function ensureOpenSslConfig(): void
    {
        $envConf = getenv('OPENSSL_CONF');
        if (empty($envConf)) {
            $candidate = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            if (file_exists($candidate)) {
                putenv("OPENSSL_CONF={$candidate}");
                $_ENV['OPENSSL_CONF'] = $candidate;
            }
        }
    }

    /**
     * Genera el XML del Ticket de Requerimiento de Acceso (TRA) con offset de reloj para evitar desfases.
     */
    public function createTraXml(string $service = 'wsfe'): string
    {
        $uniqueId = time();
        $generationTime = date('c', time() - 600); // 10 minutos en el pasado
        $expirationTime = date('c', time() + 600); // 10 minutos en el futuro

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            "<loginTicketRequest version=\"1.0\">\n" .
            "  <header>\n" .
            "    <uniqueId>{$uniqueId}</uniqueId>\n" .
            "    <generationTime>{$generationTime}</generationTime>\n" .
            "    <expirationTime>{$expirationTime}</expirationTime>\n" .
            "  </header>\n" .
            "  <service>{$service}</service>\n" .
            "</loginTicketRequest>";
    }

    /**
     * Firma el XML del TRA utilizando PKCS#7 / CMS con flags = 0 (Attached/Enveloped CMS)
     * y sanea el resultado extrayendo el Base64 puro sin encabezados MIME.
     */
    public function signTra(string $traXml, string $certPath, string $keyPath, string $passphrase = ''): string
    {
        $this->ensureOpenSslConfig();

        if (! file_exists($certPath)) {
            throw new RuntimeException("El archivo de certificado AFIP no existe en: {$certPath}");
        }

        if (! file_exists($keyPath)) {
            throw new RuntimeException("El archivo de clave privada AFIP no existe en: {$keyPath}");
        }

        $tempDir = sys_get_temp_dir();
        $traFile = tempnam($tempDir, 'tra_');
        $cmsFile = tempnam($tempDir, 'cms_');

        try {
            file_put_contents($traFile, $traXml);

            $signed = openssl_pkcs7_sign(
                $traFile,
                $cmsFile,
                'file://' . realpath($certPath),
                ['file://' . realpath($keyPath), $passphrase],
                [],
                0 // CRÍTICO AFIP: Attached / Enveloped CMS (NO usar PKCS7_DETACHED)
            );

            if (! $signed) {
                $error = openssl_error_string();
                throw new RuntimeException("Error al firmar TRA con OpenSSL: {$error}");
            }

            $cmsRaw = file_get_contents($cmsFile);
            if ($cmsRaw === false) {
                throw new RuntimeException("Error al leer el archivo CMS generado");
            }

            // Saneamiento de encabezados MIME de OpenSSL
            $parts = preg_split("/\r?\n\r?\n/", $cmsRaw, 2);
            $cmsBase64 = preg_replace('/[\r\n\s]+/', '', trim($parts[1] ?? $cmsRaw));

            if (empty($cmsBase64)) {
                throw new RuntimeException("El CMS firmado generado está vacío");
            }

            return $cmsBase64;
        } finally {
            if (file_exists($traFile)) {
                @unlink($traFile);
            }
            if (file_exists($cmsFile)) {
                @unlink($cmsFile);
            }
        }
    }

    /**
     * Obtiene las credenciales vigentes (Token y Sign) para el servicio especificado.
     * Utiliza caché local de 10 horas para cumplir con las políticas anti-bloqueo de AFIP.
     *
     * @return array{token: string, sign: string, expiration_time: string|null}
     */
    public function getAccessTicket(string $service = 'wsfe'): array
    {
        $cuit = BusinessSetting::where('key', 'afip_cuit')->value('value');
        $cuit = preg_replace('/\D/', '', (string) $cuit);

        if (empty($cuit)) {
            throw new RuntimeException('No se ha configurado el CUIT comercial para AFIP en ajustes.');
        }

        $environment = BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing';
        $cacheKey = "afip_ta_{$cuit}_{$service}_{$environment}";

        // 1. Revisar caché existente
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['token']) && ! empty($cached['sign'])) {
            return $cached;
        }

        // 2. Resolver rutas de certificado y clave privada
        $certPath = $this->resolveCertPath($cuit);
        $keyPath = $this->resolveKeyPath($cuit);

        // 3. Generar TRA y firmar CMS
        $traXml = $this->createTraXml($service);
        $cmsBase64 = $this->signTra($traXml, $certPath, $keyPath);

        // 4. Invocar WSAA loginCms vía HTTP/SOAP
        $endpoint = $environment === 'production' ? self::URL_WSAA_PROD : self::URL_WSAA_HOMO;
        $ticketData = $this->callLoginCms($endpoint, $cmsBase64);

        // 5. Almacenar en caché por 10 horas (36000 segundos)
        Cache::put($cacheKey, $ticketData, 36000);

        return $ticketData;
    }

    /**
     * Realiza la llamada SOAP loginCms al endpoint de AFIP WSAA.
     */
    public function callLoginCms(string $endpoint, string $cmsBase64): array
    {
        $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsaa="http://wsaa.view.sua.dvad.ar.gov.afip.biz/">' .
            '<soapenv:Header/>' .
            '<soapenv:Body>' .
            '<wsaa:loginCms>' .
            '<wsaa:in0>' . htmlspecialchars($cmsBase64, ENT_XML1) . '</wsaa:in0>' .
            '</wsaa:loginCms>' .
            '</soapenv:Body>' .
            '</soapenv:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '""',
        ])->timeout(12)->send('POST', $endpoint, [
            'body' => $soapEnvelope,
        ]);

        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();
            throw new RuntimeException("AFIP WSAA HTTP Error {$status}: {$body}");
        }

        $responseXml = $response->body();

        // Verificar si contiene un Fault de SOAP
        if (str_contains($responseXml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $responseXml, $faultMatch);
            $faultMsg = $faultMatch[1] ?? 'Error desconocido en autenticación AFIP';
            throw new RuntimeException("AFIP WSAA SOAP Fault: {$faultMsg}");
        }

        // Extraer loginCmsReturn (que contiene el XML de loginTicketResponse codificado o plano)
        if (preg_match('/<loginCmsReturn>(.*?)<\/loginCmsReturn>/s', $responseXml, $matches)) {
            $ticketXml = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        } else {
            $ticketXml = $responseXml;
        }

        // Extraer token, sign y expirationTime
        preg_match('/<token>(.*?)<\/token>/s', $ticketXml, $tokenMatch);
        preg_match('/<sign>(.*?)<\/sign>/s', $ticketXml, $signMatch);
        preg_match('/<expirationTime>(.*?)<\/expirationTime>/s', $ticketXml, $expMatch);

        $token = trim($tokenMatch[1] ?? '');
        $sign = trim($signMatch[1] ?? '');
        $expirationTime = trim($expMatch[1] ?? '');

        if (empty($token) || empty($sign)) {
            throw new RuntimeException("Respuesta de WSAA incompleta: no se encontraron token o sign válidos.");
        }

        return [
            'token' => $token,
            'sign' => $sign,
            'expiration_time' => $expirationTime ?: null,
        ];
    }

    /**
     * Resuelve la ruta física del certificado .crt en el disco.
     */
    protected function resolveCertPath(string $cuit): string
    {
        $stored = BusinessSetting::where('key', 'afip_cert_path')->value('value');
        if (! empty($stored)) {
            $p = storage_path('app/private/' . $stored);
            if (file_exists($p)) {
                return $p;
            }
        }

        $default = storage_path("app/private/afip/{$cuit}/cert.crt");
        if (file_exists($default)) {
            return $default;
        }

        throw new RuntimeException("No se encontró el certificado digital (.crt) para el CUIT {$cuit}.");
    }

    /**
     * Resuelve la ruta física de la clave privada .key en el disco.
     */
    protected function resolveKeyPath(string $cuit): string
    {
        $stored = BusinessSetting::where('key', 'afip_key_path')->value('value');
        if (! empty($stored)) {
            $p = storage_path('app/private/' . $stored);
            if (file_exists($p)) {
                return $p;
            }
        }

        $default = storage_path("app/private/afip/{$cuit}/cert.key");
        if (file_exists($default)) {
            return $default;
        }

        throw new RuntimeException("No se encontró la clave privada (.key) para el CUIT {$cuit}.");
    }

    /**
     * Limpia la caché de tickets de acceso.
     */
    public function clearCache(?string $cuit = null, string $service = 'wsfe', ?string $environment = null): void
    {
        $cuit = $cuit ?? BusinessSetting::where('key', 'afip_cuit')->value('value');
        $cuit = preg_replace('/\D/', '', (string) $cuit);
        $environment = $environment ?? (BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing');

        if (! empty($cuit)) {
            Cache::forget("afip_ta_{$cuit}_{$service}_{$environment}");
        }
    }
}
