<?php

declare(strict_types=1);

namespace App\Services\Afip;

use App\Models\BusinessSetting;
use App\Models\Sale;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class AfipWsfeService
{
    public const URL_WSFE_HOMO = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';

    public const URL_WSFE_PROD = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';

    /**
     * Claves de configuración fiscal leídas en una única consulta por emisión.
     *
     * @var array<int, string>
     */
    public const FISCAL_SETTING_KEYS = [
        'afip_cuit',
        'afip_environment',
        'afip_pto_vta',
        'tax_condition',
        'afip_cf_max_limit',
        'is_iibb_perception_agent',
        'default_iibb_perception_rate',
        'iibb_calculation_base',
        'iibb_subject_tax_conditions',
        'demo_mode_active',
    ];

    protected AfipWsaaService $wsaaService;

    protected ?Client $customHttpClient = null;

    protected ?Client $sharedWsfeClient = null;

    protected ?string $sharedWsfeClientSignature = null;

    public function __construct(?AfipWsaaService $wsaaService = null, ?Client $httpClient = null)
    {
        $this->wsaaService = $wsaaService ?? new AfipWsaaService;
        $this->customHttpClient = $httpClient;
    }

    /**
     * Lee todas las claves fiscales de `business_settings` en una sola consulta SQL.
     *
     * @return array<string, string|null>
     */
    protected function loadFiscalSettingsMap(): array
    {
        return BusinessSetting::whereIn('key', self::FISCAL_SETTING_KEYS)
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * Retorna una instancia reutilizable de GuzzleHttp\Client con TCP/TLS Keep-Alive
     * entre FECompUltimoAutorizado, FECAESolicitar y FECompConsultar.
     */
    protected function getSharedWsfeClient(): Client
    {
        if ($this->customHttpClient !== null) {
            return $this->customHttpClient;
        }

        $factory = Http::getFacadeRoot();
        $signature = 'none';
        if ($factory instanceof HttpFactory) {
            $inspector = \Closure::bind(function (): string {
                $stubCount = $this->stubCallbacks ? count($this->stubCallbacks) : 0;

                return spl_object_id($this).':'.$stubCount.':'.($this->recording ? '1' : '0').':'.($this->preventStrayRequests ? '1' : '0');
            }, $factory, HttpFactory::class);
            $signature = $inspector();
        }

        if ($this->sharedWsfeClient === null || $this->sharedWsfeClientSignature !== $signature) {
            $curlOptions = defined('CURLOPT_TCP_KEEPALIVE') ? [\CURLOPT_TCP_KEEPALIVE => 1] : [];
            $this->sharedWsfeClient = Http::withOptions([
                'headers' => ['Connection' => 'keep-alive'],
                'curl' => $curlOptions,
            ])->buildClient();
            $this->sharedWsfeClientSignature = $signature;
        }

        return $this->sharedWsfeClient;
    }

    /**
     * Consulta el último número de comprobante autorizado para un punto de venta y tipo.
     *
     * @param  array{token: string, sign: string, expiration_time?: string|null}|null  $ticket
     */
    public function getLastVoucherNumber(
        int $pointOfSale,
        int $voucherType,
        ?array $ticket = null,
        ?int $cuit = null,
        ?string $endpoint = null
    ): int {
        if ($ticket === null || $cuit === null || $endpoint === null) {
            $settings = $this->loadFiscalSettingsMap();
            $rawCuit = $settings['afip_cuit'] ?? null;
            $env = $settings['afip_environment'] ?? 'testing';
            $ticket ??= $this->wsaaService->getAccessTicket('wsfe', $rawCuit, $env);
            $cuit ??= (int) preg_replace('/\D/', '', (string) $rawCuit);
            $endpoint ??= $this->getWsfeEndpoint($env);
        }

        $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>'.
            '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'.
            '<soap:Body>'.
            '<FECompUltimoAutorizado xmlns="http://ar.gov.afip.dif.FEV1/">'.
            '<Auth>'.
            '<Token>'.htmlspecialchars($ticket['token'], ENT_XML1).'</Token>'.
            '<Sign>'.htmlspecialchars($ticket['sign'], ENT_XML1).'</Sign>'.
            '<Cuit>'.$cuit.'</Cuit>'.
            '</Auth>'.
            '<PtoVta>'.$pointOfSale.'</PtoVta>'.
            '<CbteTipo>'.$voucherType.'</CbteTipo>'.
            '</FECompUltimoAutorizado>'.
            '</soap:Body>'.
            '</soap:Envelope>';

        $response = Http::setClient($this->getSharedWsfeClient())
            ->withHeaders([
                'Connection' => 'keep-alive',
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"http://ar.gov.afip.dif.FEV1/FECompUltimoAutorizado"',
            ])
            ->timeout(10)
            ->send('POST', $endpoint, [
                'body' => $soapEnvelope,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
        }

        $xml = $response->body();

        if (str_contains($xml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $xml, $m);
            throw new RuntimeException('AFIP WSFE Fault: '.($m[1] ?? 'Error desconocido'));
        }

        if (preg_match('/<Errors>(.*?)<\/Errors>/s', $xml, $errMatch)) {
            preg_match_all('/<Msg>(.*?)<\/Msg>/s', $errMatch[1], $msgMatch);
            $msg = implode('; ', $msgMatch[1] ?? ['Error al consultar último comprobante']);
            throw new RuntimeException("AFIP WSFE Error: {$msg}");
        }

        if (preg_match('/<CbteNro>(\d+)<\/CbteNro>/', $xml, $nroMatch)) {
            return (int) $nroMatch[1];
        }

        return 0;
    }

    /**
     * Consulta en AFIP WSFEv1 (FECompConsultar) si un comprobante específico ya fue emitido.
     * Útil para recuperar el CAE ante cortes de red o timeouts durante FECAESolicitar.
     *
     * @param  array{token: string, sign: string, expiration_time?: string|null}|null  $ticket
     * @return array{cae: string, cae_expiration: string, resultado: string, cbte_nro: int, imp_total: float, doc_type: int|null, doc_nro: int|null}|null
     */
    public function consultVoucher(
        int $pointOfSale,
        int $voucherType,
        int $voucherNumber,
        ?array $ticket = null,
        ?int $cuit = null,
        ?string $endpoint = null
    ): ?array {
        if ($ticket === null || $cuit === null || $endpoint === null) {
            $settings = $this->loadFiscalSettingsMap();
            $rawCuit = $settings['afip_cuit'] ?? null;
            $env = $settings['afip_environment'] ?? 'testing';
            $ticket ??= $this->wsaaService->getAccessTicket('wsfe', $rawCuit, $env);
            $cuit ??= (int) preg_replace('/\D/', '', (string) $rawCuit);
            $endpoint ??= $this->getWsfeEndpoint($env);
        }

        $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>'.
            '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'.
            '<soap:Body>'.
            '<FECompConsultar xmlns="http://ar.gov.afip.dif.FEV1/">'.
            '<Auth>'.
            '<Token>'.htmlspecialchars($ticket['token'], ENT_XML1).'</Token>'.
            '<Sign>'.htmlspecialchars($ticket['sign'], ENT_XML1).'</Sign>'.
            '<Cuit>'.$cuit.'</Cuit>'.
            '</Auth>'.
            '<FeCompConsReq>'.
            '<CbteTipo>'.$voucherType.'</CbteTipo>'.
            '<CbteNro>'.$voucherNumber.'</CbteNro>'.
            '<PtoVta>'.$pointOfSale.'</PtoVta>'.
            '</FeCompConsReq>'.
            '</FECompConsultar>'.
            '</soap:Body>'.
            '</soap:Envelope>';

        $response = Http::setClient($this->getSharedWsfeClient())
            ->withHeaders([
                'Connection' => 'keep-alive',
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"http://ar.gov.afip.dif.FEV1/FECompConsultar"',
            ])
            ->timeout(10)
            ->send('POST', $endpoint, [
                'body' => $soapEnvelope,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
        }

        $xml = $response->body();

        if (str_contains($xml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $xml, $m);
            throw new RuntimeException('AFIP WSFE Fault: '.($m[1] ?? 'Error desconocido'));
        }

        // Código 602 en FECompConsultar indica que el comprobante no consta en AFIP
        if (preg_match('/<Errors>(.*?)<\/Errors>/s', $xml, $errMatch)) {
            if (preg_match('/<Code>\s*602\s*<\/Code>/', $errMatch[1])) {
                return null;
            }
            preg_match_all('/<Msg>(.*?)<\/Msg>/s', $errMatch[1], $msgMatch);
            $msg = implode('; ', $msgMatch[1] ?? ['Error al consultar comprobante en AFIP']);
            throw new RuntimeException("AFIP WSFE Error: {$msg}");
        }

        if (! preg_match('/<CodAutorizacion>(\d+)<\/CodAutorizacion>/', $xml, $caeMatch)) {
            return null;
        }

        preg_match('/<FchVto>(\d{8})<\/FchVto>/', $xml, $vtoMatch);
        preg_match('/<Resultado>([A-Z])<\/Resultado>/', $xml, $resMatch);
        preg_match('/<ImpTotal>([\d\.]+)<\/ImpTotal>/', $xml, $impMatch);
        preg_match('/<DocTipo>(\d+)<\/DocTipo>/', $xml, $docTipoMatch);
        preg_match('/<DocNro>(\d+)<\/DocNro>/', $xml, $docNroMatch);

        $rawVto = $vtoMatch[1] ?? '';
        $caeExpiration = ! empty($rawVto)
            ? substr($rawVto, 0, 4).'-'.substr($rawVto, 4, 2).'-'.substr($rawVto, 6, 2)
            : date('Y-m-d', strtotime('+10 days'));

        return [
            'cae' => $caeMatch[1],
            'cae_expiration' => $caeExpiration,
            'resultado' => $resMatch[1] ?? 'A',
            'cbte_nro' => $voucherNumber,
            'imp_total' => isset($impMatch[1]) ? (float) $impMatch[1] : 0.0,
            'doc_type' => isset($docTipoMatch[1]) ? (int) $docTipoMatch[1] : null,
            'doc_nro' => isset($docNroMatch[1]) ? (int) $docNroMatch[1] : null,
        ];
    }

    /**
     * Solicita autorización fiscal (CAE) a WSFEv1 para una venta del sistema POS.
     *
     * @param  Sale  $sale  Venta a facturar
     * @param  array  $options  Opciones adicionales (voucher_type, doc_type, doc_number, etc.)
     * @return array Datos de la factura electrónica autorizada
     */
    public function authorizeInvoice(Sale $sale, array $options = []): array
    {
        $fiscalSettings = $this->loadFiscalSettingsMap();

        $rawDemo = $fiscalSettings['demo_mode_active'] ?? null;
        if ($rawDemo === '1' || $rawDemo === 'true' || $rawDemo === true || $rawDemo === 1) {
            throw new RuntimeException(
                'Estás en Modo Datos de Ejemplo. Para emitir comprobantes fiscales ante ARCA (en Homologación o Producción), primero debés limpiar los datos de ejemplo y comenzar con tu negocio real.'
            );
        }

        $rawCuit = $fiscalSettings['afip_cuit'] ?? null;
        $environment = $fiscalSettings['afip_environment'] ?? 'testing';
        $ticket = $this->wsaaService->getAccessTicket('wsfe', $rawCuit, $environment);

        $issuerCuit = (int) preg_replace('/\D/', '', (string) $rawCuit);
        if (empty($issuerCuit)) {
            throw new InvalidArgumentException('El CUIT del emisor no está configurado en Ajustes de Integración.');
        }

        $ptoVta = (int) ($options['point_of_sale'] ?? ($fiscalSettings['afip_pto_vta'] ?? null) ?? 1);
        if ($ptoVta <= 0) {
            $ptoVta = 1;
        }

        // Determinar tipo de comprobante
        $voucherType = isset($options['voucher_type']) && is_numeric($options['voucher_type'])
            ? (int) $options['voucher_type']
            : (isset($options['voucher_letter'])
                ? AfipHelper::resolveVoucherTypeFromLetter((string) $options['voucher_letter'])
                : AfipHelper::VOUCHER_FACTURA_B);

        $voucherLetter = AfipHelper::getVoucherLetter($voucherType);

        // Validar coherencia entre Condición IVA del Emisor y Clase de Comprobante (A/B vs C)
        $issuerTaxCondition = $fiscalSettings['tax_condition'] ?? null;
        if (! AfipHelper::isValidVoucherForIssuer($issuerTaxCondition, $voucherType)) {
            throw new InvalidArgumentException(
                "El tipo de comprobante Clase {$voucherLetter} no es válido para la condición frente al IVA del comercio emisor ({$issuerTaxCondition})."
            );
        }

        // Receptor
        $customer = $sale->customer;
        $docType = (int) ($options['doc_type'] ?? ($customer?->document_type ?? AfipHelper::DOC_CONSUMIDOR_FINAL));
        $rawDocNumber = $options['doc_number'] ?? ($customer?->document_number ?? '');
        $docNumber = preg_replace('/\D/', '', (string) $rawDocNumber);

        $receiverName = $options['receiver_name'] ?? ($customer?->name ?? 'Consumidor Final');
        $receiverAddress = $options['receiver_address'] ?? ($customer?->fiscal_address ?? $customer?->delivery_address ?? '');
        $defaultTaxCondition = $voucherLetter === 'A' ? 'responsable_inscripto' : 'consumidor_final';
        $receiverTaxCondition = $options['receiver_tax_condition'] ?? ($customer?->tax_condition ?? $defaultTaxCondition);
        if ($voucherLetter === 'A' && ! in_array(AfipHelper::getCondicionIvaReceptorId((string) $receiverTaxCondition), [1, 6], true)) {
            $receiverTaxCondition = 'responsable_inscripto';
        } elseif ($voucherLetter === 'B' && ! in_array(AfipHelper::getCondicionIvaReceptorId((string) $receiverTaxCondition), [4, 5], true)) {
            $receiverTaxCondition = 'consumidor_final';
        }

        $prelimCondId = AfipHelper::getCondicionIvaReceptorId((string) $receiverTaxCondition, $voucherLetter);

        // Coherencia bidireccional: jamás enviar DocTipo = 99 con un documento cargado
        if ($docType === AfipHelper::DOC_CONSUMIDOR_FINAL && $docNumber !== '' && $docNumber !== '0') {
            $docType = strlen($docNumber) === 11
                ? AfipHelper::DOC_CUIT
                : AfipHelper::DOC_DNI;
        }

        // Validaciones por tipo de comprobante
        // Clase A (Factura A, Nota de Débito A, Nota de Crédito A): CUIT receptor obligatorio
        if ($voucherLetter === 'A') {
            $docType = AfipHelper::DOC_CUIT;
            if (! AfipHelper::validateCuit($docNumber)) {
                throw new InvalidArgumentException("Para emitir comprobantes clase A, el CUIT del receptor es obligatorio y debe ser válido ({$docNumber}).");
            }
        } elseif ($prelimCondId !== 5) {
            // ARCA RG 5616: Receptores Exento (4), Responsable Inscripto (1) o Monotributo (6) requieren CUIT (80)
            $docType = AfipHelper::DOC_CUIT;
            if (! AfipHelper::validateCuit($docNumber)) {
                throw new InvalidArgumentException("Para receptor con condición fiscal {$receiverTaxCondition} es obligatorio informar un CUIT válido ({$docNumber}).");
            }
        } else {
            // Consumidor Final en Clase B o C: si el documento está vacío o '0', normalizar a DocTipo 99
            if ($docNumber === '' || $docNumber === '0') {
                $docType = AfipHelper::DOC_CONSUMIDOR_FINAL;
                $docNumber = '';
            }

            if ($docType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
                // Verificar tope de Consumidor Final anónimo
                $anonymousLimit = (float) ($fiscalSettings['afip_cf_max_limit'] ?? 340000.00);
                $grossSaleTotal = round((float) $sale->total + (float) $sale->total_surcharge + (float) $sale->shipping_cost, 2);
                if ($grossSaleTotal >= $anonymousLimit && empty($docNumber)) {
                    throw new InvalidArgumentException(
                        'El importe total supera el límite de consumidor final anónimo ($'.number_format($anonymousLimit, 2).'). Debe identificar al cliente con DNI o CUIT.'
                    );
                }
                if (empty($docNumber)) {
                    $docNumber = '0';
                }
            } elseif ($docType === AfipHelper::DOC_CUIT) {
                if (! AfipHelper::validateCuit($docNumber)) {
                    throw new InvalidArgumentException("El CUIT ingresado es inválido según Módulo 11 ({$docNumber}).");
                }
            } elseif ($docType === AfipHelper::DOC_CUIL) {
                if (! AfipHelper::validateCuit($docNumber)) {
                    throw new InvalidArgumentException("El CUIL ingresado es inválido según Módulo 11 ({$docNumber}).");
                }
            } elseif ($docType === AfipHelper::DOC_DNI) {
                if (! AfipHelper::validateDni($docNumber)) {
                    throw new InvalidArgumentException("El DNI ingresado es inválido ({$docNumber}).");
                }
            }
        }

        $numericDocNro = (int) $docNumber;
        $isCreditNote = ! empty($options['cbtes_asoc']);

        // Percepción IIBB
        $hasExplicitAmountOption = array_key_exists('iibb_perception_amount', $options) && $options['iibb_perception_amount'] !== null;
        $hasExplicitRateOption = array_key_exists('iibb_perception_rate', $options) && $options['iibb_perception_rate'] !== null;
        $hasExplicitZeroRateInSale = $sale->iibb_perception_rate !== null
            && (float) $sale->iibb_perception_rate <= 0.0
            && round((float) ($sale->iibb_perception_amount ?? 0.0), 2) <= 0.0;
        $hasExplicitPerceptionOption = $hasExplicitAmountOption
            || $hasExplicitRateOption
            || $hasExplicitZeroRateInSale;

        $existingSalePerception = round((float) ($sale->iibb_perception_amount ?? 0.0), 2);
        $perceptionAmount = round((float) ($options['iibb_perception_amount'] ?? $sale->iibb_perception_amount ?? 0.0), 2);
        $perceptionRate = (float) ($options['iibb_perception_rate'] ?? $sale->iibb_perception_rate ?? 0.0);

        $isAgentRaw = $fiscalSettings['is_iibb_perception_agent'] ?? null;
        $isIibbAgentFlag = $isAgentRaw === '1' || $isAgentRaw === 'true' || $isAgentRaw === true || $isAgentRaw === 1;
        $isIssuerEligibleForIibb = BusinessSetting::isIssuerEligibleForIibb($issuerTaxCondition) && $voucherLetter !== 'C';
        $isIibbAgent = $isIibbAgentFlag && $isIssuerEligibleForIibb;

        if (! $isCreditNote && ! $isIibbAgent) {
            $perceptionAmount = 0.0;
            $perceptionRate = 0.0;
            $hasExplicitPerceptionOption = false;
        }
        $iibbCalculationBase = BusinessSetting::sanitizeIibbCalculationBase(
            $fiscalSettings['iibb_calculation_base'] ?? null
        );
        $iibbSubjectTaxConditions = array_key_exists('iibb_subject_tax_conditions', $fiscalSettings)
            && $fiscalSettings['iibb_subject_tax_conditions'] !== null
            ? BusinessSetting::sanitizeIibbSubjectTaxConditions($fiscalSettings['iibb_subject_tax_conditions'])
            : ['responsable_inscripto'];

        $receiverCondId = AfipHelper::getCondicionIvaReceptorId((string) $receiverTaxCondition, $voucherLetter);
        $rawCondNormalized = strtolower(trim((string) $receiverTaxCondition));
        $rawCondNormalized = str_replace(['_', '-'], ' ', $rawCondNormalized);
        $receiverCondKey = match (true) {
            str_contains($rawCondNormalized, 'inscripto') => 'responsable_inscripto',
            str_contains($rawCondNormalized, 'monotributo') => 'monotributo',
            str_contains($rawCondNormalized, 'exento') => 'exento',
            str_contains($rawCondNormalized, 'consumidor final') => 'consumidor_final',
            $receiverCondId === 1 => 'responsable_inscripto',
            $receiverCondId === 6 => 'monotributo',
            $receiverCondId === 4 => 'exento',
            default => 'consumidor_final',
        };
        $isConsumidorFinal = $receiverCondKey === 'consumidor_final';
        if ($isConsumidorFinal && ! $isCreditNote && $existingSalePerception <= 0.0) {
            $perceptionAmount = 0.0;
            $perceptionRate = 0.0;
        }
        $isFiscalCuitSubjectToIibb = ! $isConsumidorFinal
            && in_array($receiverCondKey, $iibbSubjectTaxConditions, true)
            && ($voucherLetter === 'A' || $docType === AfipHelper::DOC_CUIT);
        $hasExplicitlyDisabledCustomRate = $customer !== null
            && ! $customer->applies_iibb_perception
            && (float) ($customer->iibb_perception_rate ?? 0.0) > 0.0;

        $shouldAutoCalculateRate = false;
        // Auto-cálculo de tasa cuando el comercio es agente IIBB y no hubo override explícito (ni es Nota de Crédito)
        // Consumidor Final nunca recibe percepción IIBB automática.
        if (! $hasExplicitPerceptionOption && ! $isCreditNote && $perceptionAmount <= 0.0 && $isIibbAgent && ! $isConsumidorFinal) {
            $defaultIibbRate = (float) ($fiscalSettings['default_iibb_perception_rate'] ?? 0.0);

            if (! empty($customer?->applies_iibb_perception)) {
                if ($perceptionRate <= 0.0) {
                    $custRate = (float) ($customer->iibb_perception_rate ?? 0.0);
                    $perceptionRate = $custRate > 0.0 ? $custRate : $defaultIibbRate;
                }
                $shouldAutoCalculateRate = $perceptionRate > 0.0;
            } elseif ($isFiscalCuitSubjectToIibb && ! $hasExplicitlyDisabledCustomRate && $defaultIibbRate > 0.0) {
                if ($perceptionRate <= 0.0) {
                    $perceptionRate = $defaultIibbRate;
                }
                $shouldAutoCalculateRate = $perceptionRate > 0.0;
            }
        }

        $shouldComputeAmountFromRate = $perceptionAmount <= 0.0
            && $perceptionRate > 0.0
            && (
                $shouldAutoCalculateRate
                || ($hasExplicitRateOption && ! $hasExplicitAmountOption)
                || ($isIibbAgent && ! $isConsumidorFinal && (! empty($customer?->applies_iibb_perception) || ($isFiscalCuitSubjectToIibb && ! $hasExplicitlyDisabledCustomRate)))
            );

        // Cálculos e invariante matemático
        $extraAmount = round((float) $sale->total_surcharge + (float) $sale->shipping_cost, 2);
        $totalAmount = round((float) $sale->total + $extraAmount, 2);
        $ivaBreakdown = [];
        $ivaXml = '';
        $tributeBaseImp = 0.00;

        if ($voucherLetter === 'C') {
            // REGLA CRÍTICA MONOTRIBUTO (CLASE C: Factura C, Nota de Débito C, Nota de Crédito C):
            // 1. ImpIVA debe ser estrictamente 0.00
            // 2. ImpNeto es igual al total base (restando percepción si el total de la venta ya la contenía)
            // 3. El nodo <Iva> DEBE SER ESTRICTAMENTE OMITIDO
            $baseSaleAmount = $totalAmount;
            if ($existingSalePerception > 0.0 && $baseSaleAmount > $existingSalePerception) {
                $netAmount = round($baseSaleAmount - $existingSalePerception, 2);
            } else {
                $netAmount = $baseSaleAmount;
            }

            // En Factura C no hay IVA discriminado: ambas bases (neta y subtotal) coinciden
            $tributeBaseImp = $netAmount;
            $ivaAmount = 0.00;

            if ($shouldComputeAmountFromRate) {
                $perceptionAmount = round($tributeBaseImp * ($perceptionRate / 100), 2);
            }

            if ($perceptionAmount > 0.0 && $perceptionRate <= 0.0 && $tributeBaseImp > 0.0) {
                $perceptionRate = round(($perceptionAmount / $tributeBaseImp) * 100, 2);
            }
        } else {
            // FACTURAS A Y B: Desglose por alícuotas
            $items = $sale->items()->get();
            $groupedByAliquot = [];

            foreach ($items as $item) {
                $rate = (float) ($item->iva_rate ?? 21.00);
                $aliquotId = AfipHelper::getAliquotId($rate);
                $subtotal = (float) $item->subtotal;

                if (! isset($groupedByAliquot[$aliquotId])) {
                    $groupedByAliquot[$aliquotId] = [
                        'rate' => $rate,
                        'subtotal' => 0.0,
                    ];
                }
                $groupedByAliquot[$aliquotId]['subtotal'] += $subtotal;
            }

            if ($extraAmount > 0) {
                $generalAliquotId = AfipHelper::ALIQUOT_21_PERCENT;
                if (! isset($groupedByAliquot[$generalAliquotId])) {
                    $groupedByAliquot[$generalAliquotId] = [
                        'rate' => 21.00,
                        'subtotal' => 0.0,
                    ];
                }
                $groupedByAliquot[$generalAliquotId]['subtotal'] += $extraAmount;
            }

            if (empty($groupedByAliquot)) {
                // Fallback por si la venta no tiene items cargados
                $fallbackSubtotal = $totalAmount;
                if ($existingSalePerception > 0.0 && $totalAmount > $existingSalePerception) {
                    $fallbackSubtotal = round($totalAmount - $existingSalePerception, 2);
                }
                $groupedByAliquot[AfipHelper::ALIQUOT_21_PERCENT] = [
                    'rate' => 21.00,
                    'subtotal' => $fallbackSubtotal,
                ];
            }

            $totalNet = 0.0;
            $totalIva = 0.0;
            $aliquotResults = [];

            foreach ($groupedByAliquot as $id => $data) {
                $calc = AfipHelper::calculateNetAndIva((float) $data['subtotal'], (float) $data['rate']);
                $totalNet += $calc['net'];
                $totalIva += $calc['iva'];
                $aliquotResults[$id] = [
                    'Id' => $id,
                    'BaseImp' => $calc['net'],
                    'Importe' => $calc['iva'],
                ];
            }

            $netAmount = round($totalNet, 2);
            $ivaAmount = round($totalIva, 2);

            // Base esperada de los items para chequear redondeo
            $baseSale = $totalAmount;
            if ($existingSalePerception > 0.0 && $totalAmount > $existingSalePerception) {
                $baseSale = round($totalAmount - $existingSalePerception, 2);
            }
            $diff = round($baseSale - ($netAmount + $ivaAmount), 2);
            if ($diff !== 0.00 && abs($diff) < 1.00) {
                $firstKey = array_key_first($aliquotResults);
                if ($firstKey !== null) {
                    $adjustedGross = round((float) $groupedByAliquot[$firstKey]['subtotal'] + $diff, 2);
                    $recalc = AfipHelper::calculateNetAndIva($adjustedGross, (float) $groupedByAliquot[$firstKey]['rate']);
                    $aliquotResults[$firstKey]['BaseImp'] = $recalc['net'];
                    $aliquotResults[$firstKey]['Importe'] = $recalc['iva'];

                    $netAmount = round(array_sum(array_column($aliquotResults, 'BaseImp')), 2);
                    $ivaAmount = round(array_sum(array_column($aliquotResults, 'Importe')), 2);
                }
            }

            // Base imponible para el nodo <Tributo> según configuración del comercio:
            // - net_without_vat (default DGR): ImpNeto
            // - gross_with_vat: Subtotal con IVA incluido (ImpNeto + ImpIVA)
            $tributeBaseImp = $iibbCalculationBase === BusinessSetting::IIBB_BASE_GROSS_WITH_VAT
                ? round($netAmount + $ivaAmount, 2)
                : $netAmount;

            if ($shouldComputeAmountFromRate) {
                $perceptionAmount = round($tributeBaseImp * ($perceptionRate / 100), 2);
            } elseif (! $isCreditNote && $perceptionAmount > 0.0 && $perceptionRate > 0.0) {
                // Guarda de contingencia: si la venta ya traía percepción calculada bajo la otra base
                // antes de un cambio de configuración global, enviar BaseImp consistente con Importe/Alic.
                $expectedFromCurrentBase = round($tributeBaseImp * ($perceptionRate / 100), 2);
                if (abs($perceptionAmount - $expectedFromCurrentBase) > 0.02) {
                    $alternativeBase = $iibbCalculationBase === BusinessSetting::IIBB_BASE_GROSS_WITH_VAT
                        ? $netAmount
                        : round($netAmount + $ivaAmount, 2);
                    $expectedFromAlternativeBase = round($alternativeBase * ($perceptionRate / 100), 2);
                    if (abs($perceptionAmount - $expectedFromAlternativeBase) <= 0.02) {
                        $tributeBaseImp = $alternativeBase;
                    }
                }
            }

            if ($perceptionAmount > 0.0 && $perceptionRate <= 0.0 && $tributeBaseImp > 0.0) {
                $perceptionRate = round(($perceptionAmount / $tributeBaseImp) * 100, 2);
            }

            $ivaBreakdown = array_values($aliquotResults);

            // Construir nodo XML <Iva>
            $ivaXml = '<Iva>';
            foreach ($ivaBreakdown as $al) {
                $ivaXml .= '<AlicIva>'.
                    '<Id>'.$al['Id'].'</Id>'.
                    '<BaseImp>'.number_format($al['BaseImp'], 2, '.', '').'</BaseImp>'.
                    '<Importe>'.number_format($al['Importe'], 2, '.', '').'</Importe>'.
                    '</AlicIva>';
            }
            $ivaXml .= '</Iva>';
        }

        // Si es Nota de Crédito y se preservó el BaseImp original de la factura anulada, respetarlo
        if ($isCreditNote && isset($options['iibb_perception_base']) && (float) $options['iibb_perception_base'] > 0.0) {
            $tributeBaseImp = round((float) $options['iibb_perception_base'], 2);
        }

        // Cumplimiento estricto del invariante matemático de AFIP:
        // ImpTotal = ImpNeto + ImpIVA + ImpTrib (al centavo exacto)
        $totalAmount = round($netAmount + $ivaAmount + $perceptionAmount, 2);

        // Construir nodo XML <Tributos> si y solo si $perceptionAmount > 0
        $tributosXml = '';
        $tributesBreakdown = null;
        if ($perceptionAmount > 0.00) {
            $tributesBreakdown = [
                [
                    'Id' => 2,
                    'Desc' => 'Percepcion de Ingresos Brutos',
                    'BaseImp' => $tributeBaseImp,
                    'Alic' => $perceptionRate,
                    'Importe' => $perceptionAmount,
                ],
            ];

            $tributosXml = '<Tributos>'.
                '<Tributo>'.
                '<Id>2</Id>'.
                '<Desc>Percepcion de Ingresos Brutos</Desc>'.
                '<BaseImp>'.number_format($tributeBaseImp, 2, '.', '').'</BaseImp>'.
                '<Alic>'.number_format($perceptionRate, 2, '.', '').'</Alic>'.
                '<Importe>'.number_format($perceptionAmount, 2, '.', '').'</Importe>'.
                '</Tributo>'.
                '</Tributos>';
        }

        $cbteFch = date('Ymd');
        $endpoint = $this->getWsfeEndpoint($environment);

        // ── Determinar Concepto AFIP (1: Productos, 2: Servicios, 3: Productos y Servicios) ──
        if (isset($options['concepto']) && in_array((int) $options['concepto'], [1, 2, 3], true)) {
            $concepto = (int) $options['concepto'];
        } else {
            $saleItems = $sale->relationLoaded('items')
                ? $sale->items
                : $sale->items()->with('product:id,is_service')->get();

            $hasServices = false;
            $hasGoods = false;
            foreach ($saleItems as $saleItem) {
                $prod = $saleItem->relationLoaded('product') ? $saleItem->product : $saleItem->product()->first(['id', 'is_service']);
                if ($prod && (bool) $prod->is_service) {
                    $hasServices = true;
                } else {
                    $hasGoods = true;
                }
            }

            if ($hasServices && ! $hasGoods) {
                $concepto = 2;
            } elseif ($hasServices && $hasGoods) {
                $concepto = 3;
            } else {
                $concepto = 1;
            }
        }

        $serviceDatesXml = '';
        if ($concepto === 2 || $concepto === 3) {
            $fchServDesde = preg_replace('/\D/', '', (string) ($options['fch_serv_desde'] ?? $cbteFch));
            $fchServHasta = preg_replace('/\D/', '', (string) ($options['fch_serv_hasta'] ?? $cbteFch));
            $fchVtoPago = preg_replace('/\D/', '', (string) ($options['fch_vto_pago'] ?? $cbteFch));
            if (strlen($fchServDesde) !== 8) {
                $fchServDesde = $cbteFch;
            }
            if (strlen($fchServHasta) !== 8) {
                $fchServHasta = $cbteFch;
            }
            if (strlen($fchVtoPago) !== 8) {
                $fchVtoPago = $cbteFch;
            }
            $serviceDatesXml = '<FchServDesde>'.$fchServDesde.'</FchServDesde>'.
                '<FchServHasta>'.$fchServHasta.'</FchServHasta>'.
                '<FchVtoPago>'.$fchVtoPago.'</FchVtoPago>';
        }

        // ── Nodo CbtesAsoc (Para Notas de Crédito / Débito) ──
        // <Cuit> = CUIT del EMISOR del comprobante asociado. En este sistema el comprobante
        // asociado siempre es propio, por lo que se usa el CUIT emisor (Auth), nunca el del receptor.
        $cbtesAsocXml = '';
        if (! empty($options['cbtes_asoc']) && is_array($options['cbtes_asoc'])) {
            $cbtesAsocXml .= '<CbtesAsoc>';
            foreach ($options['cbtes_asoc'] as $asoc) {
                $asocFch = preg_replace('/\D/', '', (string) ($asoc['CbteFch'] ?? ''));
                $cbtesAsocXml .= '<CbteAsoc>'.
                    '<Tipo>'.(int) ($asoc['Tipo'] ?? 0).'</Tipo>'.
                    '<PtoVta>'.(int) ($asoc['PtoVta'] ?? 0).'</PtoVta>'.
                    '<Nro>'.(int) ($asoc['Nro'] ?? 0).'</Nro>'.
                    '<Cuit>'.$issuerCuit.'</Cuit>'.
                    (strlen($asocFch) === 8 ? '<CbteFch>'.$asocFch.'</CbteFch>' : '').
                    '</CbteAsoc>';
            }
            $cbtesAsocXml .= '</CbtesAsoc>';
        }

        // Lock atómico por CUIT + Punto de Venta + Tipo de Comprobante para evitar colisión
        // de correlatividad (FECompUltimoAutorizado -> FECAESolicitar) en peticiones concurrentes.
        $cacheDriver = config('cache.default') === 'database' ? 'file' : config('cache.default');
        /** @var \Illuminate\Contracts\Cache\Repository&\Illuminate\Contracts\Cache\LockProvider $cacheStore */
        $cacheStore = Cache::store($cacheDriver);
        $lock = $cacheStore->lock("afip_wsfe_lock:{$issuerCuit}:{$ptoVta}:{$voucherType}", 25);

        $pendingCacheKey = ! empty($sale->id)
            ? ('afip_pending_'.($isCreditNote ? 'nc' : 'sale').":{$sale->id}:{$ptoVta}:{$voucherType}")
            : null;

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Otro comprobante fiscal se está emitiendo en este punto de venta. Intente nuevamente en unos segundos.');
        }

        try {
            // Reconciliación diferida (FN-F3-01): si la misma venta tuvo un intento previo interrumpido,
            // verificamos con FECompConsultar antes de pedir un nuevo correlativo para evitar duplicar CAE.
            if ($pendingCacheKey !== null && $cacheStore->has($pendingCacheKey)) {
                $pendingData = $cacheStore->get($pendingCacheKey);
                $pendingCbteNro = (int) (is_array($pendingData) ? ($pendingData['cbte_nro'] ?? 0) : 0);
                if ($pendingCbteNro > 0) {
                    try {
                        $recovered = $this->consultVoucher($ptoVta, $voucherType, $pendingCbteNro, $ticket, $issuerCuit, $endpoint);
                        if ($recovered !== null) {
                            $docMatches = ($recovered['doc_type'] === null || $recovered['doc_type'] === (int) $docType)
                                && ($recovered['doc_nro'] === null || (int) $recovered['doc_nro'] === (int) $numericDocNro);

                            if (
                                ($recovered['resultado'] ?? '') === 'A'
                                && ! empty($recovered['cae'])
                                && $recovered['imp_total'] > 0.0
                                && abs($recovered['imp_total'] - $totalAmount) <= 0.05
                                && $docMatches
                            ) {
                                $cacheStore->forget($pendingCacheKey);

                                return $this->buildAuthorizedInvoiceResult(
                                    voucherType: $voucherType,
                                    voucherLetter: $voucherLetter,
                                    ptoVta: $ptoVta,
                                    cbteNro: $pendingCbteNro,
                                    cae: (string) $recovered['cae'],
                                    caeExpiration: (string) $recovered['cae_expiration'],
                                    docType: $docType,
                                    docNumber: (string) $docNumber,
                                    numericDocNro: $numericDocNro,
                                    receiverName: $receiverName,
                                    receiverAddress: $receiverAddress,
                                    receiverTaxCondition: $receiverTaxCondition,
                                    netAmount: $netAmount,
                                    ivaAmount: $ivaAmount,
                                    perceptionAmount: $perceptionAmount,
                                    tributesBreakdown: $tributesBreakdown,
                                    totalAmount: $totalAmount,
                                    ivaBreakdown: $ivaBreakdown,
                                    issuerCuit: $issuerCuit,
                                    resultado: (string) $recovered['resultado'],
                                    concepto: $concepto,
                                );
                            }

                            // COL-06: Si el comprobante en AFIP pertenece a otro receptor o monto, limpiar la caché pendiente
                            if (! $docMatches) {
                                $cacheStore->forget($pendingCacheKey);
                            }
                        }
                    } catch (\Throwable) {
                        // Si falla la consulta preventiva, procedemos con la emisión normal
                    }
                }
            }

            // Correlatividad numérica estricta dentro de la sección crítica
            $lastNumber = $this->getLastVoucherNumber($ptoVta, $voucherType, $ticket, $issuerCuit, $endpoint);
            $cbteNro = $lastNumber + 1;

            if ($pendingCacheKey !== null) {
                $cacheStore->put($pendingCacheKey, [
                    'pto_vta' => $ptoVta,
                    'voucher_type' => $voucherType,
                    'cbte_nro' => $cbteNro,
                    'total_amount' => $totalAmount,
                ], now()->addHours(24));
            }

            // Armado del Envelope SOAP FECAESolicitar
            $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>'.
                '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'.
                '<soap:Body>'.
                '<FECAESolicitar xmlns="http://ar.gov.afip.dif.FEV1/">'.
                '<Auth>'.
                '<Token>'.htmlspecialchars($ticket['token'], ENT_XML1).'</Token>'.
                '<Sign>'.htmlspecialchars($ticket['sign'], ENT_XML1).'</Sign>'.
                '<Cuit>'.$issuerCuit.'</Cuit>'.
                '</Auth>'.
                '<FeCAEReq>'.
                '<FeCabReq>'.
                '<CantReg>1</CantReg>'.
                '<PtoVta>'.$ptoVta.'</PtoVta>'.
                '<CbteTipo>'.$voucherType.'</CbteTipo>'.
                '</FeCabReq>'.
                '<FeDetReq>'.
                '<FECAEDetRequest>'.
                '<Concepto>'.$concepto.'</Concepto>'.
                '<DocTipo>'.$docType.'</DocTipo>'.
                '<DocNro>'.$numericDocNro.'</DocNro>'.
                '<CbteDesde>'.$cbteNro.'</CbteDesde>'.
                '<CbteHasta>'.$cbteNro.'</CbteHasta>'.
                '<CbteFch>'.$cbteFch.'</CbteFch>'.
                '<ImpTotal>'.number_format($totalAmount, 2, '.', '').'</ImpTotal>'.
                '<ImpTotConc>0.00</ImpTotConc>'.
                '<ImpNeto>'.number_format($netAmount, 2, '.', '').'</ImpNeto>'.
                '<ImpOpEx>0.00</ImpOpEx>'.
                '<ImpTrib>'.number_format($perceptionAmount, 2, '.', '').'</ImpTrib>'.
                '<ImpIVA>'.number_format($ivaAmount, 2, '.', '').'</ImpIVA>'.
                $serviceDatesXml.
                '<MonId>PES</MonId>'.
                '<MonCotiz>1</MonCotiz>'.
                '<CondicionIVAReceptorId>'.AfipHelper::getCondicionIvaReceptorId((string) $receiverTaxCondition, $voucherLetter).'</CondicionIVAReceptorId>'.
                $cbtesAsocXml.
                $tributosXml.
                $ivaXml.
                '</FECAEDetRequest>'.
                '</FeDetReq>'.
                '</FeCAEReq>'.
                '</FECAESolicitar>'.
                '</soap:Body>'.
                '</soap:Envelope>';

            try {
                $response = Http::setClient($this->getSharedWsfeClient())
                    ->withHeaders([
                        'Connection' => 'keep-alive',
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"http://ar.gov.afip.dif.FEV1/FECAESolicitar"',
                    ])
                    ->timeout(12)
                    ->send('POST', $endpoint, [
                        'body' => $soapEnvelope,
                    ]);

                if ($response->status() >= 500) {
                    throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
                }
            } catch (\Throwable $requestException) {
                // F3-AFIP-01 / COL-06: Reconciliación inmediata ante timeout o error HTTP 5xx en FECAESolicitar
                try {
                    $recovered = $this->consultVoucher($ptoVta, $voucherType, $cbteNro, $ticket, $issuerCuit, $endpoint);
                    if ($recovered !== null) {
                        $docMatches = ($recovered['doc_type'] === null || $recovered['doc_type'] === (int) $docType)
                            && ($recovered['doc_nro'] === null || (int) $recovered['doc_nro'] === (int) $numericDocNro);

                        if (
                            ($recovered['resultado'] ?? '') === 'A'
                            && ! empty($recovered['cae'])
                            && $recovered['imp_total'] > 0.0
                            && abs($recovered['imp_total'] - $totalAmount) <= 0.05
                            && $docMatches
                        ) {
                            if ($pendingCacheKey !== null) {
                                $cacheStore->forget($pendingCacheKey);
                            }

                            return $this->buildAuthorizedInvoiceResult(
                                voucherType: $voucherType,
                                voucherLetter: $voucherLetter,
                                ptoVta: $ptoVta,
                                cbteNro: $cbteNro,
                                cae: (string) $recovered['cae'],
                                caeExpiration: (string) $recovered['cae_expiration'],
                                docType: $docType,
                                docNumber: (string) $docNumber,
                                numericDocNro: $numericDocNro,
                                receiverName: $receiverName,
                                receiverAddress: $receiverAddress,
                                receiverTaxCondition: $receiverTaxCondition,
                                netAmount: $netAmount,
                                ivaAmount: $ivaAmount,
                                perceptionAmount: $perceptionAmount,
                                tributesBreakdown: $tributesBreakdown,
                                totalAmount: $totalAmount,
                                ivaBreakdown: $ivaBreakdown,
                                issuerCuit: $issuerCuit,
                                resultado: (string) $recovered['resultado'],
                                concepto: $concepto,
                            );
                        }
                    }
                } catch (\Throwable) {
                    // Si FECompConsultar también falla, relanzamos la excepción original
                }

                throw $requestException;
            }
        } finally {
            $lock->release();
        }

        if (! $response->successful()) {
            throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
        }

        $xml = $response->body();

        // 1. Error a nivel Envelope
        if (str_contains($xml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $xml, $m);
            throw new RuntimeException('AFIP WSFE Fault: '.($m[1] ?? 'Error desconocido'));
        }

        // 2. Errores a nivel Cabecera
        if (preg_match('/<Errors>(.*?)<\/Errors>/s', $xml, $errMatch)) {
            if ($pendingCacheKey !== null) {
                $cacheStore->forget($pendingCacheKey);
            }
            preg_match_all('/<Msg>(.*?)<\/Msg>/s', $errMatch[1], $msgs);
            $errList = implode('; ', $msgs[1] ?? []);
            throw new RuntimeException("AFIP WSFE Error: {$errList}");
        }

        // 3. Resultado de la autorización
        preg_match('/<Resultado>([A-Z])<\/Resultado>/', $xml, $resMatch);
        $resultado = $resMatch[1] ?? 'R';

        if ($resultado !== 'A') {
            if ($pendingCacheKey !== null) {
                $cacheStore->forget($pendingCacheKey);
            }
            $obsMsg = 'Comprobante rechazado por ARCA';
            if (preg_match('/<Observaciones>(.*?)<\/Observaciones>/s', $xml, $obsMatch)) {
                preg_match_all('/<Msg>(.*?)<\/Msg>/s', $obsMatch[1], $obsMsgs);
                $obsMsg .= ': '.implode('; ', $obsMsgs[1] ?? []);
            }
            throw new RuntimeException($obsMsg);
        }

        // Extraer CAE y Vencimiento
        preg_match('/<CAE>(\d+)<\/CAE>/', $xml, $caeMatch);
        preg_match('/<CAEFchVto>(\d{8})<\/CAEFchVto>/', $xml, $vtoMatch);

        $cae = $caeMatch[1] ?? '';
        $rawVto = $vtoMatch[1] ?? '';

        if (empty($cae)) {
            throw new RuntimeException('La respuesta de ARCA no contiene un CAE válido.');
        }

        if ($pendingCacheKey !== null) {
            $cacheStore->forget($pendingCacheKey);
        }

        $caeExpiration = ! empty($rawVto)
            ? substr($rawVto, 0, 4).'-'.substr($rawVto, 4, 2).'-'.substr($rawVto, 6, 2)
            : date('Y-m-d', strtotime('+10 days'));

        return $this->buildAuthorizedInvoiceResult(
            voucherType: $voucherType,
            voucherLetter: $voucherLetter,
            ptoVta: $ptoVta,
            cbteNro: $cbteNro,
            cae: $cae,
            caeExpiration: $caeExpiration,
            docType: $docType,
            docNumber: (string) $docNumber,
            numericDocNro: $numericDocNro,
            receiverName: $receiverName,
            receiverAddress: $receiverAddress,
            receiverTaxCondition: $receiverTaxCondition,
            netAmount: $netAmount,
            ivaAmount: $ivaAmount,
            perceptionAmount: $perceptionAmount,
            tributesBreakdown: $tributesBreakdown,
            totalAmount: $totalAmount,
            ivaBreakdown: $ivaBreakdown,
            issuerCuit: $issuerCuit,
            resultado: $resultado,
            concepto: $concepto,
        );
    }

    /**
     * Construye el arreglo canónico de respuesta de comprobante fiscal autorizado.
     */
    protected function buildAuthorizedInvoiceResult(
        int $voucherType,
        string $voucherLetter,
        int $ptoVta,
        int $cbteNro,
        string $cae,
        string $caeExpiration,
        int $docType,
        string $docNumber,
        int $numericDocNro,
        string $receiverName,
        string $receiverAddress,
        string $receiverTaxCondition,
        float $netAmount,
        float $ivaAmount,
        float $perceptionAmount,
        ?array $tributesBreakdown,
        float $totalAmount,
        array $ivaBreakdown,
        int $issuerCuit,
        string $resultado = 'A',
        int $concepto = 1,
    ): array {
        $qrUrl = $this->buildQrUrl([
            'fecha' => date('Y-m-d'),
            'cuit' => $issuerCuit,
            'ptoVta' => $ptoVta,
            'tipoCmp' => $voucherType,
            'nroCmp' => $cbteNro,
            'importe' => $totalAmount,
            'tipoDocRec' => $docType,
            'nroDocRec' => $numericDocNro,
            'codAut' => (int) $cae,
        ]);

        return [
            'voucher_type' => $voucherType,
            'voucher_letter' => $voucherLetter,
            'point_of_sale' => $ptoVta,
            'voucher_number' => $cbteNro,
            'formatted_number' => sprintf('%05d-%08d', $ptoVta, $cbteNro),
            'concepto' => $concepto,
            'cae' => $cae,
            'cae_expiration' => $caeExpiration,
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'receiver_name' => $receiverName,
            'receiver_address' => $receiverAddress,
            'receiver_tax_condition' => $receiverTaxCondition,
            'net_amount' => $netAmount,
            'iva_amount' => $ivaAmount,
            'tribute_amount' => $perceptionAmount,
            'tributes_breakdown' => $tributesBreakdown,
            'exempt_amount' => 0.00,
            'untaxed_amount' => 0.00,
            'total_amount' => $totalAmount,
            'iva_breakdown' => $ivaBreakdown,
            'qr_data' => $qrUrl,
            'status' => 'authorized',
            'afip_request' => [
                'ptoVta' => $ptoVta,
                'cbteTipo' => $voucherType,
                'cbteNro' => $cbteNro,
                'docTipo' => $docType,
                'docNro' => $numericDocNro,
                'concepto' => $concepto,
            ],
            'afip_response' => [
                'Resultado' => $resultado,
                'CAE' => $cae,
                'CAEFchVto' => $caeExpiration,
            ],
        ];
    }

    /**
     * Construye la URL oficial y el payload JSON canónico del Código QR Fiscal (RG 4892/2020).
     */
    public function buildQrUrl(array $params): string
    {
        $qrData = [
            'ver' => 1,
            'fecha' => (string) ($params['fecha'] ?? date('Y-m-d')),
            'cuit' => (int) ($params['cuit'] ?? 0),
            'ptoVta' => (int) ($params['ptoVta'] ?? 1),
            'tipoCmp' => (int) ($params['tipoCmp'] ?? 6),
            'nroCmp' => (int) ($params['nroCmp'] ?? 1),
            'importe' => (float) round((float) ($params['importe'] ?? 0.0), 2),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => (int) ($params['tipoDocRec'] ?? 99),
            'nroDocRec' => (int) ($params['nroDocRec'] ?? 0),
            'tipoCodAut' => 'E',
            'codAut' => (int) ($params['codAut'] ?? 0),
        ];

        $json = json_encode($qrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $base64 = base64_encode((string) $json);

        return "https://www.afip.gob.ar/fe/qr/?p={$base64}";
    }

    /**
     * Resuelve el endpoint de WSFEv1 según el entorno de ejecución configurado.
     */
    protected function getWsfeEndpoint(?string $environment = null): string
    {
        $environment ??= (BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing');

        return $environment === 'production' ? self::URL_WSFE_PROD : self::URL_WSFE_HOMO;
    }
}
