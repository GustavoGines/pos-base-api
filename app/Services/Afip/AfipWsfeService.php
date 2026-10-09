<?php

declare(strict_types=1);

namespace App\Services\Afip;

use App\Models\BusinessSetting;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class AfipWsfeService
{
    public const URL_WSFE_HOMO = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
    public const URL_WSFE_PROD = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';

    protected AfipWsaaService $wsaaService;

    public function __construct(?AfipWsaaService $wsaaService = null)
    {
        $this->wsaaService = $wsaaService ?? new AfipWsaaService();
    }

    /**
     * Consulta el último número de comprobante autorizado para un punto de venta y tipo.
     */
    public function getLastVoucherNumber(int $pointOfSale, int $voucherType): int
    {
        $ticket = $this->wsaaService->getAccessTicket('wsfe');
        $cuit = BusinessSetting::where('key', 'afip_cuit')->value('value');
        $cuit = (int) preg_replace('/\D/', '', (string) $cuit);

        $endpoint = $this->getWsfeEndpoint();

        $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>' .
            '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body>' .
            '<FECompUltimoAutorizado xmlns="http://ar.gov.afip.dif.FEV1/">' .
            '<Auth>' .
            '<Token>' . htmlspecialchars($ticket['token'], ENT_XML1) . '</Token>' .
            '<Sign>' . htmlspecialchars($ticket['sign'], ENT_XML1) . '</Sign>' .
            '<Cuit>' . $cuit . '</Cuit>' .
            '</Auth>' .
            '<PtoVta>' . $pointOfSale . '</PtoVta>' .
            '<CbteTipo>' . $voucherType . '</CbteTipo>' .
            '</FECompUltimoAutorizado>' .
            '</soap:Body>' .
            '</soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '"http://ar.gov.afip.dif.FEV1/FECompUltimoAutorizado"',
        ])->timeout(10)->send('POST', $endpoint, [
            'body' => $soapEnvelope,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
        }

        $xml = $response->body();

        if (str_contains($xml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $xml, $m);
            throw new RuntimeException("AFIP WSFE Fault: " . ($m[1] ?? 'Error desconocido'));
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
     * Solicita autorización fiscal (CAE) a WSFEv1 para una venta del sistema POS.
     *
     * @param Sale $sale Venta a facturar
     * @param array $options Opciones adicionales (voucher_type, doc_type, doc_number, etc.)
     * @return array Datos de la factura electrónica autorizada
     */
    public function authorizeInvoice(Sale $sale, array $options = []): array
    {
        $ticket = $this->wsaaService->getAccessTicket('wsfe');

        $issuerCuit = BusinessSetting::where('key', 'afip_cuit')->value('value');
        $issuerCuit = (int) preg_replace('/\D/', '', (string) $issuerCuit);
        if (empty($issuerCuit)) {
            throw new InvalidArgumentException('El CUIT del emisor no está configurado en Ajustes de Integración.');
        }

        $ptoVta = (int) ($options['point_of_sale'] ?? BusinessSetting::where('key', 'afip_pto_vta')->value('value') ?? 1);
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

        // Receptor
        $customer = $sale->customer;
        $docType = (int) ($options['doc_type'] ?? ($customer?->document_type ?? AfipHelper::DOC_CONSUMIDOR_FINAL));
        $rawDocNumber = $options['doc_number'] ?? ($customer?->document_number ?? '');
        $docNumber = preg_replace('/\D/', '', (string) $rawDocNumber);

        $receiverName = $options['receiver_name'] ?? ($customer?->name ?? 'Consumidor Final');
        $receiverAddress = $options['receiver_address'] ?? ($customer?->fiscal_address ?? $customer?->delivery_address ?? '');
        $receiverTaxCondition = $options['receiver_tax_condition'] ?? ($customer?->tax_condition ?? 'consumidor_final');

        // Validaciones por tipo de comprobante
        // Clase A (Factura A, Nota de Débito A, Nota de Crédito A): CUIT receptor obligatorio
        if ($voucherLetter === 'A') {
            $docType = AfipHelper::DOC_CUIT;
            if (! AfipHelper::validateCuit($docNumber)) {
                throw new InvalidArgumentException("Para emitir comprobantes clase A, el CUIT del receptor es obligatorio y debe ser válido ({$docNumber}).");
            }
        } elseif ($docType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
            // Verificar tope de Consumidor Final anónimo
            $anonymousLimit = (float) (BusinessSetting::where('key', 'afip_cf_max_limit')->value('value') ?? 340000.00);
            if ((float) $sale->total >= $anonymousLimit && empty($docNumber)) {
                throw new InvalidArgumentException(
                    "El importe total supera el límite de consumidor final anónimo ($" . number_format($anonymousLimit, 2) . "). Debe identificar al cliente con DNI o CUIT."
                );
            }
            if (empty($docNumber)) {
                $docNumber = '0';
            }
        } elseif ($docType === AfipHelper::DOC_CUIT) {
            if (! AfipHelper::validateCuit($docNumber)) {
                throw new InvalidArgumentException("El CUIT ingresado es inválido según Módulo 11 ({$docNumber}).");
            }
        } elseif ($docType === AfipHelper::DOC_DNI) {
            if (! AfipHelper::validateDni($docNumber)) {
                throw new InvalidArgumentException("El DNI ingresado es inválido ({$docNumber}).");
            }
        }

        $numericDocNro = (int) $docNumber;

        // Correlatividad numérica estricta
        $lastNumber = $this->getLastVoucherNumber($ptoVta, $voucherType);
        $cbteNro = $lastNumber + 1;

        // Percepción IIBB
        $perceptionAmount = round((float) ($options['iibb_perception_amount'] ?? $sale->iibb_perception_amount ?? 0.0), 2);
        $perceptionRate = (float) ($options['iibb_perception_rate'] ?? $sale->iibb_perception_rate ?? 0.0);

        // Auto-cálculo de tasa si el cliente aplica y negocio es agente
        if ($perceptionAmount <= 0.0 && ! empty($customer?->applies_iibb_perception)) {
            $isAgent = BusinessSetting::where('key', 'is_iibb_perception_agent')->value('value');
            if ($isAgent === '1' || $isAgent === 'true' || $isAgent === true || $isAgent === 1) {
                if ($perceptionRate <= 0.0) {
                    $perceptionRate = (float) ($customer->iibb_perception_rate
                        ?? BusinessSetting::where('key', 'default_iibb_perception_rate')->value('value')
                        ?? 0.0);
                }
            }
        }

        // Cálculos e invariante matemático
        $totalAmount = round((float) $sale->total, 2);
        $ivaBreakdown = [];
        $ivaXml = '';

        if ($voucherLetter === 'C') {
            // REGLA CRÍTICA MONOTRIBUTO (CLASE C: Factura C, Nota de Débito C, Nota de Crédito C):
            // 1. ImpIVA debe ser estrictamente 0.00
            // 2. ImpNeto es igual al total base (restando percepción si el total de la venta ya la contenía)
            // 3. El nodo <Iva> DEBE SER ESTRICTAMENTE OMITIDO
            $baseSaleAmount = $totalAmount;
            if ($perceptionAmount > 0.0 && $baseSaleAmount > $perceptionAmount && round((float) ($sale->iibb_perception_amount ?? 0), 2) > 0) {
                $netAmount = round($baseSaleAmount - $perceptionAmount, 2);
            } else {
                $netAmount = $baseSaleAmount;
            }

            if ($perceptionAmount <= 0.0 && $perceptionRate > 0.0 && ! empty($customer?->applies_iibb_perception)) {
                $isAgent = BusinessSetting::where('key', 'is_iibb_perception_agent')->value('value');
                if ($isAgent === '1' || $isAgent === 'true' || $isAgent === true || $isAgent === 1) {
                    $perceptionAmount = round($netAmount * ($perceptionRate / 100), 2);
                }
            }

            if ($perceptionAmount > 0.0 && $perceptionRate <= 0.0 && $netAmount > 0.0) {
                $perceptionRate = round(($perceptionAmount / $netAmount) * 100, 2);
            }

            $ivaAmount = 0.00;
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

            if (empty($groupedByAliquot)) {
                // Fallback por si la venta no tiene items cargados
                $fallbackSubtotal = $totalAmount;
                if ($perceptionAmount > 0.0 && $totalAmount > $perceptionAmount && round((float) ($sale->iibb_perception_amount ?? 0), 2) > 0) {
                    $fallbackSubtotal = round($totalAmount - $perceptionAmount, 2);
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

            if ($perceptionAmount <= 0.0 && $perceptionRate > 0.0 && ! empty($customer?->applies_iibb_perception)) {
                $isAgent = BusinessSetting::where('key', 'is_iibb_perception_agent')->value('value');
                if ($isAgent === '1' || $isAgent === 'true' || $isAgent === true || $isAgent === 1) {
                    $perceptionAmount = round($netAmount * ($perceptionRate / 100), 2);
                }
            }

            if ($perceptionAmount > 0.0 && $perceptionRate <= 0.0 && $netAmount > 0.0) {
                $perceptionRate = round(($perceptionAmount / $netAmount) * 100, 2);
            }

            // Base esperada de los items para chequear redondeo
            $baseSale = $totalAmount;
            if ($perceptionAmount > 0.0 && round((float) ($sale->iibb_perception_amount ?? 0), 2) > 0) {
                $baseSale = round($totalAmount - $perceptionAmount, 2);
            }
            $diff = round($baseSale - ($netAmount + $ivaAmount), 2);
            if ($diff !== 0.00 && abs($diff) < 1.00) {
                $netAmount = round($baseSale - $ivaAmount, 2);
                $firstKey = array_key_first($aliquotResults);
                if ($firstKey !== null) {
                    $aliquotResults[$firstKey]['BaseImp'] = round($aliquotResults[$firstKey]['BaseImp'] + $diff, 2);
                }
            }

            $ivaBreakdown = array_values($aliquotResults);

            // Construir nodo XML <Iva>
            $ivaXml = '<Iva>';
            foreach ($ivaBreakdown as $al) {
                $ivaXml .= '<AlicIva>' .
                    '<Id>' . $al['Id'] . '</Id>' .
                    '<BaseImp>' . number_format($al['BaseImp'], 2, '.', '') . '</BaseImp>' .
                    '<Importe>' . number_format($al['Importe'], 2, '.', '') . '</Importe>' .
                    '</AlicIva>';
            }
            $ivaXml .= '</Iva>';
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
                    'Desc' => 'Percepcion IIBB Formosa',
                    'BaseImp' => $netAmount,
                    'Alic' => $perceptionRate,
                    'Importe' => $perceptionAmount,
                ],
            ];

            $tributosXml = '<Tributos>' .
                '<Tributo>' .
                '<Id>2</Id>' .
                '<Desc>Percepcion IIBB Formosa</Desc>' .
                '<BaseImp>' . number_format($netAmount, 2, '.', '') . '</BaseImp>' .
                '<Alic>' . number_format($perceptionRate, 2, '.', '') . '</Alic>' .
                '<Importe>' . number_format($perceptionAmount, 2, '.', '') . '</Importe>' .
                '</Tributo>' .
                '</Tributos>';
        }

        $cbteFch = date('Ymd');
        $endpoint = $this->getWsfeEndpoint();

        // ── Nodo CbtesAsoc (Para Notas de Crédito / Débito) ──
        // <Cuit> = CUIT del EMISOR del comprobante asociado. En este sistema el comprobante
        // asociado siempre es propio, por lo que se usa el CUIT emisor (Auth), nunca el del receptor.
        $cbtesAsocXml = '';
        if (!empty($options['cbtes_asoc']) && is_array($options['cbtes_asoc'])) {
            $cbtesAsocXml .= '<CbtesAsoc>';
            foreach ($options['cbtes_asoc'] as $asoc) {
                $asocFch = preg_replace('/\D/', '', (string) ($asoc['CbteFch'] ?? ''));
                $cbtesAsocXml .= '<CbteAsoc>' .
                    '<Tipo>' . (int) ($asoc['Tipo'] ?? 0) . '</Tipo>' .
                    '<PtoVta>' . (int) ($asoc['PtoVta'] ?? 0) . '</PtoVta>' .
                    '<Nro>' . (int) ($asoc['Nro'] ?? 0) . '</Nro>' .
                    '<Cuit>' . $issuerCuit . '</Cuit>' .
                    (strlen($asocFch) === 8 ? '<CbteFch>' . $asocFch . '</CbteFch>' : '') .
                    '</CbteAsoc>';
            }
            $cbtesAsocXml .= '</CbtesAsoc>';
        }

        // Armado del Envelope SOAP FECAESolicitar
        $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>' .
            '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body>' .
            '<FECAESolicitar xmlns="http://ar.gov.afip.dif.FEV1/">' .
            '<Auth>' .
            '<Token>' . htmlspecialchars($ticket['token'], ENT_XML1) . '</Token>' .
            '<Sign>' . htmlspecialchars($ticket['sign'], ENT_XML1) . '</Sign>' .
            '<Cuit>' . $issuerCuit . '</Cuit>' .
            '</Auth>' .
            '<FeCAEReq>' .
            '<FeCabReq>' .
            '<CantReg>1</CantReg>' .
            '<PtoVta>' . $ptoVta . '</PtoVta>' .
            '<CbteTipo>' . $voucherType . '</CbteTipo>' .
            '</FeCabReq>' .
            '<FeDetReq>' .
            '<FECAEDetRequest>' .
            '<Concepto>1</Concepto>' .
            '<DocTipo>' . $docType . '</DocTipo>' .
            '<DocNro>' . $numericDocNro . '</DocNro>' .
            '<CbteDesde>' . $cbteNro . '</CbteDesde>' .
            '<CbteHasta>' . $cbteNro . '</CbteHasta>' .
            '<CbteFch>' . $cbteFch . '</CbteFch>' .
            '<ImpTotal>' . number_format($totalAmount, 2, '.', '') . '</ImpTotal>' .
            '<ImpTotConc>0.00</ImpTotConc>' .
            '<ImpNeto>' . number_format($netAmount, 2, '.', '') . '</ImpNeto>' .
            '<ImpOpEx>0.00</ImpOpEx>' .
            '<ImpTrib>' . number_format($perceptionAmount, 2, '.', '') . '</ImpTrib>' .
            '<ImpIVA>' . number_format($ivaAmount, 2, '.', '') . '</ImpIVA>' .
            '<MonId>PES</MonId>' .
            '<MonCotiz>1</MonCotiz>' .
            '<CondicionIVAReceptorId>' . AfipHelper::getCondicionIvaReceptorId($receiverTaxCondition) . '</CondicionIVAReceptorId>' .
            $cbtesAsocXml .
            $tributosXml .
            $ivaXml .
            '</FECAEDetRequest>' .
            '</FeDetReq>' .
            '</FeCAEReq>' .
            '</FECAESolicitar>' .
            '</soap:Body>' .
            '</soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '"http://ar.gov.afip.dif.FEV1/FECAESolicitar"',
        ])->timeout(12)->send('POST', $endpoint, [
            'body' => $soapEnvelope,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("AFIP WSFEv1 Error HTTP {$response->status()}: {$response->body()}");
        }

        $xml = $response->body();

        // 1. Error a nivel Envelope
        if (str_contains($xml, '<faultstring>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $xml, $m);
            throw new RuntimeException("AFIP WSFE Fault: " . ($m[1] ?? 'Error desconocido'));
        }

        // 2. Errores a nivel Cabecera
        if (preg_match('/<Errors>(.*?)<\/Errors>/s', $xml, $errMatch)) {
            preg_match_all('/<Msg>(.*?)<\/Msg>/s', $errMatch[1], $msgs);
            $errList = implode('; ', $msgs[1] ?? []);
            throw new RuntimeException("AFIP WSFE Error: {$errList}");
        }

        // 3. Resultado de la autorización
        preg_match('/<Resultado>([A-Z])<\/Resultado>/', $xml, $resMatch);
        $resultado = $resMatch[1] ?? 'R';

        if ($resultado !== 'A') {
            $obsMsg = 'Comprobante rechazado por AFIP';
            if (preg_match('/<Observaciones>(.*?)<\/Observaciones>/s', $xml, $obsMatch)) {
                preg_match_all('/<Msg>(.*?)<\/Msg>/s', $obsMatch[1], $obsMsgs);
                $obsMsg .= ': ' . implode('; ', $obsMsgs[1] ?? []);
            }
            throw new RuntimeException($obsMsg);
        }

        // Extraer CAE y Vencimiento
        preg_match('/<CAE>(\d+)<\/CAE>/', $xml, $caeMatch);
        preg_match('/<CAEFchVto>(\d{8})<\/CAEFchVto>/', $xml, $vtoMatch);

        $cae = $caeMatch[1] ?? '';
        $rawVto = $vtoMatch[1] ?? '';

        if (empty($cae)) {
            throw new RuntimeException("La respuesta de AFIP no contiene un CAE válido.");
        }

        $caeExpiration = ! empty($rawVto)
            ? substr($rawVto, 0, 4) . '-' . substr($rawVto, 4, 2) . '-' . substr($rawVto, 6, 2)
            : date('Y-m-d', strtotime('+10 days'));

        // Generar URL del Código QR Fiscal Oficial RG 4892
        $qrUrl = $this->buildQrUrl([
            'fecha'      => date('Y-m-d'),
            'cuit'       => $issuerCuit,
            'ptoVta'     => $ptoVta,
            'tipoCmp'    => $voucherType,
            'nroCmp'     => $cbteNro,
            'importe'    => $totalAmount,
            'tipoDocRec' => $docType,
            'nroDocRec'  => $numericDocNro,
            'codAut'     => (int) $cae,
        ]);

        return [
            'voucher_type'            => $voucherType,
            'voucher_letter'          => $voucherLetter,
            'point_of_sale'           => $ptoVta,
            'voucher_number'          => $cbteNro,
            'formatted_number'        => sprintf('%05d-%08d', $ptoVta, $cbteNro),
            'cae'                     => $cae,
            'cae_expiration'          => $caeExpiration,
            'doc_type'                => $docType,
            'doc_number'              => (string) $docNumber,
            'receiver_name'           => $receiverName,
            'receiver_address'        => $receiverAddress,
            'receiver_tax_condition'  => $receiverTaxCondition,
            'net_amount'              => $netAmount,
            'iva_amount'              => $ivaAmount,
            'tribute_amount'          => $perceptionAmount,
            'tributes_breakdown'      => $tributesBreakdown,
            'exempt_amount'           => 0.00,
            'untaxed_amount'          => 0.00,
            'total_amount'            => $totalAmount,
            'iva_breakdown'           => $ivaBreakdown,
            'qr_data'                 => $qrUrl,
            'status'                  => 'authorized',
            'afip_request'            => [
                'ptoVta'   => $ptoVta,
                'cbteTipo' => $voucherType,
                'cbteNro'  => $cbteNro,
                'docTipo'  => $docType,
                'docNro'   => $numericDocNro,
            ],
            'afip_response'           => [
                'Resultado'  => $resultado,
                'CAE'        => $cae,
                'CAEFchVto'  => $caeExpiration,
            ],
        ];
    }

    /**
     * Construye la URL oficial y el payload JSON canónico del Código QR Fiscal (RG 4892/2020).
     */
    public function buildQrUrl(array $params): string
    {
        $qrData = [
            'ver'        => 1,
            'fecha'      => (string) ($params['fecha'] ?? date('Y-m-d')),
            'cuit'       => (int) ($params['cuit'] ?? 0),
            'ptoVta'     => (int) ($params['ptoVta'] ?? 1),
            'tipoCmp'    => (int) ($params['tipoCmp'] ?? 6),
            'nroCmp'     => (int) ($params['nroCmp'] ?? 1),
            'importe'    => (float) round((float) ($params['importe'] ?? 0.0), 2),
            'moneda'     => 'PES',
            'ctz'        => 1,
            'tipoDocRec' => (int) ($params['tipoDocRec'] ?? 99),
            'nroDocRec'  => (int) ($params['nroDocRec'] ?? 0),
            'tipoCodAut' => 'E',
            'codAut'     => (int) ($params['codAut'] ?? 0),
        ];

        $json = json_encode($qrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $base64 = base64_encode((string) $json);

        return "https://www.afip.gob.ar/fe/qr/?p={$base64}";
    }

    /**
     * Resuelve el endpoint de WSFEv1 según el entorno de ejecución configurado.
     */
    protected function getWsfeEndpoint(): string
    {
        $environment = BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing';
        return $environment === 'production' ? self::URL_WSFE_PROD : self::URL_WSFE_HOMO;
    }
}
