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

    private function getProfitDataArray(string $startDate, string $endDate, string $fiscalFilter = 'all'): Collection
    {
        $cacheKey = ReportCacheService::key('profit_data', $startDate, $endDate, $fiscalFilter);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startDate, $endDate, $fiscalFilter) {
            return $this->analyticsRepo->getProfitReport($startDate, $endDate, 'category', null, $fiscalFilter);
        });
    }

    private function getProfitByBrandDataArray(string $startDate, string $endDate, string $fiscalFilter = 'all'): Collection
    {
        $cacheKey = ReportCacheService::key('profit_brand', $startDate, $endDate, $fiscalFilter);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startDate, $endDate, $fiscalFilter) {
            return $this->analyticsRepo->getProfitReport($startDate, $endDate, 'brand', null, $fiscalFilter);
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

    private function getProfitByRubroDataArray(string $startDate, string $endDate, mixed $rubroFilter = null, string $fiscalFilter = 'all'): Collection
    {
        [$start, $end] = $this->normalizeDateRange($startDate, $endDate);

        $cacheSuffix = ($this->normalizeRubroFilter($rubroFilter) ?? 'all').'_'.$fiscalFilter;
        $cacheKey = ReportCacheService::key('profit_rubro', $start, $end, $cacheSuffix);

        return ReportCacheService::remember($cacheKey, 900, function () use ($start, $end, $rubroFilter, $fiscalFilter) {
            return $this->analyticsRepo->getProfitReport($start, $end, 'rubro', $rubroFilter, $fiscalFilter);
        });
    }

    // ─── Endpoint: JSON para el Dashboard Flutter ─────────────────────────────

    private function getCommonStatsAndDailySales(string $startDate, string $endDate, string $fiscalFilter = 'all')
    {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        $prevStart = $start->copy()->subDays($diffDays)->toDateString();
        $prevEnd = $end->copy()->subDays($diffDays)->toDateString();

        $prevQuery = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
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
            });

        $this->analyticsRepo->applyFiscalFilter($prevQuery, $fiscalFilter);
        $prevStats = $prevQuery->first();

        $dailyQuery = DB::table('sales')
            ->whereBetween('sales.created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            });

        $this->analyticsRepo->applyFiscalFilter($dailyQuery, $fiscalFilter);

        $dailySales = $dailyQuery
            ->selectRaw('DATE(sales.created_at) as date, SUM(sales.total) as daily_revenue')
            ->groupByRaw('DATE(sales.created_at)')
            ->orderByRaw('DATE(sales.created_at) ASC')
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
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate, $fiscalFilter);
        $report = $this->getProfitDataArray($startDate, $endDate, $fiscalFilter);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'fiscal_filter' => $fiscalFilter,
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
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate, $fiscalFilter);
        $report = $this->getProfitByBrandDataArray($startDate, $endDate, $fiscalFilter);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'fiscal_filter' => $fiscalFilter,
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
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        $common = $this->getCommonStatsAndDailySales($startDate, $endDate, $fiscalFilter);
        $report = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter, $fiscalFilter);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'fiscal_filter' => $fiscalFilter,
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
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        return $this->exportService->exportProfitByRubroExcel($startDate, $endDate, $rubroFilter, $fiscalFilter);
    }

    public function exportProfitByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $type = $request->query('type', 'category');
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        if ($type === 'rubro') {
            if ($response = $this->ensureMultiRubroEnabled()) {
                return $response;
            }
            [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
            $rubroFilter = $request->query('rubro_ids');
            if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
                $rubroFilter = $request->query('rubro_id');
            }

            return $this->exportService->exportProfitByRubroExcel($startDate, $endDate, $rubroFilter, $fiscalFilter);
        }

        return $this->exportService->exportProfitByCategoryExcel($startDate, $endDate, $type, $fiscalFilter);
    }

    // ─── Endpoint: Exportar PDF ───────────────────────────────────────────────

    public function exportPdfByRubro(Request $request)
    {
        if ($response = $this->ensureMultiRubroEnabled()) {
            return $response;
        }

        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $rubroFilter = $request->query('rubro_ids');
        if ($rubroFilter === null || $rubroFilter === '' || $rubroFilter === []) {
            $rubroFilter = $request->query('rubro_id');
        }

        $data = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter, $fiscalFilter);

        return $this->exportService->exportProfitPdf(
            $data,
            'Reporte de Rentabilidad por Rubro',
            'Rubro',
            $startDate,
            $endDate,
            'rubro',
            $fiscalFilter
        );
    }

    public function exportPdfByCategory(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $type = $request->query('type', 'category');
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        if ($type === 'brand') {
            $data = $this->getProfitByBrandDataArray($startDate, $endDate, $fiscalFilter);
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
            $data = $this->getProfitByRubroDataArray($startDate, $endDate, $rubroFilter, $fiscalFilter);
            $reportTitle = 'Reporte de Rentabilidad por Rubro';
            $groupLabel = 'Rubro';
        } else {
            $data = $this->getProfitDataArray($startDate, $endDate, $fiscalFilter);
            $reportTitle = 'Reporte de Rentabilidad por Categoría';
            $groupLabel = 'Categoría';
        }

        return $this->exportService->exportProfitPdf(
            $data,
            $reportTitle,
            $groupLabel,
            $startDate,
            $endDate,
            $type,
            $fiscalFilter
        );
    }

    // ─── Endpoint: Balance Mensual Flexible ──────────────────────────────────

    private function getMonthlyBalanceData(string $startMonth, string $endMonth, string $fiscalFilter = 'all')
    {
        $cacheKey = ReportCacheService::key('balance_monthly', $startMonth, $endMonth, $fiscalFilter);

        return ReportCacheService::remember($cacheKey, 900, function () use ($startMonth, $endMonth, $fiscalFilter) {
            return $this->getMonthlyBalanceDataUncached($startMonth, $endMonth, $fiscalFilter);
        });
    }

    private function getMonthlyBalanceDataUncached(string $startMonth, string $endMonth, string $fiscalFilter = 'all')
    {
        return $this->analyticsRepo->getMonthlyBalance($startMonth, $endMonth, $fiscalFilter);
    }

    public function monthlyBalance(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        $data = $this->getMonthlyBalanceData($startMonth, $endMonth, $fiscalFilter);

        return response()->json([
            'start_month' => $startMonth,
            'end_month' => $endMonth,
            'fiscal_filter' => $fiscalFilter,
            'months' => $data['months'],
            'totals' => $data['totals'],
        ]);
    }

    public function exportMonthlyBalanceExcel(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        return $this->exportService->exportMonthlyBalanceExcel($startMonth, $endMonth, $fiscalFilter);
    }

    public function exportMonthlyBalancePdf(Request $request)
    {
        $startMonth = $request->query('start_month', Carbon::now()->subMonths(5)->format('Y-m'));
        $endMonth = $request->query('end_month', Carbon::now()->format('Y-m'));
        $fiscalFilter = (string) $request->query('fiscal_filter', 'all');

        $data = $this->getMonthlyBalanceData($startMonth, $endMonth, $fiscalFilter);

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
            'action' => 'nullable|string|in:recalibrate,save_settings,reset,restore_history',
            'pos_popularity_days' => 'nullable|integer|in:0,7,15,30,90',
            'pos_popularity_metric' => 'nullable|string|in:tickets,units',
            'pos_demote_out_of_stock' => 'nullable|boolean',
        ]);

        $action = $validated['action'] ?? 'save_settings';

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

        $days = isset($validated['pos_popularity_days']) ? (int) $validated['pos_popularity_days'] : null;
        $metric = $validated['pos_popularity_metric'] ?? null;

        if ($action === 'reset') {
            $stockService->resetPopularityCounts();
            $updatedProducts = 0;
            $message = 'Ranking del POS reiniciado en cero y guardado correctamente.';
        } elseif ($action === 'restore_history') {
            // Restauración explícita: limpia el corte de reinicio para reconstruir desde el historial de la ventana
            BusinessSetting::where('key', 'pos_popularity_reset_at')->delete();
            $updatedProducts = $stockService->recalculatePopularityCounts($days, $metric);
            $message = "Historial del Acceso Rápido restaurado ({$updatedProducts} productos con ventas en el período).";
        } else {
            // save_settings / recalibrate: guarda preferencias y recalcula respetando cualquier reinicio en cero activo
            $updatedProducts = $stockService->recalculatePopularityCounts($days, $metric);
            $message = 'Configuración del Acceso Rápido guardada y aplicada automáticamente.';
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

    /**
     * Construye el dataset consolidado del Libro IVA Ventas (ARCA/AFIP) con desglose por alícuotas (21%, 10.5%, 27%).
     */
    private function buildVatSalesData(string $startDate, string $endDate): array
    {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $startBound = Carbon::parse($startDate)->startOfDay();
        $endBound = Carbon::parse($endDate)->endOfDay();

        $invoices = \App\Models\ElectronicInvoice::where('status', 'authorized')
            ->whereNotNull('cae')
            ->where(function ($q) use ($startBound, $endBound) {
                $q->whereBetween('issued_at', [$startBound, $endBound])
                    ->orWhere(function ($sq) use ($startBound, $endBound) {
                        $sq->whereNull('issued_at')
                            ->whereBetween('created_at', [$startBound, $endBound]);
                    })
                    ->orWhereBetween('credit_note_issued_at', [$startBound, $endBound]);
            })
            ->orderBy('point_of_sale', 'asc')
            ->orderBy('voucher_type', 'asc')
            ->orderBy('voucher_number', 'asc')
            ->get();

        $extractAliquots = function (\App\Models\ElectronicInvoice $inv): array {
            $n21 = 0.0;
            $i21 = 0.0;
            $n105 = 0.0;
            $i105 = 0.0;
            $n27 = 0.0;
            $i27 = 0.0;
            $nOther = 0.0;
            $iOther = 0.0;
            $exFromBreakdown = 0.0;

            $breakdown = is_array($inv->iva_breakdown) ? $inv->iva_breakdown : [];
            if (! empty($breakdown)) {
                foreach ($breakdown as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $id = (int) ($row['Id'] ?? $row['id'] ?? 0);
                    $rate = (float) ($row['rate'] ?? $row['Alic'] ?? 0.0);
                    $base = (float) ($row['BaseImp'] ?? $row['base_imp'] ?? 0.0);
                    $imp = (float) ($row['Importe'] ?? $row['importe'] ?? 0.0);

                    if ($id === 5 || abs($rate - 21.0) < 0.1) {
                        $n21 += $base;
                        $i21 += $imp;
                    } elseif ($id === 4 || abs($rate - 10.5) < 0.1) {
                        $n105 += $base;
                        $i105 += $imp;
                    } elseif ($id === 6 || abs($rate - 27.0) < 0.1) {
                        $n27 += $base;
                        $i27 += $imp;
                    } elseif ($id === 3 || ($id === 0 && abs($rate) < 0.01 && abs($imp) < 0.01)) {
                        $exFromBreakdown += $base;
                    } else {
                        $nOther += $base;
                        $iOther += $imp;
                    }
                }
            } else {
                $net = (float) $inv->net_amount;
                $iva = (float) $inv->iva_amount;
                if ($iva > 0.0 && $net > 0.0) {
                    $ratio = ($iva / $net) * 100.0;
                    if (abs($ratio - 10.5) < 1.2) {
                        $n105 = $net;
                        $i105 = $iva;
                    } elseif (abs($ratio - 27.0) < 1.5) {
                        $n27 = $net;
                        $i27 = $iva;
                    } else {
                        $n21 = $net;
                        $i21 = $iva;
                    }
                } elseif (strtoupper((string) $inv->voucher_letter) === 'C') {
                    // Factura C (Monotributo / Exento): sin IVA discriminado
                } else {
                    $n21 = $net;
                    $i21 = $iva;
                }
            }

            return [
                'net_21' => $n21,
                'iva_21' => $i21,
                'net_105' => $n105,
                'iva_105' => $i105,
                'net_27' => $n27,
                'iva_27' => $i27,
                'net_other' => $nOther,
                'iva_other' => $iOther,
                'ex_breakdown' => $exFromBreakdown,
            ];
        };

        $vouchers = [];
        $totalNet = 0.0;
        $totalIva = 0.0;
        $totalTributes = 0.0;
        $totalExempt = 0.0;
        $totalUntaxed = 0.0;
        $totalInvoiced = 0.0;
        $totalCreditNotes = 0.0;

        $totalNet21 = 0.0;
        $totalIva21 = 0.0;
        $totalNet105 = 0.0;
        $totalIva105 = 0.0;
        $totalNet27 = 0.0;
        $totalIva27 = 0.0;
        $invoicesCount = 0;
        $creditNotesCount = 0;

        foreach ($invoices as $inv) {
            $aliquots = $extractAliquots($inv);
            $letter = strtoupper((string) ($inv->voucher_letter ?: 'B'));

            $issuedAt = $inv->issued_at ?? $inv->created_at;
            if ($issuedAt && $issuedAt->between($startBound, $endBound)) {
                $net = (float) $inv->net_amount;
                $iva = (float) $inv->iva_amount;
                $trib = (float) $inv->tribute_amount;
                $ex = (float) $inv->exempt_amount;
                $untax = (float) $inv->untaxed_amount;
                $tot = (float) $inv->total_amount;

                $totalNet += $net;
                $totalIva += $iva;
                $totalTributes += $trib;
                $totalExempt += $ex;
                $totalUntaxed += $untax;
                $totalInvoiced += $tot;

                $totalNet21 += $aliquots['net_21'];
                $totalIva21 += $aliquots['iva_21'];
                $totalNet105 += $aliquots['net_105'];
                $totalIva105 += $aliquots['iva_105'];
                $totalNet27 += $aliquots['net_27'];
                $totalIva27 += $aliquots['iva_27'];
                $invoicesCount++;

                $vouchers[] = [
                    'id' => $inv->id,
                    'sale_id' => $inv->sale_id,
                    'kind' => 'invoice',
                    'voucher_type' => $inv->voucher_type,
                    'voucher_letter' => $letter,
                    'voucher_label' => "Factura {$letter}",
                    'point_of_sale' => $inv->point_of_sale,
                    'voucher_number' => $inv->voucher_number,
                    'formatted_number' => $inv->formatted_number,
                    'cae' => $inv->cae,
                    'issued_at' => $issuedAt->toIso8601String(),
                    'doc_type' => $inv->doc_type,
                    'doc_number' => $inv->doc_number,
                    'receiver_name' => $inv->receiver_name,
                    'receiver_tax_condition' => $inv->receiver_tax_condition,
                    'net_21' => round($aliquots['net_21'], 2),
                    'iva_21' => round($aliquots['iva_21'], 2),
                    'net_105' => round($aliquots['net_105'], 2),
                    'iva_105' => round($aliquots['iva_105'], 2),
                    'net_27' => round($aliquots['net_27'], 2),
                    'iva_27' => round($aliquots['iva_27'], 2),
                    'net_amount' => round($net, 2),
                    'iva_amount' => round($iva, 2),
                    'tribute_amount' => round($trib, 2),
                    'exempt_amount' => round($ex, 2),
                    'untaxed_amount' => round($untax, 2),
                    'total_amount' => round($tot, 2),
                ];
            }

            if (! empty($inv->credit_note_cae) && $inv->credit_note_issued_at && $inv->credit_note_issued_at->between($startBound, $endBound)) {
                $net = (float) $inv->net_amount;
                $iva = (float) $inv->iva_amount;
                $trib = (float) $inv->tribute_amount;
                $ex = (float) $inv->exempt_amount;
                $untax = (float) $inv->untaxed_amount;
                $tot = (float) $inv->total_amount;

                $totalNet -= $net;
                $totalIva -= $iva;
                $totalTributes -= $trib;
                $totalExempt -= $ex;
                $totalUntaxed -= $untax;
                $totalCreditNotes += $tot;

                $totalNet21 -= $aliquots['net_21'];
                $totalIva21 -= $aliquots['iva_21'];
                $totalNet105 -= $aliquots['net_105'];
                $totalIva105 -= $aliquots['iva_105'];
                $totalNet27 -= $aliquots['net_27'];
                $totalIva27 -= $aliquots['iva_27'];
                $creditNotesCount++;

                $vouchers[] = [
                    'id' => $inv->id,
                    'sale_id' => $inv->sale_id,
                    'kind' => 'credit_note',
                    'voucher_type' => $inv->credit_note_voucher_type,
                    'voucher_letter' => $letter,
                    'voucher_label' => "Nota de Crédito {$letter}",
                    'point_of_sale' => $inv->point_of_sale,
                    'voucher_number' => $inv->credit_note_number,
                    'formatted_number' => sprintf('%05d-%08d', (int) $inv->point_of_sale, (int) $inv->credit_note_number),
                    'cae' => $inv->credit_note_cae,
                    'issued_at' => $inv->credit_note_issued_at->toIso8601String(),
                    'doc_type' => $inv->doc_type,
                    'doc_number' => $inv->doc_number,
                    'receiver_name' => $inv->receiver_name,
                    'receiver_tax_condition' => $inv->receiver_tax_condition,
                    'net_21' => round(-$aliquots['net_21'], 2),
                    'iva_21' => round(-$aliquots['iva_21'], 2),
                    'net_105' => round(-$aliquots['net_105'], 2),
                    'iva_105' => round(-$aliquots['iva_105'], 2),
                    'net_27' => round(-$aliquots['net_27'], 2),
                    'iva_27' => round(-$aliquots['iva_27'], 2),
                    'net_amount' => round(-$net, 2),
                    'iva_amount' => round(-$iva, 2),
                    'tribute_amount' => round(-$trib, 2),
                    'exempt_amount' => round(-$ex, 2),
                    'untaxed_amount' => round(-$untax, 2),
                    'total_amount' => round(-$tot, 2),
                ];
            }
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_net' => round($totalNet, 2),
                'total_iva' => round($totalIva, 2),
                'total_tributes' => round($totalTributes, 2),
                'total_exempt' => round($totalExempt, 2),
                'total_untaxed' => round($totalUntaxed, 2),
                'total_invoiced' => round($totalInvoiced, 2),
                'total_credit_notes' => round($totalCreditNotes, 2),
                'net_fiscal_total' => round($totalInvoiced - $totalCreditNotes, 2),
                'net_21' => round($totalNet21, 2),
                'iva_21' => round($totalIva21, 2),
                'net_105' => round($totalNet105, 2),
                'iva_105' => round($totalIva105, 2),
                'net_27' => round($totalNet27, 2),
                'iva_27' => round($totalIva27, 2),
                'invoices_count' => $invoicesCount,
                'credit_notes_count' => $creditNotesCount,
                'vouchers_count' => count($vouchers),
            ],
            'vouchers' => $vouchers,
        ];
    }

    /**
     * REP-11: Libro IVA Ventas consolidado para contabilidad fiscal AFIP/ARCA.
     * GET /api/reports/vat-sales?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function vatSales(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        return response()->json($this->buildVatSalesData($startDate, $endDate));
    }

    /**
     * Exporta el Libro IVA Ventas a Excel (.xlsx).
     * GET /api/reports/vat-sales/export?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function exportVatSalesExcel(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $data = $this->buildVatSalesData($startDate, $endDate);

        return $this->exportService->exportVatSalesExcel($data, $data['start_date'], $data['end_date']);
    }

    /**
     * Exporta el Libro IVA Ventas a PDF.
     * GET /api/reports/vat-sales/pdf?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function exportVatSalesPdf(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());
        $data = $this->buildVatSalesData($startDate, $endDate);

        return $this->exportService->exportVatSalesPdf($data, $data['start_date'], $data['end_date']);
    }
}
