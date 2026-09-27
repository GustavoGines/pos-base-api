<?php

namespace App\Exports;

use App\Repositories\SalesAnalyticsRepository;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlyBalanceExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles
{
    protected $startMonthOrYear;

    protected $endMonth;

    protected SalesAnalyticsRepository $repository;

    public function __construct($startMonthOrYear = null, $endMonth = null, ?SalesAnalyticsRepository $repository = null)
    {
        $this->startMonthOrYear = $startMonthOrYear ?? Carbon::now()->subMonths(5)->format('Y-m');
        $this->endMonth = $endMonth;
        $this->repository = $repository ?? app(SalesAnalyticsRepository::class);
    }

    public function collection()
    {
        $data = $this->repository->getMonthlyBalance($this->startMonthOrYear, $this->endMonth);

        return collect($data['months'] ?? []);
    }

    public function headings(): array
    {
        return [
            'Mes / Período',
            'Tickets Emitidos',
            'Facturación Total',
            'Costo de Ventas',
            'Ganancia Neta',
            'Margen Promedio (%)',
        ];
    }

    public function map($row): array
    {
        $data = is_array($row) ? $row : (array) $row;
        $date = Carbon::parse(($data['period'] ?? now()->format('Y-m')).'-01');
        $revenueWithCost = (float) ($data['revenue_with_cost'] ?? $data['total_revenue'] ?? 0);
        $totalProfit = (float) ($data['total_profit'] ?? 0);
        $margin = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            ucfirst($date->translatedFormat('F Y')),
            (int) ($data['transactions'] ?? 0),
            (float) ($data['total_revenue'] ?? 0),
            (float) ($data['total_cost'] ?? 0),
            (float) ($data['total_profit'] ?? 0),
            $margin,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'F' => NumberFormat::FORMAT_PERCENTAGE_00,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];
    }
}
