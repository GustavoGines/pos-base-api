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

class VatPurchasesExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function title(): string
    {
        return 'Libro IVA Compras';
    }

    public function collection()
    {
        $vouchers = collect($this->data['vouchers'] ?? []);
        $summary = $this->data['summary'] ?? [];

        if ($vouchers->isNotEmpty()) {
            $vouchers->push([
                '_is_total_row' => true,
                'issue_date' => null,
                'voucher_label' => 'TOTALES DEL PERÍODO',
                'formatted_number' => ($summary['vouchers_count'] ?? $vouchers->count()).' comprobantes',
                'supplier_cuit' => '',
                'supplier_name' => '',
                'supplier_tax_condition' => '',
                'net_21' => (float) ($summary['total_net_21'] ?? 0),
                'iva_21' => (float) ($summary['total_iva_21'] ?? 0),
                'net_105' => (float) ($summary['total_net_105'] ?? 0),
                'iva_105' => (float) ($summary['total_iva_105'] ?? 0),
                'net_27' => (float) ($summary['total_net_27'] ?? 0),
                'iva_27' => (float) ($summary['total_iva_27'] ?? 0),
                'non_taxed_amount' => (float) ($summary['total_non_taxed'] ?? 0),
                'exempt_amount' => (float) ($summary['total_exempt'] ?? 0),
                'iva_perception' => (float) ($summary['total_iva_perception'] ?? 0),
                'iibb_perception' => (float) ($summary['total_iibb_perception'] ?? 0),
                'internal_taxes' => (float) ($summary['total_internal_taxes'] ?? 0),
                'credit_fiscal' => (float) ($summary['total_credit_fiscal'] ?? 0),
                'total_amount' => (float) ($summary['total_amount'] ?? 0),
                '_total_net' => (float) ($summary['total_net'] ?? 0),
                '_total_credit_fiscal' => (float) ($summary['total_credit_fiscal'] ?? 0),
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
            'CUIT Proveedor',
            'Proveedor / Razón Social',
            'Condición IVA',
            'Neto Grav. 21%',
            'IVA 21%',
            'Neto Grav. 10.5%',
            'IVA 10.5%',
            'Neto Grav. 27%',
            'IVA 27%',
            'No Gravado / Exento',
            'Percep. IVA',
            'Percep. IIBB',
            'Imp. Internos',
            'Total Neto Gravado',
            'Total IVA Crédito Fiscal',
            'Total Comprobante',
        ];
    }

    public function map($row): array
    {
        $r = is_array($row) ? $row : (array) $row;
        $isTotal = ! empty($r['_is_total_row']);

        $dateStr = '';
        if (! $isTotal && ! empty($r['issue_date'])) {
            try {
                $dateStr = Carbon::parse($r['issue_date'])->format('d/m/Y');
            } catch (\Throwable) {
                $dateStr = (string) $r['issue_date'];
            }
        }

        $nonTaxedOrExempt = (float) (($r['non_taxed_amount'] ?? 0) + ($r['exempt_amount'] ?? 0));

        $netTotal = $isTotal
            ? (float) ($r['_total_net'] ?? 0)
            : (float) (($r['net_21'] ?? 0) + ($r['net_105'] ?? 0) + ($r['net_27'] ?? 0));

        $creditFiscalTotal = $isTotal
            ? (float) ($r['_total_credit_fiscal'] ?? 0)
            : (float) ($r['credit_fiscal'] ?? (($r['iva_21'] ?? 0) + ($r['iva_105'] ?? 0) + ($r['iva_27'] ?? 0)));

        return [
            $dateStr,
            (string) ($r['voucher_label'] ?? ''),
            (string) ($r['formatted_number'] ?? ($r['invoice_number'] ?? '')),
            (string) ($r['supplier_cuit'] ?? ''),
            (string) ($r['supplier_name'] ?? 'Proveedor'),
            (string) ($r['supplier_tax_condition'] ?? ($r['tax_category'] ?? 'Responsable Inscripto')),
            (float) ($r['net_21'] ?? 0),
            (float) ($r['iva_21'] ?? 0),
            (float) ($r['net_105'] ?? 0),
            (float) ($r['iva_105'] ?? 0),
            (float) ($r['net_27'] ?? 0),
            (float) ($r['iva_27'] ?? 0),
            $nonTaxedOrExempt,
            (float) ($r['iva_perception'] ?? 0),
            (float) ($r['iibb_perception'] ?? 0),
            (float) ($r['internal_taxes'] ?? 0),
            $netTotal,
            $creditFiscalTotal,
            (float) ($r['total_amount'] ?? 0),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
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
            'S' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
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
