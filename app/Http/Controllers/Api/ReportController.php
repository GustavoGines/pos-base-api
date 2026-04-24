<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SaleItem;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ─── Método Privado: Motor de la Mega-Query (DRY) ────────────────────────

    private function getProfitDataArray(string $startDate, string $endDate): \Illuminate\Support\Collection
    {
        $productStats = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('
                COALESCE(categories.name, "Sin Categoría") as category_name,
                products.id as product_id,
                products.name as product_name,
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
                ) as revenue_with_cost,
                COUNT(CASE
                    WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                      OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                    THEN 1
                END) as items_with_cost,
                COUNT(*) as total_items
            ')
            ->whereBetween('sales.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('sales.status', 'completed')
            ->groupBy('products.category_id', 'categories.name', 'products.id', 'products.name')
            ->get();

        return $productStats->groupBy('category_name')->map(function ($items, $categoryName) {
            return [
                'category_name'   => $categoryName,
                'items_sold'      => $items->sum('items_sold'),
                'total_revenue'   => $items->sum('total_revenue'),
                'total_profit'    => $items->sum('total_profit'),
                'revenue_with_cost' => $items->sum('revenue_with_cost'),
                'items_with_cost' => $items->sum('items_with_cost'),
                'total_items'     => $items->sum('total_items'),
                'products'        => $items->sortByDesc('total_revenue')->map(function ($prod) {
                    return [
                        'product_id'       => $prod->product_id,
                        'product_name'     => $prod->product_name,
                        'items_sold'       => (int)   $prod->items_sold,
                        'total_revenue'    => (float) $prod->total_revenue,
                        'total_profit'     => (float) $prod->total_profit,
                        'revenue_with_cost'=> (float) $prod->revenue_with_cost,
                        'items_with_cost'  => (int)   $prod->items_with_cost,
                        'total_items'      => (int)   $prod->total_items,
                    ];
                })->values(),
            ];
        })->sortByDesc('total_revenue')->values();
    }

    private function getProfitByBrandDataArray(string $startDate, string $endDate): \Illuminate\Support\Collection
    {
        $productStats = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->selectRaw('
                COALESCE(brands.name, "Sin Marca") as category_name,
                products.id as product_id,
                products.name as product_name,
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
                ) as revenue_with_cost,
                COUNT(CASE
                    WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                      OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                    THEN 1
                END) as items_with_cost,
                COUNT(*) as total_items
            ')
            ->whereBetween('sales.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('sales.status', 'completed')
            ->groupBy('products.brand_id', 'brands.name', 'products.id', 'products.name')
            ->get();

        return $productStats->groupBy('category_name')->map(function ($items, $categoryName) {
            return [
                'category_name'   => $categoryName, // Reutilizamos la misma key para el frontend
                'items_sold'      => $items->sum('items_sold'),
                'total_revenue'   => $items->sum('total_revenue'),
                'total_profit'    => $items->sum('total_profit'),
                'revenue_with_cost' => $items->sum('revenue_with_cost'),
                'items_with_cost' => $items->sum('items_with_cost'),
                'total_items'     => $items->sum('total_items'),
                'products'        => $items->sortByDesc('total_revenue')->map(function ($prod) {
                    return [
                        'product_id'       => $prod->product_id,
                        'product_name'     => $prod->product_name,
                        'items_sold'       => (int)   $prod->items_sold,
                        'total_revenue'    => (float) $prod->total_revenue,
                        'total_profit'     => (float) $prod->total_profit,
                        'revenue_with_cost'=> (float) $prod->revenue_with_cost,
                        'items_with_cost'  => (int)   $prod->items_with_cost,
                        'total_items'      => (int)   $prod->total_items,
                    ];
                })->values(),
            ];
        })->sortByDesc('total_revenue')->values();
    }

    // ─── Endpoint: JSON para el Dashboard Flutter ─────────────────────────────

    public function profitByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->query('end_date',   Carbon::now()->endOfMonth()->toDateString());

        $start    = Carbon::parse($startDate);
        $end      = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        $prevStart = $start->copy()->subDays($diffDays)->toDateString();
        $prevEnd   = $end->copy()->subDays($diffDays)->toDateString();

        $report = $this->getProfitDataArray($startDate, $endDate);

        $prevStats = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->selectRaw('
                SUM(sale_items.subtotal) as total_revenue,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                        ELSE 0
                    END
                ) as total_profit
            ')
            ->whereBetween('sales.created_at', [$prevStart . ' 00:00:00', $prevEnd . ' 23:59:59'])
            ->where('sales.status', 'completed')
            ->first();

        $dailySales = DB::table('sales')
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(total) as daily_revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get();

        return response()->json([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'previous_period' => [
                'start_date' => $prevStart,
                'end_date'   => $prevEnd,
                'revenue'    => (float) ($prevStats->total_revenue ?? 0),
                'profit'     => (float) ($prevStats->total_profit  ?? 0),
            ],
            'daily_evolution' => $dailySales,
            'data'            => $report,
        ]);
    }

    public function profitByBrand(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->query('end_date',   Carbon::now()->endOfMonth()->toDateString());

        $start    = Carbon::parse($startDate);
        $end      = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        $prevStart = $start->copy()->subDays($diffDays)->toDateString();
        $prevEnd   = $end->copy()->subDays($diffDays)->toDateString();

        $report = $this->getProfitByBrandDataArray($startDate, $endDate);

        $prevStats = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->selectRaw('
                SUM(sale_items.subtotal) as total_revenue,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                        ELSE 0
                    END
                ) as total_profit
            ')
            ->whereBetween('sales.created_at', [$prevStart . ' 00:00:00', $prevEnd . ' 23:59:59'])
            ->where('sales.status', 'completed')
            ->first();

        $dailySales = DB::table('sales')
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(total) as daily_revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get();

        return response()->json([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'previous_period' => [
                'start_date' => $prevStart,
                'end_date'   => $prevEnd,
                'revenue'    => (float) ($prevStats->total_revenue ?? 0),
                'profit'     => (float) ($prevStats->total_profit  ?? 0),
            ],
            'daily_evolution' => $dailySales,
            'data'            => $report,
        ]);
    }

    // ─── Endpoint: Exportar Excel ─────────────────────────────────────────────

    public function exportProfitByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->query('end_date',   Carbon::now()->endOfMonth()->toDateString());

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\ProfitByCategoryExport($startDate, $endDate),
            'reporte_ganancias.xlsx'
        );
    }

    // ─── Endpoint: Exportar PDF ───────────────────────────────────────────────

    public function exportPdfByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate   = $request->query('end_date',   Carbon::now()->endOfMonth()->toDateString());

        $data = $this->getProfitDataArray($startDate, $endDate);

        // Totales globales para la sección KPI del encabezado
        $totalRevenue    = $data->sum('total_revenue');
        $totalProfit     = $data->sum('total_profit');
        $totalWithCost   = $data->sum('revenue_with_cost');
        $totalCost       = $totalWithCost - $data->sum('total_profit');
        $avgMargin       = $totalWithCost > 0 ? ($totalProfit / $totalWithCost) * 100 : 0;

        $pdf = Pdf::loadView('reports.pdf_profit', [
            'data'          => $data,
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'totalRevenue'  => $totalRevenue,
            'totalProfit'   => $totalProfit,
            'totalCost'     => $totalCost,
            'avgMargin'     => $avgMargin,
            'generatedAt'   => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('reporte_ganancias_' . $startDate . '_' . $endDate . '.pdf');
    }

    // ─── Endpoint: Balance Mensual Flexible ──────────────────────────────────

    public function monthlyBalance(Request $request)
    {
        // Valores por defecto: mes actual − 5 meses → mes actual
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth   = $request->query('end_month',   Carbon::now()->format('Y-m'));

        // Convertimos YYYY-MM al primer y último día del rango completo
        $startDate = Carbon::parse($startMonth . '-01')->startOfMonth();
        $endDate   = Carbon::parse($endMonth   . '-01')->endOfMonth();

        // Query por mes: agrupa ventas + calcula costo histórico (mismo fallback que el resto)
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

        // Enriquecemos con etiqueta legible y margen
        $months = $rows->map(function ($row) {
            $date         = Carbon::parse($row->period . '-01');
            $marginPct    = $row->revenue_with_cost > 0
                            ? round(($row->total_profit / $row->revenue_with_cost) * 100, 1)
                            : 0.0;
            return [
                'period'       => $row->period,
                'label'        => $date->translatedFormat('M Y'),   // "Abr 2026"
                'total_revenue'=> round((float) $row->total_revenue, 2),
                'total_cost'   => round((float) $row->total_cost,    2),
                'total_profit' => round((float) $row->total_profit,  2),
                'transactions' => (int) $row->transactions,
                'margin_pct'   => $marginPct,
            ];
        });

        // Totales acumulados del rango completo
        $grandRevenue     = $months->sum('total_revenue');
        $grandCost        = $months->sum('total_cost');
        $grandProfit      = $months->sum('total_profit');
        $grandRWC         = $rows->sum('revenue_with_cost');
        $grandMargin      = $grandRWC > 0 ? round(($grandProfit / $grandRWC) * 100, 1) : 0.0;

        return response()->json([
            'start_month' => $startMonth,
            'end_month'   => $endMonth,
            'months'      => $months,
            'totals'      => [
                'total_revenue' => round($grandRevenue, 2),
                'total_cost'    => round($grandCost,    2),
                'total_profit'  => round($grandProfit,  2),
                'avg_margin_pct'=> $grandMargin,
            ],
        ]);
    }
}
