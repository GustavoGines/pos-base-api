<?php

namespace App\Exports;

use App\Models\SaleItem;
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

class ProfitByCategoryExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping, WithColumnFormatting
{
    protected $startDate;
    protected $endDate;
    protected $type;

    public function __construct($startDate, $endDate, $type = 'category')
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->type = $type;
    }

    public function collection()
    {
        $query = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id');

        if ($this->type === 'brand') {
            $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->selectRaw('
                    COALESCE(brands.name, "Sin Marca") as category_name,
                    SUM(sale_items.quantity) as items_sold,
                    SUM(sale_items.subtotal) as total_revenue,
                    SUM(
                        CASE
                            WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                            THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                            WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                            THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                            ELSE 0
                        END
                    ) as total_profit,
                    SUM(
                        CASE
                            WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                              OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                            THEN sale_items.subtotal
                            ELSE 0
                        END
                    ) as revenue_with_cost
                ')
                ->groupBy('products.brand_id', 'brands.name');
        } else {
            $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw('
                    COALESCE(categories.name, "Sin Categoría") as category_name,
                    SUM(sale_items.quantity) as items_sold,
                    SUM(sale_items.subtotal) as total_revenue,
                    SUM(
                        CASE
                            WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                            THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                            WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                            THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                            ELSE 0
                        END
                    ) as total_profit,
                    SUM(
                        CASE
                            WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                              OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                            THEN sale_items.subtotal
                            ELSE 0
                        END
                    ) as revenue_with_cost
                ')
                ->groupBy('products.category_id', 'categories.name');
        }

        return $query->whereBetween('sales.created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->where('sales.status', 'completed')
            ->orderByDesc('total_revenue')
            ->get();
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
        $revenueWithCost = (float) $row->revenue_with_cost;
        $totalProfit = (float) $row->total_profit;
        $margin = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            $row->category_name,
            (int) $row->items_sold,
            (float) $row->total_revenue,
            (float) $row->total_profit,
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
                    'startColor' => ['argb' => 'FFE0E0E0'], // Gris claro
                ],
            ],
        ];
    }
}
