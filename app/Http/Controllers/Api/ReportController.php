<?php

namespace App\Http\Controllers\Api;

use App\Exports\ExpensesAnalysisExport;
use App\Exports\MonthlyBalanceExport;
use App\Exports\ProfitByCategoryExport;
use App\Exports\ProfitByRubroExport;
use App\Http\Controllers\Controller;
use App\Models\SaleItem;
use App\Repositories\SalesAnalyticsRepository;
use App\Services\ReportCacheService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    // ─── Método Privado: Motor de la Mega-Query (DRY) ────────────────────────

    private SalesAnalyticsRepository $analyticsRepo;

    public function __construct(SalesAnalyticsRepository $analyticsRepo)
    {
        $this->analyticsRepo = $analyticsRepo;
    }

    private function getProfitDataArray(string $startDate, string $endDate): Collection
    {
        $cacheKey = ReportCacheService::key('profit_data', $startDate, $endDate);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startDate, $endDate) {
            return $this->analyticsRepo->getProfitReport($startDate, $endDate, 'category');
        });
    }

    private function getProfitByBrandDataArray(string $startDate, string $endDate): Collection
    {
        $cacheKey = ReportCacheService::key('profit_brand', $startDate, $endDate);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startDate, $endDate) {
            return $this->analyticsRepo->getProfitReport($startDate, $endDate, 'brand');
        });
    }

    private function ensureMultiRubroEnabled(): ?\Illuminate\Http\JsonResponse
    {
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');

        $features = [];
        if (! empty($featuresJson)) {
            $decoded = json_decode($featuresJson, true);
            if (is_array($decoded)) {
                $features = $decoded;
            }
        }

        if (! isset($features['multi_rubro']) || $features['multi_rubro'] !== true) {
            return response()->json([
                'message' => "La licencia activa no incluye el módulo requerido: 'multi_rubro'. Actualice su plan.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required' => 'multi_rubro',
            ], 403);
        }

        return null;
    }

    private function normalizeRubroFilter(mixed $rubroFilter): ?string
    {
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            return null;
        }

        $rawIds = is_array($rubroFilter) ? $rubroFilter : [$rubroFilter];
        $ids = [];
        foreach ($rawIds as $item) {
            if (is_string($item) && str_contains($item, ',')) {
                $ids = array_merge($ids, explode(',', $item));
            } else {
                $ids[] = $item;
            }
        }

        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));

        if (empty($cleanIds)) {
            return 'empty';
        }

        sort($cleanIds);

        return implode('_', $cleanIds);
    }

    private function normalizeDateRange(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();
        if (Carbon::parse($start)->gt(Carbon::parse($end))) {
            return [$end, $start];
        }

        return [$start, $end];
    }

    private function getProfitByRubroDataArray(string $startDate, string $endDate, mixed $rubroFilter = null): Collection
    {
        [$start, $end] = $this->normalizeDateRange($startDate, $endDate);

        $cacheSuffix = $this->normalizeRubroFilter($rubroFilter) ?? 'all';
        $cacheKey = ReportCacheService::key('profit_rubro', $start, $end, $cacheSuffix);

        return ReportCacheService::remember($cacheKey, 900, function () use ($start, $end, $rubroFilter) {
            return $this->analyticsRepo->getProfitReport($start, $end, 'rubro', $rubroFilter);
        });
    }

    // ─── Endpoint: JSON para el Dashboard Flutter ─────────────────────────────

    private function getCommonStatsAndDailySales(string $startDate, string $endDate)
    {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        $prevStart = $start->copy()->subDays($diffDays)->toDateString();
        $prevEnd = $end->copy()->subDays($diffDays)->toDateString();

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
            ->whereBetween('sales.created_at', [Carbon::parse($prevStart)->startOfDay(), Carbon::parse($prevEnd)->endOfDay()])
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            })
            ->first();

        $dailySales = DB::table('sales')
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            })
            ->selectRaw('DATE(created_at) as date, SUM(total) as daily_revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get();

        return [
            'prevStart' => $prevStart,
            'prevEnd' => $prevEnd,
            'prevStats' => $prevStats,
            'dailySales' => $dailySales,
        ];
    }

    public function profitByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate);
        $report = $this->getProfitDataArray($startDate, $endDate);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'previous_period' => [
                'start_date' => $common['prevStart'],
                'end_date' => $common['prevEnd'],
                'revenue' => (float) ($common['prevStats']->total_revenue ?? 0),
                'profit' => (float) ($common['prevStats']->total_profit ?? 0),
            ],
            'daily_evolution' => $common['dailySales'],
            'data' => $report,
        ]);
    }

    public function profitByBrand(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate);
        $report = $this->getProfitByBrandDataArray($startDate, $endDate);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'previous_period' => [
                'start_date' => $common['prevStart'],
                'end_date' => $common['prevEnd'],
                'revenue' => (float) ($common['prevStats']->total_revenue ?? 0),
                'profit' => (float) ($common['prevStats']->total_profit ?? 0),
            ],
            'daily_evolution' => $common['dailySales'],
            'data' => $report,
        ]);
    }

    public function profitByRubro(Request $request)
    {
        if ($response = $this->ensureMultiRubroEnabled()) {
            return $response;
        }

        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate);
        $report = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'previous_period' => [
                'start_date' => $common['prevStart'],
                'end_date' => $common['prevEnd'],
                'revenue' => (float) ($common['prevStats']->total_revenue ?? 0),
                'profit' => (float) ($common['prevStats']->total_profit ?? 0),
            ],
            'daily_evolution' => $common['dailySales'],
            'data' => $report,
        ]);
    }

    // ─── Endpoint: Exportar Excel ─────────────────────────────────────────────

    public function exportProfitByRubro(Request $request)
    {
        if ($response = $this->ensureMultiRubroEnabled()) {
            return $response;
        }

        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        return Excel::download(
            new ProfitByRubroExport($startDate, $endDate, $rubroFilter),
            'reporte_ganancias_rubros.xlsx'
        );
    }

    public function exportProfitByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $type = $request->query('type', 'category');

        if ($type === 'rubro') {
            if ($response = $this->ensureMultiRubroEnabled()) {
                return $response;
            }
            [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
            $rubroFilter = $request->query('rubro_ids');
            if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
                $rubroFilter = $request->query('rubro_id');
            }
            return Excel::download(
                new ProfitByRubroExport($startDate, $endDate, $rubroFilter),
                'reporte_ganancias_rubros.xlsx'
            );
        }

        $filename = $type === 'brand' ? 'reporte_ganancias_marcas.xlsx' : 'reporte_ganancias_categorias.xlsx';

        return Excel::download(
            new ProfitByCategoryExport($startDate, $endDate, $type),
            $filename
        );
    }

    // ─── Endpoint: Exportar PDF ───────────────────────────────────────────────

    public function exportPdfByRubro(Request $request)
    {
        if ($response = $this->ensureMultiRubroEnabled()) {
            return $response;
        }

        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        $data = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter);
        $reportTitle = 'Reporte de Rentabilidad por Rubro';

        $totalRevenue = $data->sum('total_revenue');
        $totalProfit = $data->sum('total_profit');
        $totalWithCost = $data->sum('revenue_with_cost');
        $totalCost = $totalWithCost - $data->sum('total_profit');
        $avgMargin = $totalWithCost > 0 ? ($totalProfit / $totalWithCost) * 100 : 0;

        $salesByPlan = DB::table('sales')
            ->selectRaw("COALESCE(price_list, 'base') as plan_name, COUNT(*) as total_tickets, SUM(total) as total_revenue")
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            })
            ->groupByRaw("COALESCE(price_list, 'base')")
            ->orderByDesc('total_revenue')
            ->get();

        $pdf = Pdf::loadView('reports.pdf_profit', [
            'data' => $data,
            'reportTitle' => $reportTitle,
            'groupLabel' => 'Rubro',
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalRevenue' => $totalRevenue,
            'totalProfit' => $totalProfit,
            'totalCost' => $totalCost,
            'avgMargin' => $avgMargin,
            'salesByPlan' => $salesByPlan,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('reporte_ganancias_rubros_'.$startDate.'_'.$endDate.'.pdf');
    }

    public function exportPdfByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $type = $request->query('type', 'category');

        if ($type === 'brand') {
            $data = $this->getProfitByBrandDataArray($startDate, $endDate);
            $reportTitle = 'Reporte de Rentabilidad por Marca';
            $groupLabel = 'Marca';
        } elseif ($type === 'rubro') {
            if ($response = $this->ensureMultiRubroEnabled()) {
                return $response;
            }
            [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
            $rubroFilter = $request->query('rubro_ids');
            if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
                $rubroFilter = $request->query('rubro_id');
            }
            $data = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter);
            $reportTitle = 'Reporte de Rentabilidad por Rubro';
            $groupLabel = 'Rubro';
        } else {
            $data = $this->getProfitDataArray($startDate, $endDate);
            $reportTitle = 'Reporte de Rentabilidad por Categoría';
            $groupLabel = 'Categoría';
        }

        $totalRevenue = $data->sum('total_revenue');
        $totalProfit = $data->sum('total_profit');
        $totalWithCost = $data->sum('revenue_with_cost');
        $totalCost = $totalWithCost - $data->sum('total_profit');
        $avgMargin = $totalWithCost > 0 ? ($totalProfit / $totalWithCost) * 100 : 0;

        $salesByPlan = DB::table('sales')
            ->selectRaw("COALESCE(price_list, 'base') as plan_name, COUNT(*) as total_tickets, SUM(total) as total_revenue")
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            })
            ->groupByRaw("COALESCE(price_list, 'base')")
            ->orderByDesc('total_revenue')
            ->get();

        $pdf = Pdf::loadView('reports.pdf_profit', [
            'data' => $data,
            'reportTitle' => $reportTitle,
            'groupLabel' => $groupLabel,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalRevenue' => $totalRevenue,
            'totalProfit' => $totalProfit,
            'totalCost' => $totalCost,
            'avgMargin' => $avgMargin,
            'salesByPlan' => $salesByPlan,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        $filePrefix = match ($type) {
            'brand' => 'reporte_ganancias_marcas_',
            'rubro' => 'reporte_ganancias_rubros_',
            default => 'reporte_ganancias_categorias_',
        };

        return $pdf->download($filePrefix.$startDate.'_'.$endDate.'.pdf');
    }

    // ─── Endpoint: Balance Mensual Flexible ──────────────────────────────────

    private function getMonthlyBalanceData(string $startMonth, string $endMonth)
    {
        $cacheKey = ReportCacheService::key('balance_monthly', $startMonth, $endMonth);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startMonth, $endMonth) {
            return $this->getMonthlyBalanceDataUncached($startMonth, $endMonth);
        });
    }

    private function getMonthlyBalanceDataUncached(string $startMonth, string $endMonth)
    {
        return $this->analyticsRepo->getMonthlyBalance($startMonth, $endMonth);
    }

    public function monthlyBalance(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));

        $data = $this->getMonthlyBalanceData($startMonth, $endMonth);

        return response()->json([
            'start_month' => $startMonth,
            'end_month' => $endMonth,
            'months' => $data['months'],
            'totals' => $data['totals'],
        ]);
    }

    public function exportMonthlyBalanceExcel(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));

        return Excel::download(
            new MonthlyBalanceExport($startMonth, $endMonth),
            'balance_mensual_'.$startMonth.'_al_'.$endMonth.'.xlsx'
        );
    }

    public function exportMonthlyBalancePdf(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));

        $data = $this->getMonthlyBalanceData($startMonth, $endMonth);

        $pdf = Pdf::loadView('reports.pdf_monthly_balance', [
            'data' => $data,
            'startMonth' => $startMonth,
            'endMonth' => $endMonth,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('balance_mensual_'.$startMonth.'_al_'.$endMonth.'.pdf');
    }

    public function internalConsumption(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $customerId = $request->query('customer_id');

        $query = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('customers.is_internal_account', true)
            ->whereBetween('sales.created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('sales.status', 'completed');

        if ($customerId) {
            $query->where('sales.customer_id', $customerId);
        }

        $report = $query->selectRaw('
                products.id as product_id, 
                products.name as product_name, 
                SUM(sale_items.quantity) as total_quantity, 
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.unit_cost_price * sale_items.quantity
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN products.cost_price * sale_items.quantity
                        ELSE 0
                    END
                ) as total_cost
            ')
            ->groupBy('products.id', 'products.name')
            ->get();

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data' => $report,
        ]);
    }

    /**
     * Parse and normalize query filters for expense analysis.
     */
    private function parseExpenseFilters(Request $request): array
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $includeSuppliers = true;
        if ($request->has('include_suppliers')) {
            $rawInclude = $request->query('include_suppliers');
            if ($rawInclude !== '' && $rawInclude !== null) {
                $includeSuppliers = filter_var($rawInclude, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $paymentMethod = $request->query('payment_method');
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $normalized = strtolower(trim((string) $paymentMethod));
            $map = [
                'efectivo' => 'cash',
                'cash' => 'cash',
                'transferencia' => 'transfer',
                'transfer' => 'transfer',
                'cheque' => 'check',
                'check' => 'check',
            ];
            $paymentMethod = $map[$normalized] ?? null;
        } else {
            $paymentMethod = null;
        }

        $minAmount = null;
        if ($request->filled('min_amount') && is_numeric($request->query('min_amount'))) {
            $val = (float) $request->query('min_amount');
            if ($val > 0) {
                $minAmount = $val;
            }
        }

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'includeSuppliers' => $includeSuppliers,
            'paymentMethod' => $paymentMethod,
            'minAmount' => $minAmount,
        ];
    }

    /**
     * Build base query for cash movements filtered by date, supplier inclusion, payment method, and min amount.
     */
    private function buildBaseExpensesQuery(
        string $startDate,
        string $endDate,
        bool $includeSuppliers = true,
        ?string $paymentMethod = null,
        ?float $minAmount = null
    ) {
        $types = $includeSuppliers ? ['expense', 'supplier_payment'] : ['expense'];

        $query = DB::table('cash_movements')
            ->leftJoin('expense_categories', 'cash_movements.expense_category_id', '=', 'expense_categories.id')
            ->whereIn('cash_movements.type', $types)
            ->whereNull('cash_movements.deleted_at')
            ->whereBetween('cash_movements.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);

        if ($paymentMethod !== null) {
            $query->where('cash_movements.payment_method', $paymentMethod);
        }

        if ($minAmount !== null && $minAmount > 0) {
            $query->where('cash_movements.amount', '>=', $minAmount);
        }

        return $query;
    }

    public function expensesAnalysis(Request $request)
    {
        $filters = $this->parseExpenseFilters($request);

        $movements = $this->buildBaseExpensesQuery(
            $filters['startDate'],
            $filters['endDate'],
            $filters['includeSuppliers'],
            $filters['paymentMethod'],
            $filters['minAmount']
        )
            ->select(
                'cash_movements.id',
                'cash_movements.amount',
                'cash_movements.description',
                'cash_movements.created_at',
                'cash_movements.type',
                DB::raw("
                CASE 
                    WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                    ELSE COALESCE(expense_categories.name, 'Sin Categoría')
                END as category_name
            ")
            )
            ->orderByDesc('cash_movements.created_at')
            ->get();

        $grouped = $movements->groupBy('category_name');

        $expenses = [];
        $totalExpenses = 0;

        foreach ($grouped as $categoryName => $items) {
            $sum = $items->sum('amount');
            $totalExpenses += $sum;

            $expenses[] = [
                'category_name' => $categoryName,
                'total_amount' => $sum,
                'transactions' => $items->count(),
                'movements' => $items->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'amount' => (float) $m->amount,
                        'description' => $m->description,
                        'date' => $m->created_at,
                    ];
                })->values()->all(),
            ];
        }

        // Sort by total amount desc
        usort($expenses, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        $expenses = collect($expenses);

        return response()->json([
            'start_date' => $filters['startDate'],
            'end_date' => $filters['endDate'],
            'total_expenses' => round($totalExpenses, 2),
            'by_category' => $expenses->map(function ($row) use ($totalExpenses) {
                return [
                    'category' => $row['category_name'],
                    'amount' => round((float) $row['total_amount'], 2),
                    'transactions' => (int) $row['transactions'],
                    'percentage' => $totalExpenses > 0 ? round(($row['total_amount'] / $totalExpenses) * 100, 1) : 0,
                    'movements' => $row['movements'],
                ];
            }),
        ]);
    }

    public function exportExpensesAnalysisExcel(Request $request)
    {
        $filters = $this->parseExpenseFilters($request);

        return Excel::download(
            new ExpensesAnalysisExport(
                $filters['startDate'],
                $filters['endDate'],
                $filters['includeSuppliers'],
                $filters['paymentMethod'],
                $filters['minAmount']
            ),
            'analisis_gastos_'.str_replace('-', '', $filters['startDate']).'_al_'.str_replace('-', '', $filters['endDate']).'.xlsx'
        );
    }

    public function exportExpensesAnalysisPdf(Request $request)
    {
        $filters = $this->parseExpenseFilters($request);

        $expenses = $this->buildBaseExpensesQuery(
            $filters['startDate'],
            $filters['endDate'],
            $filters['includeSuppliers'],
            $filters['paymentMethod'],
            $filters['minAmount']
        )
            ->selectRaw("
            CASE 
                WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                ELSE COALESCE(expense_categories.name, 'Sin Categoría')
            END as category_name,
            SUM(cash_movements.amount) as total_amount,
            COUNT(*) as transactions
        ")
            ->groupBy(DB::raw("
            CASE 
                WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                ELSE COALESCE(expense_categories.name, 'Sin Categoría')
            END
        "))
            ->orderByDesc('total_amount')
            ->get();

        $totalExpenses = $expenses->sum('total_amount');
        $startDate = $filters['startDate'];
        $endDate = $filters['endDate'];

        $pdf = Pdf::loadView('reports.pdf_expenses_analysis', compact('expenses', 'startDate', 'endDate', 'totalExpenses'));

        return $pdf->download('analisis_gastos_'.str_replace('-', '', $startDate).'_al_'.str_replace('-', '', $endDate).'.pdf');
    }
}
