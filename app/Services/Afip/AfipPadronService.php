<?php

declare(strict_types=1);

namespace App\Services\Afip;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\ElectronicInvoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AfipPadronService
{
    public const URL_PADRON_A13_HOMO = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13';

    public const URL_PADRON_A13_PROD = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13';

    public const URL_PADRON_A5_HOMO = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5';

    public const URL_PADRON_A5_PROD = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5';

    protected AfipWsaaService $wsaaService;

    public function __construct(?AfipWsaaService $wsaaService = null)
    {
        $this->wsaaService = $wsaaService ?? new AfipWsaaService;
    }

    /**
     * Busca datos fiscales (Nombre/Razón Social, Condición IVA, Domicilio, Tipo/Nro Doc)
     * a partir de un DNI (7-8 dígitos) o CUIT/CUIL (11 dígitos) en 3 capas:
     *   1. Padrón local de Clientes (`customers`)
     *   2. Historial local de Facturas Electrónicas (`electronic_invoices`)
     *   3. Padrón Oficial ARCA Online (`ws_sr_padron_a13` / `ws_sr_constancia_inscripto`)
     *
     * @return array{
     *   found: bool,
     *   source: string|null,
     *   customer_id?: int|null,
     *   doc_type?: int,
     *   doc_number?: string,
     *   cuit?: string|null,
     *   name?: string,
     *   tax_condition?: string,
     *   address?: string,
     *   message?: string
     * }
     */
    public function lookupByDocument(string $rawDocument): array
    {
        $digits = preg_replace('/\D/', '', $rawDocument);
        $len = strlen($digits);

        if ($len < 7 || ($len > 8 && $len !== 11)) {
            return [
                'found' => false,
                'source' => null,
                'message' => 'El documento debe ser un DNI (7-8 dígitos) o CUIT/CUIL (11 dígitos).',
            ];
        }

        if ($len === 11 && ! AfipHelper::validateCuit($digits)) {
            return [
                'found' => false,
                'source' => null,
                'message' => 'El CUIT/CUIL ingresado no supera la validación de Módulo 11.',
            ];
        }

        $targetDni = $len === 11
            ? ltrim(substr($digits, 2, 8), '0')
            : ltrim($digits, '0');

        // ── CAPA 1: Padrón Local de Clientes (`customers`) ──────────────────
        $localCustomer = $this->findInLocalCustomers($digits, $targetDni);
        if ($localCustomer !== null) {
            return $localCustomer;
        }

        // ── CAPA 2: Historial de Facturas Emitidas (`electronic_invoices`) ──
        $historyMatch = $this->findInInvoiceHistory($digits, $targetDni);
        if ($historyMatch !== null) {
            return $historyMatch;
        }

        // ── CAPA 3: Padrón Oficial de ARCA Online (A13 / A5) ────────────────
        $afipEnabled = filter_var(
            BusinessSetting::where('key', 'afip_enabled')->value('value'),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $afipEnabled) {
            return [
                'found' => false,
                'source' => null,
                'message' => 'Facturación ARCA desactivada en configuración.',
            ];
        }

        $environment = (string) (BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing');
        $cacheKey = "afip_padron_v1_{$environment}_{$digits}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['found'])) {
            return $cached;
        }

        $arcaResult = $this->queryArcaPadronOnline($digits, $len, $environment);
        if ($arcaResult !== null && ($arcaResult['found'] ?? false) === true) {
            Cache::put($cacheKey, $arcaResult, 86400); // 24 horas

            return $arcaResult;
        }

        return [
            'found' => false,
            'source' => null,
            'message' => 'No se encontraron datos adicionales en el padrón para este documento.',
        ];
    }

    /**
     * Busca coincidencia exacta o cruzada (DNI <-> CUIT) en la tabla `customers`.
     */
    protected function findInLocalCustomers(string $digits, string $targetDni): ?array
    {
        $customers = Customer::where('is_active', true)->get();

        // 1. Coincidencia exacta
        foreach ($customers as $c) {
            $cDoc = preg_replace('/\D/', '', (string) $c->document_number);
            if ($cDoc === $digits && $cDoc !== '') {
                return $this->formatCustomerMatch($c, $digits);
            }
        }

        // 2. Coincidencia cruzada DNI (7-8 dígitos) <-> CUIT (8 dígitos centrales)
        if ($targetDni !== '') {
            foreach ($customers as $c) {
                $cDoc = preg_replace('/\D/', '', (string) $c->document_number);
                if (strlen($cDoc) === 11) {
                    $cDni = ltrim(substr($cDoc, 2, 8), '0');
                    if ($cDni === $targetDni) {
                        return $this->formatCustomerMatch($c, $digits);
                    }
                } elseif (strlen($cDoc) >= 7 && strlen($cDoc) <= 8) {
                    if (ltrim($cDoc, '0') === $targetDni) {
                        return $this->formatCustomerMatch($c, $digits);
                    }
                }
            }
        }

        return null;
    }

    protected function formatCustomerMatch(Customer $c, string $queriedDigits): array
    {
        $cDoc = preg_replace('/\D/', '', (string) $c->document_number);

        return [
            'found' => true,
            'source' => 'local_customer',
            'customer_id' => $c->id,
            'doc_type' => strlen($queriedDigits) === 11 ? 80 : ($c->document_type ?: 96),
            'doc_number' => $queriedDigits,
            'cuit' => strlen($cDoc) === 11 ? $cDoc : (strlen($queriedDigits) === 11 ? $queriedDigits : null),
            'name' => $c->name,
            'tax_condition' => $c->tax_condition ?: 'consumidor_final',
            'address' => trim((string) ($c->fiscal_address ?: $c->delivery_address ?: '')),
        ];
    }

    /**
     * Busca en el historial de `electronic_invoices` si el DNI o CUIT ya fue facturado antes con nombre real.
     */
    protected function findInInvoiceHistory(string $digits, string $targetDni): ?array
    {
        $recentInvoices = ElectronicInvoice::query()
            ->whereNotNull('receiver_name')
            ->where('receiver_name', '!=', '')
            ->whereRaw('LOWER(TRIM(receiver_name)) != ?', ['consumidor final'])
            ->orderByDesc('id')
            ->limit(300)
            ->get(['sale_id', 'doc_type', 'doc_number', 'receiver_name', 'receiver_address', 'receiver_tax_condition']);

        foreach ($recentInvoices as $inv) {
            $invDoc = preg_replace('/\D/', '', (string) $inv->doc_number);
            if ($invDoc === '' || $invDoc === '0') {
                continue;
            }

            $isMatch = ($invDoc === $digits);
            if (! $isMatch && $targetDni !== '') {
                if (strlen($invDoc) === 11 && ltrim(substr($invDoc, 2, 8), '0') === $targetDni) {
                    $isMatch = true;
                } elseif (strlen($invDoc) >= 7 && strlen($invDoc) <= 8 && ltrim($invDoc, '0') === $targetDni) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                return [
                    'found' => true,
                    'source' => 'invoice_history',
                    'customer_id' => null,
                    'doc_type' => strlen($digits) === 11 ? 80 : 96,
                    'doc_number' => $digits,
                    'cuit' => strlen($invDoc) === 11 ? $invDoc : (strlen($digits) === 11 ? $digits : null),
                    'name' => trim((string) $inv->receiver_name),
                    'tax_condition' => $inv->receiver_tax_condition ?: 'consumidor_final',
                    'address' => trim((string) ($inv->receiver_address ?? '')),
                ];
            }
        }

        return null;
    }

    /**
     * Consulta los Web Services SOAP de Padrón de ARCA (`ws_sr_padron_a13` y `ws_sr_constancia_inscripto`).
     */
    protected function queryArcaPadronOnline(string $digits, int $len, string $environment): ?array
    {
        $issuerCuit = preg_replace('/\D/', '', (string) BusinessSetting::where('key', 'afip_cuit')->value('value'));
        if (empty($issuerCuit)) {
            return null;
        }

        // 1. Intentar con ws_sr_padron_a13 (soporta búsqueda por DNI y por CUIT)
        try {
            $ticketA13 = $this->wsaaService->getAccessTicket('ws_sr_padron_a13');
            $endpointA13 = $environment === 'production'
                ? self::URL_PADRON_A13_PROD
                : self::URL_PADRON_A13_HOMO;

            $targetCuit = $len === 11
                ? $digits
                : $this->resolveCuitByDniInA13($endpointA13, $ticketA13, $issuerCuit, $digits);

            if ($targetCuit !== null) {
                $persona = $this->getPersonaFromPadronSoap(
                    $endpointA13,
                    'http://a13.soap.ws.server.puc.sr/',
                    'getPersona',
                    $ticketA13,
                    $issuerCuit,
                    $targetCuit,
                    $digits
                );
                if ($persona !== null) {
                    return $persona;
                }
            }
        } catch (Throwable $e) {
            Log::debug('ARCA Padrón A13 no disponible o no delegado: '.$e->getMessage());
        }

        // 2. Fallback a ws_sr_constancia_inscripto (Padrón A5)
        try {
            $ticketA5 = $this->wsaaService->getAccessTicket('ws_sr_constancia_inscripto');
            $endpointA5 = $environment === 'production'
                ? self::URL_PADRON_A5_PROD
                : self::URL_PADRON_A5_HOMO;

            $candidateCuits = $len === 11
                ? [$digits]
                : $this->generateCandidateCuitsForDni($digits);

            foreach ($candidateCuits as $candidateCuit) {
                $persona = $this->getPersonaFromPadronSoap(
                    $endpointA5,
                    'http://a5.soap.ws.server.puc.sr/',
                    'getPersona_v2',
                    $ticketA5,
                    $issuerCuit,
                    $candidateCuit,
                    $digits
                );
                if ($persona !== null) {
                    return $persona;
                }
            }
        } catch (Throwable $e) {
            Log::debug('ARCA Padrón A5 (Constancia Inscripto) no disponible: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Traduce un DNI (7 u 8 dígitos) a su CUIT/CUIL activo en ARCA usando `getIdPersonaListByDocumento` de Padrón A13.
     */
    protected function resolveCuitByDniInA13(
        string $endpoint,
        array $ticket,
        string $issuerCuit,
        string $dni
    ): ?string {
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:a13="http://a13.soap.ws.server.puc.sr/">'.
            '<soapenv:Header/>'.
            '<soapenv:Body>'.
            '<a13:getIdPersonaListByDocumento>'.
            '<token>'.htmlspecialchars($ticket['token'], ENT_XML1).'</token>'.
            '<sign>'.htmlspecialchars($ticket['sign'], ENT_XML1).'</sign>'.
            '<cuitRepresentada>'.$issuerCuit.'</cuitRepresentada>'.
            '<documento>'.$dni.'</documento>'.
            '</a13:getIdPersonaListByDocumento>'.
            '</soapenv:Body>'.
            '</soapenv:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '""',
        ])->timeout(6)->send('POST', $endpoint, ['body' => $soap]);

        if (! $response->successful()) {
            return null;
        }

        $xml = $response->body();
        if (preg_match('/<idPersona>(\d{11})<\/idPersona>/', $xml, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Consulta `getPersona` (A13) o `getPersona_v2` (A5) en ARCA y parsea el XML devuelto.
     */
    protected function getPersonaFromPadronSoap(
        string $endpoint,
        string $namespace,
        string $method,
        array $ticket,
        string $issuerCuit,
        string $targetCuit,
        string $queriedDigits
    ): ?array {
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="'.$namespace.'">'.
            '<soapenv:Header/>'.
            '<soapenv:Body>'.
            '<ns:'.$method.'>'.
            '<token>'.htmlspecialchars($ticket['token'], ENT_XML1).'</token>'.
            '<sign>'.htmlspecialchars($ticket['sign'], ENT_XML1).'</sign>'.
            '<cuitRepresentada>'.$issuerCuit.'</cuitRepresentada>'.
            '<idPersona>'.$targetCuit.'</idPersona>'.
            '</ns:'.$method.'>'.
            '</soapenv:Body>'.
            '</soapenv:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '""',
        ])->timeout(6)->send('POST', $endpoint, ['body' => $soap]);

        if (! $response->successful()) {
            return null;
        }

        return $this->parsePadronPersonaXml($response->body(), $targetCuit, $queriedDigits);
    }

    /**
     * Parsea la respuesta XML de `personaServiceA13` o `personaServiceA5`.
     */
    public function parsePadronPersonaXml(string $xml, string $targetCuit, string $queriedDigits): ?array
    {
        if (str_contains($xml, '<faultstring>') || str_contains($xml, '<errorConstancia>')) {
            return null;
        }

        $razonSocial = $this->extractXmlTag($xml, 'razonSocial');
        $apellido = $this->extractXmlTag($xml, 'apellido');
        $nombre = $this->extractXmlTag($xml, 'nombre');

        $fullName = '';
        if ($razonSocial !== '') {
            $fullName = $razonSocial;
        } elseif ($apellido !== '' || $nombre !== '') {
            $fullName = trim($apellido.' '.$nombre);
        }

        if ($fullName === '') {
            return null;
        }

        // Formatear nombre en Title Case legible si viene todo en mayúsculas
        $formattedName = mb_convert_case(mb_strtolower($fullName, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        // Preservar siglas societarias comunes en mayúsculas
        $formattedName = preg_replace_callback(
            '/\b(S\.?A\.?|S\.?R\.?L\.?|S\.?A\.?S\.?|S\.?H\.?)\b/iu',
            fn (array $m) => mb_strtoupper($m[0], 'UTF-8'),
            $formattedName
        ) ?? $formattedName;

        // Determinar condición frente al IVA
        $taxCondition = 'consumidor_final';
        if (str_contains($xml, '<datosMonotributo>') || str_contains($xml, '<categoriaMonotributo>')) {
            $taxCondition = 'monotributo';
        } elseif (preg_match('/<idImpuesto>\s*30\s*<\/idImpuesto>/', $xml)) {
            $taxCondition = 'responsable_inscripto';
        } elseif (preg_match('/<idImpuesto>\s*32\s*<\/idImpuesto>/', $xml)) {
            $taxCondition = 'exento';
        }

        // Extraer domicilio fiscal / real
        $direccion = $this->extractXmlTag($xml, 'direccion');
        $localidad = $this->extractXmlTag($xml, 'localidad');
        $provincia = $this->extractXmlTag($xml, 'descripcionProvincia');

        $addressParts = array_filter([
            $direccion !== '' ? mb_convert_case(mb_strtolower($direccion, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            $localidad !== '' ? mb_convert_case(mb_strtolower($localidad, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            $provincia !== '' ? mb_convert_case(mb_strtolower($provincia, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
        ]);
        $address = implode(', ', $addressParts);

        $isCuitQuery = strlen($queriedDigits) === 11;
        $resolvedDocType = ($isCuitQuery || $taxCondition === 'responsable_inscripto' || $taxCondition === 'monotributo')
            ? 80
            : 96;
        $resolvedDocNumber = $resolvedDocType === 80 ? $targetCuit : $queriedDigits;

        return [
            'found' => true,
            'source' => 'arca_padron',
            'customer_id' => null,
            'doc_type' => $resolvedDocType,
            'doc_number' => $resolvedDocNumber,
            'cuit' => $targetCuit,
            'name' => $formattedName,
            'tax_condition' => $taxCondition,
            'address' => $address,
        ];
    }

    /**
     * Genera los CUIT/CUIL candidatos matemáticamente válidos (Módulo 11) para un DNI de 7 u 8 dígitos.
     *
     * @return list<string>
     */
    public function generateCandidateCuitsForDni(string $dni): array
    {
        $paddedDni = str_pad(preg_replace('/\D/', '', $dni), 8, '0', STR_PAD_LEFT);
        if (strlen($paddedDni) !== 8) {
            return [];
        }

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $candidates = [];

        foreach (['20', '27', '23', '24'] as $prefix) {
            $base10 = $prefix.$paddedDni;
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $sum += ((int) $base10[$i]) * $multipliers[$i];
            }
            $mod = $sum % 11;
            $checkDigit = $mod === 0 ? 0 : ($mod === 1 ? -1 : 11 - $mod);
            if ($checkDigit >= 0 && $checkDigit <= 9) {
                $candidates[] = $base10.$checkDigit;
            }
        }

        return array_values(array_unique($candidates));
    }

    protected function extractXmlTag(string $xml, string $tag): string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return '';
    }
}
