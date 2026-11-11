<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VatSalesExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function title(): string
    {
        return 'Libro IVA Ventas';
    }

    public function collection()
    {
        $vouchers = collect($this->data['vouchers'] ?? []);
        $summary = $this->data['summary'] ?? [];

        if ($vouchers->isNotEmpty()) {
            $vouchers->push([
                '_is_total_row' => true,
                'issued_at' => null,
                'voucher_label' => 'TOTALES DEL PERÍODO',
                'formatted_number' => ($summary['vouchers_count'] ?? $vouchers->count()).' comprobantes',
                'doc_type' => null,
                'doc_number' => '',
                'receiver_name' => '',
                'receiver_tax_condition' => '',
                'net_21' => (float) ($summary['net_21'] ?? 0),
                'iva_21' => (float) ($summary['iva_21'] ?? 0),
                'net_105' => (float) ($summary['net_105'] ?? 0),
                'iva_105' => (float) ($summary['iva_105'] ?? 0),
                'net_27' => (float) ($summary['net_27'] ?? 0),
                'iva_27' => (float) ($summary['iva_27'] ?? 0),
                'exempt_or_untaxed' => (float) (($summary['total_exempt'] ?? 0) + ($summary['total_untaxed'] ?? 0)),
                'tribute_amount' => (float) ($summary['total_tributes'] ?? 0),
                'net_amount' => (float) ($summary['total_net'] ?? 0),
                'iva_amount' => (float) ($summary['total_iva'] ?? 0),
                'total_amount' => (float) ($summary['net_fiscal_total'] ?? 0),
                'cae' => '',
            ]);
        }

        return $vouchers;
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Tipo Comprobante',
            'Pto. Venta - Número',
            'Tipo Doc.',
            'N° Documento',
            'Cliente / Razón Social',
            'Condición IVA',
            'Neto Grav. 21%',
            'IVA 21%',
            'Neto Grav. 10.5%',
            'IVA 10.5%',
            'Neto Grav. 27%',
            'IVA 27%',
            'Exento / No Grav.',
            'Percep. IIBB / Trib.',
            'Total Neto Gravado',
            'Total IVA Débito',
            'Total Comprobante',
            'CAE',
        ];
    }

    public function map($row): array
    {
        $r = is_array($row) ? $row : (array) $row;
        $isTotal = ! empty($r['_is_total_row']);

        $dateStr = '';
        if (! $isTotal && ! empty($r['issued_at'])) {
            try {
                $dateStr = Carbon::parse($r['issued_at'])->format('d/m/Y');
            } catch (\Throwable) {
                $dateStr = (string) $r['issued_at'];
            }
        }

        $docTypeLabel = '';
        if (! $isTotal) {
            $dt = (int) ($r['doc_type'] ?? 99);
            $docTypeLabel = match ($dt) {
                80 => 'CUIT',
                86 => 'CUIL',
                96 => 'DNI',
                default => 'CF',
            };
        }

        $exemptOrUntaxed = $isTotal
            ? (float) ($r['exempt_or_untaxed'] ?? 0)
            : (float) (($r['exempt_amount'] ?? 0) + ($r['untaxed_amount'] ?? 0));

        return [
            $dateStr,
            (string) ($r['voucher_label'] ?? ''),
            (string) ($r['formatted_number'] ?? ''),
            $docTypeLabel,
            (string) ($r['doc_number'] ?? ''),
            (string) ($r['receiver_name'] ?? 'Consumidor Final'),
            (string) ($r['receiver_tax_condition'] ?? 'Consumidor Final'),
            (float) ($r['net_21'] ?? 0),
            (float) ($r['iva_21'] ?? 0),
            (float) ($r['net_105'] ?? 0),
            (float) ($r['iva_105'] ?? 0),
            (float) ($r['net_27'] ?? 0),
            (float) ($r['iva_27'] ?? 0),
            $exemptOrUntaxed,
            (float) ($r['tribute_amount'] ?? 0),
            (float) ($r['net_amount'] ?? 0),
            (float) ($r['iva_amount'] ?? 0),
            (float) ($r['total_amount'] ?? 0),
            (string) ($r['cae'] ?? ''),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'I' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'J' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'K' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'L' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'M' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'N' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'O' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'P' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'Q' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'R' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_WHITE]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF1E3A5F'],
                ],
            ],
        ];

        if ($highestRow > 1) {
            $styles[$highestRow] = [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE2E8F0'],
                ],
            ];
        }

        return $styles;
    }
}
