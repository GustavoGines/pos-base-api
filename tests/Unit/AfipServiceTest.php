<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Afip\AfipHelper;
use App\Services\Afip\AfipWsfeService;
use PHPUnit\Framework\TestCase;

class AfipServiceTest extends TestCase
{
    /**
     * Valida CUITs autorizados y oficiales de entidades reales argentinas mediante Módulo 11.
     */
    public function test_cuit_modulo_11_validates_authoritative_argentine_cuits(): void
    {
        // AFIP Oficial: 33-69345023-9
        $this->assertTrue(AfipHelper::validateCuit('33-69345023-9'));
        $this->assertTrue(AfipHelper::validateCuit('33693450239'));

        // Banco de la Nación Argentina: 30-50001091-2
        $this->assertTrue(AfipHelper::validateCuit('30-50001091-2'));
        $this->assertTrue(AfipHelper::validateCuit('30500010912'));

        // YPF S.A.: 30-54668997-9
        $this->assertTrue(AfipHelper::validateCuit('30-54668997-9'));
        $this->assertTrue(AfipHelper::validateCuit('30546689979'));

        // Telecom Argentina S.A.: 30-63945373-8
        $this->assertTrue(AfipHelper::validateCuit('30-63945373-8'));
        $this->assertTrue(AfipHelper::validateCuit('30639453738'));
    }

    /**
     * Rechaza CUITs inválidos: alteración de dígito verificador, prefijo o longitud errónea.
     */
    public function test_cuit_modulo_11_rejects_invalid_cuits(): void
    {
        // CUITs con dígito verificador adulterado
        $this->assertFalse(AfipHelper::validateCuit('33-69345023-0'));
        $this->assertFalse(AfipHelper::validateCuit('30-50001091-5'));
        $this->assertFalse(AfipHelper::validateCuit('30-54668997-1'));

        // Longitudes inválidas
        $this->assertFalse(AfipHelper::validateCuit('3050001091'));    // 10 dígitos
        $this->assertFalse(AfipHelper::validateCuit('305000109129'));  // 12 dígitos

        // Prefijos inválidos
        $this->assertFalse(AfipHelper::validateCuit('15-50001091-2'));
        $this->assertFalse(AfipHelper::validateCuit('99-50001091-2'));

        // Valores nulos o vacíos
        $this->assertFalse(AfipHelper::validateCuit(''));
        $this->assertFalse(AfipHelper::validateCuit(null));
        $this->assertFalse(AfipHelper::validateCuit('abcdefghijk'));
    }

    /**
     * Valida números de DNI argentino (numéricos de 7 y 8 dígitos).
     */
    public function test_dni_validator(): void
    {
        $this->assertTrue(AfipHelper::validateDni('8456123'));    // 7 dígitos
        $this->assertTrue(AfipHelper::validateDni('35123456'));   // 8 dígitos
        $this->assertTrue(AfipHelper::validateDni(35123456));

        // Inválidos
        $this->assertFalse(AfipHelper::validateDni('123456'));    // 6 dígitos
        $this->assertFalse(AfipHelper::validateDni('123456789')); // 9 dígitos
        $this->assertFalse(AfipHelper::validateDni('0'));
        $this->assertFalse(AfipHelper::validateDni(null));
        $this->assertFalse(AfipHelper::validateDni('35ABC456'));
    }

    /**
     * Valida el cálculo de base neta e IVA a partir de importes brutos (con IVA incluido).
     */
    public function test_iva_calculation_and_breakdown(): void
    {
        // Alícuota 21%
        $calc21 = AfipHelper::calculateNetAndIva(1210.00, 21.00);
        $this->assertEquals(1000.00, $calc21['net']);
        $this->assertEquals(210.00, $calc21['iva']);
        $this->assertEquals(1210.00, round($calc21['net'] + $calc21['iva'], 2));

        // Alícuota 10.5%
        $calc105 = AfipHelper::calculateNetAndIva(1105.00, 10.50);
        $this->assertEquals(1000.00, $calc105['net']);
        $this->assertEquals(105.00, $calc105['iva']);
        $this->assertEquals(1105.00, round($calc105['net'] + $calc105['iva'], 2));

        // Alícuota 0% (Exento)
        $calc0 = AfipHelper::calculateNetAndIva(500.00, 0.00);
        $this->assertEquals(500.00, $calc0['net']);
        $this->assertEquals(0.00, $calc0['iva']);
        $this->assertEquals(500.00, round($calc0['net'] + $calc0['iva'], 2));

        // Alícuota 27%
        $calc27 = AfipHelper::calculateNetAndIva(1270.00, 27.00);
        $this->assertEquals(1000.00, $calc27['net']);
        $this->assertEquals(270.00, $calc27['iva']);
        $this->assertEquals(1270.00, round($calc27['net'] + $calc27['iva'], 2));

        // Invariante con centavos impares ($99.99 con 21% IVA)
        $calcOdd = AfipHelper::calculateNetAndIva(99.99, 21.00);
        $this->assertEquals(99.99, round($calcOdd['net'] + $calcOdd['iva'], 2));
    }

    /**
     * Valida el mapeo bidireccional de códigos de alícuota de AFIP.
     */
    public function test_afip_aliquot_codes_mapping(): void
    {
        $this->assertSame(AfipHelper::ALIQUOT_21_PERCENT, AfipHelper::getAliquotId(21.00));
        $this->assertSame(AfipHelper::ALIQUOT_10_5_PERCENT, AfipHelper::getAliquotId(10.50));
        $this->assertSame(AfipHelper::ALIQUOT_0_PERCENT, AfipHelper::getAliquotId(0.00));
        $this->assertSame(AfipHelper::ALIQUOT_27_PERCENT, AfipHelper::getAliquotId(27.00));
        $this->assertSame(AfipHelper::ALIQUOT_5_PERCENT, AfipHelper::getAliquotId(5.00));
        $this->assertSame(AfipHelper::ALIQUOT_2_5_PERCENT, AfipHelper::getAliquotId(2.50));

        // Inverso
        $this->assertSame(21.00, AfipHelper::getAliquotRate(5));
        $this->assertSame(10.50, AfipHelper::getAliquotRate(4));
        $this->assertSame(0.00, AfipHelper::getAliquotRate(3));
        $this->assertSame(27.00, AfipHelper::getAliquotRate(6));
    }

    /**
     * Valida la construcción del payload canónico de 13 campos y URL Base64 para el Código QR Fiscal (RG 4892/2020).
     */
    public function test_rg_4892_fiscal_qr_url_builder(): void
    {
        $wsfeService = new AfipWsfeService();

        $qrData = [
            'fecha'      => '2026-10-07',
            'cuit'       => 30712345678,
            'ptoVta'     => 1,
            'tipoCmp'    => 1,
            'nroCmp'     => 124,
            'importe'    => 12100.50,
            'tipoDocRec' => 80,
            'nroDocRec'  => 20123456789,
            'codAut'     => 74382910492819,
        ];

        $qrUrl = $wsfeService->buildQrUrl($qrData);

        $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $qrUrl);

        // Extraer y decodificar el payload Base64
        $base64 = str_replace('https://www.afip.gob.ar/fe/qr/?p=', '', $qrUrl);
        $decodedJson = base64_decode($base64, true);

        $this->assertNotFalse($decodedJson, 'El parámetro ?p= no es un Base64 válido');

        $parsed = json_decode($decodedJson, true);
        $this->assertIsArray($parsed);

        // Verificar los 13 campos oficiales canónicos
        $this->assertSame(1, $parsed['ver']);
        $this->assertSame('2026-10-07', $parsed['fecha']);
        $this->assertSame(30712345678, $parsed['cuit']);
        $this->assertSame(1, $parsed['ptoVta']);
        $this->assertSame(1, $parsed['tipoCmp']);
        $this->assertSame(124, $parsed['nroCmp']);
        $this->assertSame(12100.50, $parsed['importe']);
        $this->assertSame('PES', $parsed['moneda']);
        $this->assertSame(1, $parsed['ctz']);
        $this->assertSame(80, $parsed['tipoDocRec']);
        $this->assertSame(20123456789, $parsed['nroDocRec']);
        $this->assertSame('E', $parsed['tipoCodAut']);
        $this->assertSame(74382910492819, $parsed['codAut']);
    }

    /**
     * Valida helpers de tipos de comprobante y letras.
     */
    public function test_voucher_type_and_letter_helpers(): void
    {
        $this->assertSame('A', AfipHelper::getVoucherLetter(AfipHelper::VOUCHER_FACTURA_A));
        $this->assertSame('B', AfipHelper::getVoucherLetter(AfipHelper::VOUCHER_FACTURA_B));
        $this->assertSame('C', AfipHelper::getVoucherLetter(AfipHelper::VOUCHER_FACTURA_C));

        $this->assertSame(AfipHelper::VOUCHER_FACTURA_A, AfipHelper::resolveVoucherTypeFromLetter('A'));
        $this->assertSame(AfipHelper::VOUCHER_FACTURA_B, AfipHelper::resolveVoucherTypeFromLetter('B'));
        $this->assertSame(AfipHelper::VOUCHER_FACTURA_C, AfipHelper::resolveVoucherTypeFromLetter('C'));
    }
}
