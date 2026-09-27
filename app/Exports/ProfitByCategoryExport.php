<?php

namespace App\Exports;

use App\Repositories\SalesAnalyticsRepository;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;

class ProfitByCategoryExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping, WithColumnFormatting
{
    protected $startDate;
    protected $endDate;
    protected $type;
    protected SalesAnalyticsRepository $repository;

    public function __construct($startDate, $endDate, $type = 'category', ?SalesAnalyticsRepository $repository = null)
    {
        $this->startDate  = $startDate;
        $this->endDate    = $endDate;
        $this->type       = $type;
        $this->repository = $repository ?? app(SalesAnalyticsRepository::class);
    }

    public function collection()
    {
        return $this->repository->getProfitReport($this->startDate, $this->endDate, $this->type);
    }

    public function headings(): array
    {
        return [
            $this->type === 'brand' ? 'Marca' : 'Categoría',
            'Cantidad Vendida',
            'Facturación',
            'Ganancia Neta',
            'Margen Promedio (%)'
        ];
    }

    public function map($row): array
    {
        $data = is_array($row) ? $row : (array) $row;
        $revenueWithCost = (float) ($data['revenue_with_cost'] ?? 0);
        $totalProfit = (float) ($data['total_profit'] ?? 0);
        $margin = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            $data['category_name'] ?? '',
            (int) ($data['items_sold'] ?? 0),
            (float) ($data['total_revenue'] ?? 0),
            (float) ($data['total_profit'] ?? 0),
            $margin
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_PERCENTAGE_00,
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
