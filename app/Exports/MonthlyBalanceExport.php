<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
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

class MonthlyBalanceExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping, WithColumnFormatting
{
    protected $startMonth;
    protected $endMonth;

    public function __construct($startMonth, $endMonth)
    {
        $this->startMonth = $startMonth;
        $this->endMonth = $endMonth;
    }

    public function collection()
    {
        $startDate = Carbon::parse($this->startMonth . '-01')->startOfMonth();
        $endDate   = Carbon::parse($this->endMonth   . '-01')->endOfMonth();

        $rows = DB::table('sale_items')
            ->join('sales',    'sales.id',    '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [
                $startDate->toDateTimeString(),
                $endDate->toDateTimeString(),
            ])
            ->selectRaw("
                DATE_FORMAT(sales.created_at, '%Y-%m') as period,
                SUM(sale_items.subtotal)               as total_revenue,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.unit_cost_price * sale_items.quantity
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN products.cost_price * sale_items.quantity
                        ELSE 0
                    END
                )                                       as total_cost,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                        ELSE 0
                    END
                )                                       as total_profit,
                SUM(
                    CASE
                        WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                          OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                        THEN sale_items.subtotal
                        ELSE 0
                    END
                )                                       as revenue_with_cost,
                COUNT(DISTINCT sales.id)               as transactions
            ")
            ->groupByRaw("DATE_FORMAT(sales.created_at, '%Y-%m')")
            ->orderByRaw("period ASC")
            ->get();

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Mes / Período',
            'Tickets Emitidos',
            'Facturación Total',
            'Costo de Ventas',
            'Ganancia Neta',
            'Margen Promedio (%)'
        ];
    }

    public function map($row): array
    {
        $date = Carbon::parse($row->period . '-01');
        $revenueWithCost = (float) $row->revenue_with_cost;
        $totalProfit = (float) $row->total_profit;
        $margin = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            ucfirst($date->translatedFormat('F Y')),
            (int) $row->transactions,
            (float) $row->total_revenue,
            (float) $row->total_cost,
            (float) $row->total_profit,
            $margin
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
