<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\SaleItem;
use App\Repositories\SalesAnalyticsRepository;
use App\Services\ReportCacheService;
use App\Services\Reports\ReportExpenseService;
use App\Services\Reports\ReportExportService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ─── Método Privado: Motor de la Mega-Query (DRY) ────────────────────────

    private SalesAnalyticsRepository $analyticsRepo;

    private ReportExportService $exportService;

    private ReportExpenseService $expenseService;

    public function __construct(
        SalesAnalyticsRepository $analyticsRepo,
        ReportExportService $exportService,
        ReportExpenseService $expenseService
    ) {
        $this->analyticsRepo = $analyticsRepo;
        $this->exportService = $exportService;
        $this->expenseService = $expenseService;
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

    private function ensureMultiRubroEnabled(): ?JsonResponse
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

        return $this->exportService->exportProfitByRubroExcel($startDate, $endDate, $rubroFilter);
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

            return $this->exportService->exportProfitByRubroExcel($startDate, $endDate, $rubroFilter);
        }

        return $this->exportService->exportProfitByCategoryExcel($startDate, $endDate, $type);
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

        return $this->exportService->exportProfitPdf(
            $data,
            'Reporte de Rentabilidad por Rubro',
            'Rubro',
            $startDate,
            $endDate,
            'rubro'
        );
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

        return $this->exportService->exportProfitPdf(
            $data,
            $reportTitle,
            $groupLabel,
            $startDate,
            $endDate,
            $type
        );
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

        return $this->exportService->exportMonthlyBalanceExcel($startMonth, $endMonth);
    }

    public function exportMonthlyBalancePdf(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));

        $data = $this->getMonthlyBalanceData($startMonth, $endMonth);

        return $this->exportService->exportMonthlyBalancePdf($data, $startMonth, $endMonth);
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

    public function expensesAnalysis(Request $request)
    {
        $filters = $this->expenseService->parseExpenseFilters($request);
        $analysis = $this->expenseService->getAnalysis($filters);

        return response()->json($analysis);
    }

    public function exportExpensesAnalysisExcel(Request $request)
    {
        $filters = $this->expenseService->parseExpenseFilters($request);

        return $this->exportService->exportExpensesAnalysisExcel($filters);
    }

    public function exportExpensesAnalysisPdf(Request $request)
    {
        $filters = $this->expenseService->parseExpenseFilters($request);

        return $this->exportService->exportExpensesAnalysisPdf($filters, $this->expenseService);
    }

    /**
     * Guarda las reglas del motor de Acceso Rápido POS y recalibra (o pone en cero)
     * los contadores de popularidad (products.sales_count).
     */
    public function recalibratePosPopularity(Request $request, StockService $stockService): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'nullable|string|in:recalibrate,reset',
            'pos_popularity_days' => 'nullable|integer|in:0,7,15,30,90',
            'pos_popularity_metric' => 'nullable|string|in:tickets,units',
            'pos_demote_out_of_stock' => 'nullable|boolean',
        ]);

        $action = $validated['action'] ?? 'recalibrate';

        if (array_key_exists('pos_popularity_days', $validated) && $validated['pos_popularity_days'] !== null) {
            BusinessSetting::updateOrCreate(
                ['key' => 'pos_popularity_days'],
                ['value' => (string) $validated['pos_popularity_days']]
            );
        }

        if (array_key_exists('pos_popularity_metric', $validated) && $validated['pos_popularity_metric'] !== null) {
            BusinessSetting::updateOrCreate(
                ['key' => 'pos_popularity_metric'],
                ['value' => $validated['pos_popularity_metric']]
            );
        }

        if (array_key_exists('pos_demote_out_of_stock', $validated) && $validated['pos_demote_out_of_stock'] !== null) {
            BusinessSetting::updateOrCreate(
                ['key' => 'pos_demote_out_of_stock'],
                ['value' => $validated['pos_demote_out_of_stock'] ? '1' : '0']
            );
        }

        if ($action === 'reset') {
            $stockService->resetPopularityCounts();
            $updatedProducts = 0;
            $message = 'Contadores de popularidad puestos en cero correctamente para la nueva temporada.';
        } else {
            // Al recalibrar manualmente desde las ventas reales, limpiamos cualquier corte previo de reset
            BusinessSetting::where('key', 'pos_popularity_reset_at')->delete();
            $days = isset($validated['pos_popularity_days']) ? (int) $validated['pos_popularity_days'] : null;
            $metric = $validated['pos_popularity_metric'] ?? null;
            $updatedProducts = $stockService->recalculatePopularityCounts($days, $metric);
            $message = "Acceso Rápido POS recalibrado ({$updatedProducts} productos con ventas en el período).";
        }

        ReportCacheService::flush();

        $settings = BusinessSetting::whereIn('key', [
            'pos_popularity_days',
            'pos_popularity_metric',
            'pos_demote_out_of_stock',
            'pos_popularity_reset_at',
        ])->pluck('value', 'key')->toArray();

        return response()->json([
            'message' => $message,
            'updated_products_count' => $updatedProducts,
            'settings' => [
                'pos_popularity_days' => (int) ($settings['pos_popularity_days'] ?? 30),
                'pos_popularity_metric' => $settings['pos_popularity_metric'] ?? 'tickets',
                'pos_demote_out_of_stock' => ($settings['pos_demote_out_of_stock'] ?? '0') === '1',
                'pos_popularity_reset_at' => $settings['pos_popularity_reset_at'] ?? null,
            ],
        ]);
    }
}
