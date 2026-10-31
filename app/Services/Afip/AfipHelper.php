<?php

declare(strict_types=1);

namespace App\Services\Afip;

class AfipHelper
{
    /**
     * Códigos de alícuotas de IVA oficiales de AFIP / ARCA (Tabla WSFEv1).
     */
    public const ALIQUOT_0_PERCENT = 3;    // 0.00% (Exento / No gravado)

    public const ALIQUOT_10_5_PERCENT = 4; // 10.50%

    public const ALIQUOT_21_PERCENT = 5;   // 21.00%

    public const ALIQUOT_27_PERCENT = 6;   // 27.00%

    public const ALIQUOT_5_PERCENT = 8;    // 5.00%

    public const ALIQUOT_2_5_PERCENT = 9;  // 2.50%

    /**
     * Tipos de Comprobante AFIP (CbteTipo).
     */
    public const VOUCHER_FACTURA_A = 1;

    public const VOUCHER_NOTA_DEBITO_A = 2;

    public const VOUCHER_NOTA_CREDITO_A = 3;

    public const VOUCHER_FACTURA_B = 6;

    public const VOUCHER_NOTA_DEBITO_B = 7;

    public const VOUCHER_NOTA_CREDITO_B = 8;

    public const VOUCHER_FACTURA_C = 11;

    public const VOUCHER_NOTA_DEBITO_C = 12;

    public const VOUCHER_NOTA_CREDITO_C = 13;

    /**
     * Tipos de Documento AFIP (DocTipo).
     */
    public const DOC_CUIT = 80;

    public const DOC_CUIL = 86;

    public const DOC_DNI = 96;

    public const DOC_CONSUMIDOR_FINAL = 99;

    /**
     * Valida un CUIT o CUIL argentino utilizando el algoritmo oficial de Módulo 11.
     * Ponderadores: [5, 4, 3, 2, 7, 6, 5, 4, 3, 2].
     */
    public static function validateCuit(string|int|null $cuit): bool
    {
        if ($cuit === null) {
            return false;
        }

        $clean = preg_replace('/\D/', '', (string) $cuit);

        if (strlen($clean) !== 11) {
            return false;
        }

        $validPrefixes = ['20', '23', '24', '27', '30', '33', '34'];
        $prefix = substr($clean, 0, 2);
        if (! in_array($prefix, $validPrefixes, true)) {
            return false;
        }

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $sum += ((int) $clean[$i]) * $multipliers[$i];
        }

        $remainder = $sum % 11;

        if ($remainder === 0) {
            $expectedDigit = 0;
        } elseif ($remainder === 1) {
            // Un resto de 1 arroja 11 - 1 = 10, lo cual no es representable en un único dígito.
            // Para CUITs estándar en Argentina, el dígito verificador es inválido.
            return false;
        } else {
            $expectedDigit = 11 - $remainder;
        }

        return ((int) $clean[10]) === $expectedDigit;
    }

    /**
     * Valida un DNI argentino (numérico entre 7 y 8 dígitos).
     */
    public static function validateDni(string|int|null $dni): bool
    {
        if ($dni === null) {
            return false;
        }

        $clean = preg_replace('/\D/', '', (string) $dni);
        $len = strlen($clean);

        if ($len < 7 || $len > 8) {
            return false;
        }

        $num = (int) $clean;

        return $num >= 1000000 && $num <= 99999999;
    }

    /**
     * Mapea un porcentaje de alícuota al Id correspondiente de AFIP (Tabla WSFEv1).
     */
    public static function getAliquotId(float|int|string $rate): int
    {
        $normalizedRate = round((float) $rate, 2);

        return match ($normalizedRate) {
            0.00 => self::ALIQUOT_0_PERCENT,
            10.50 => self::ALIQUOT_10_5_PERCENT,
            27.00 => self::ALIQUOT_27_PERCENT,
            5.00 => self::ALIQUOT_5_PERCENT,
            2.50 => self::ALIQUOT_2_5_PERCENT,
            default => self::ALIQUOT_21_PERCENT,
        };
    }

    /**
     * Devuelve el porcentaje correspondiente a un ID de alícuota de AFIP.
     */
    public static function getAliquotRate(int $id): float
    {
        return match ($id) {
            self::ALIQUOT_0_PERCENT => 0.00,
            self::ALIQUOT_10_5_PERCENT => 10.50,
            self::ALIQUOT_27_PERCENT => 27.00,
            self::ALIQUOT_5_PERCENT => 5.00,
            self::ALIQUOT_2_5_PERCENT => 2.50,
            default => 21.00,
        };
    }

    /**
     * Calcula la base neta y el IVA a partir del subtotal bruto (precio final consumidor).
     * Garantiza el invariante matemático: net + iva == grossSubtotal.
     */
    public static function calculateNetAndIva(float $grossSubtotal, float $ivaRate = 21.00): array
    {
        $gross = round($grossSubtotal, 2);
        $rate = round($ivaRate, 2);

        if ($rate <= 0.00) {
            return [
                'net' => $gross,
                'iva' => 0.00,
            ];
        }

        $net = round($gross / (1 + ($rate / 100.0)), 2);
        $iva = round($gross - $net, 2);

        return [
            'net' => $net,
            'iva' => $iva,
        ];
    }

    /**
     * Obtiene la letra del comprobante ('A', 'B', 'C') según su tipo AFIP.
     */
    public static function getVoucherLetter(int $voucherType): string
    {
        return match ($voucherType) {
            self::VOUCHER_FACTURA_A, self::VOUCHER_NOTA_DEBITO_A, self::VOUCHER_NOTA_CREDITO_A => 'A',
            self::VOUCHER_FACTURA_C, self::VOUCHER_NOTA_DEBITO_C, self::VOUCHER_NOTA_CREDITO_C => 'C',
            default => 'B',
        };
    }

    /**
     * Resuelve el tipo de comprobante Factura a partir de la letra seleccionada.
     */
    public static function resolveVoucherTypeFromLetter(string $letter): int
    {
        return match (strtoupper(trim($letter))) {
            'A' => self::VOUCHER_FACTURA_A,
            'C' => self::VOUCHER_FACTURA_C,
            default => self::VOUCHER_FACTURA_B,
        };
    }

    /**
     * Resuelve el ID de la Condición Frente al IVA del Receptor
     * según la Resolución General Nro 5616.
     */
    public static function getCondicionIvaReceptorId(string $taxCondition, ?string $voucherLetter = null): int
    {
        $normalized = strtolower(trim($taxCondition));
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        $id = match (true) {
            str_contains($normalized, 'inscripto') => 1,
            str_contains($normalized, 'exento') => 4,
            str_contains($normalized, 'monotributo') => 6,
            str_contains($normalized, 'consumidor final') => 5,
            default => 5, // Fallback por defecto a Consumidor Final
        };

        // RG 5616 Validación cruzada preventiva (evita rechazo ARCA 10243):
        // - Clase A admite Receptor 1 (Resp. Inscripto) o 6 (Monotributo)
        // - Clase B admite Receptor 5 (Consumidor Final) o 4 (Sujeto Exento)
        if ($voucherLetter !== null) {
            $letter = strtoupper(trim($voucherLetter));
            if ($letter === 'A' && ! in_array($id, [1, 6], true)) {
                return 1;
            }
            if ($letter === 'B' && ! in_array($id, [4, 5], true)) {
                return 5;
            }
        }

        return $id;
    }

    /**
     * Valida si el tipo de comprobante es compatible con la condición frente al IVA del comercio emisor.
     * - Responsable Inscripto: solo puede emitir Clase A (1, 2, 3) o Clase B (6, 7, 8).
     * - Monotributo o Sujeto Exento: solo puede emitir Clase C (11, 12, 13).
     * - Si la condición del emisor no está configurada, permite el comprobante por compatibilidad.
     */
    public static function isValidVoucherForIssuer(?string $issuerTaxCondition, int $voucherType): bool
    {
        if ($issuerTaxCondition === null || trim($issuerTaxCondition) === '') {
            return true;
        }

        $normalized = strtolower(trim($issuerTaxCondition));
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $voucherLetter = self::getVoucherLetter($voucherType);

        if (str_contains($normalized, 'inscripto')) {
            return in_array($voucherLetter, ['A', 'B'], true);
        }

        if (str_contains($normalized, 'monotributo') || str_contains($normalized, 'exento')) {
            return $voucherLetter === 'C';
        }

        return true;
    }
}
