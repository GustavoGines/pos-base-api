<?php

namespace App\Services\Reports;

use App\Exports\ExpensesAnalysisExport;
use App\Exports\MonthlyBalanceExport;
use App\Exports\ProfitByCategoryExport;
use App\Exports\ProfitByRubroExport;
use App\Exports\VatPurchasesExport;
use App\Exports\VatSalesExport;
use App\Models\BusinessSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportService
{
    /**
     * Export Profit by Rubro to Excel.
     */
    public function exportProfitByRubroExcel(string $startDate, string $endDate, mixed $rubroFilter, string $fiscalFilter = 'all'): BinaryFileResponse
    {
        return Excel::download(
            new ProfitByRubroExport($startDate, $endDate, $rubroFilter, null, $fiscalFilter),
            'reporte_ganancias_rubros.xlsx'
        );
    }

    /**
     * Export Profit by Category / Brand to Excel.
     */
    public function exportProfitByCategoryExcel(string $startDate, string $endDate, string $type, string $fiscalFilter = 'all'): BinaryFileResponse
    {
        $filename = $type === 'brand' ? 'reporte_ganancias_marcas.xlsx' : 'reporte_ganancias_categorias.xlsx';

        return Excel::download(
            new ProfitByCategoryExport($startDate, $endDate, $type, null, null, $fiscalFilter),
            $filename
        );
    }

    /**
     * Export Profit PDF (Rubro, Category or Brand).
     */
    public function exportProfitPdf(
        Collection $data,
        string $reportTitle,
        string $groupLabel,
        string $startDate,
        string $endDate,
        string $type = 'category',
        string $fiscalFilter = 'all'
    ): Response {
        $totalRevenue = $data->sum('total_revenue');
        $totalProfit = $data->sum('total_profit');
        $totalWithCost = $data->sum('revenue_with_cost');
        $totalCost = $totalWithCost - $data->sum('total_profit');
        $avgMargin = $totalWithCost > 0 ? ($totalProfit / $totalWithCost) * 100 : 0;

        $salesByPlanQuery = DB::table('sales')
            ->selectRaw("COALESCE(price_list, 'base') as plan_name, COUNT(*) as total_tickets, SUM(total) as total_revenue")
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'sales.customer_id')
                    ->where('customers.is_internal_account', true);
            });

        if ($fiscalFilter === 'fiscal') {
            $salesByPlanQuery->where('invoice_status', 'invoiced');
        } elseif ($fiscalFilter === 'non_fiscal') {
            $salesByPlanQuery->where(function ($q) {
                $q->whereNull('invoice_status')
                    ->orWhere('invoice_status', '!=', 'invoiced');
            });
        }

        $salesByPlan = $salesByPlanQuery
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

    /**
     * Export Monthly Balance to Excel.
     */
    public function exportMonthlyBalanceExcel(string $startMonth, string $endMonth, string $fiscalFilter = 'all'): BinaryFileResponse
    {
        return Excel::download(
            new MonthlyBalanceExport($startMonth, $endMonth, null, $fiscalFilter),
            'balance_mensual_'.$startMonth.'_al_'.$endMonth.'.xlsx'
        );
    }

    /**
     * Export Monthly Balance to PDF.
     */
    public function exportMonthlyBalancePdf(array $data, string $startMonth, string $endMonth): Response
    {
        $pdf = Pdf::loadView('reports.pdf_monthly_balance', [
            'data' => $data,
            'startMonth' => $startMonth,
            'endMonth' => $endMonth,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('balance_mensual_'.$startMonth.'_al_'.$endMonth.'.pdf');
    }

    /**
     * Export Expenses Analysis to Excel.
     */
    public function exportExpensesAnalysisExcel(array $filters): BinaryFileResponse
    {
        return Excel::download(
            new ExpensesAnalysisExport(
                $filters['startDate'],
                $filters['endDate'],
                $filters['includeSuppliers'],
                $filters['paymentMethod'],
                $filters['minAmount'],
                $filters['includeWithdrawals'] ?? true,
                $filters['cashRegisterId'] ?? null
            ),
            'analisis_gastos_'.str_replace('-', '', $filters['startDate']).'_al_'.str_replace('-', '', $filters['endDate']).'.xlsx'
        );
    }

    /**
     * Export Expenses Analysis to PDF.
     */
    public function exportExpensesAnalysisPdf(array $filters, ReportExpenseService $expenseService): Response
    {
        $expenses = $expenseService->buildBaseExpensesQuery(
            $filters['startDate'],
            $filters['endDate'],
            $filters['includeSuppliers'],
            $filters['paymentMethod'],
            $filters['minAmount'],
            $filters['includeWithdrawals'] ?? true,
            $filters['cashRegisterId'] ?? null
        )
            ->selectRaw("
            CASE 
                WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                WHEN cash_movements.type = 'withdrawal' THEN
                    CASE
                        WHEN cash_movements.category IS NULL OR TRIM(cash_movements.category) = '' OR cash_movements.category = 'Retiro'
                        THEN 'Retiro de Caja'
                        ELSE cash_movements.category
                    END
                ELSE COALESCE(expense_categories.name, 'Sin Categoría')
            END as category_name,
            SUM(cash_movements.amount) as total_amount,
            COUNT(*) as transactions
        ")
            ->groupBy(DB::raw("
            CASE 
                WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                WHEN cash_movements.type = 'withdrawal' THEN
                    CASE
                        WHEN cash_movements.category IS NULL OR TRIM(cash_movements.category) = '' OR cash_movements.category = 'Retiro'
                        THEN 'Retiro de Caja'
                        ELSE cash_movements.category
                    END
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

    /**
     * Export Libro IVA Ventas (ARCA/AFIP) to Excel.
     */
    public function exportVatSalesExcel(array $data, string $startDate, string $endDate): BinaryFileResponse
    {
        return Excel::download(
            new VatSalesExport($data),
            'libro_iva_ventas_'.str_replace('-', '', $startDate).'_al_'.str_replace('-', '', $endDate).'.xlsx'
        );
    }

    /**
     * Export Libro IVA Ventas (ARCA/AFIP) to PDF.
     */
    public function exportVatSalesPdf(array $data, string $startDate, string $endDate): Response
    {
        $settings = BusinessSetting::whereIn('key', ['company_name', 'tax_id'])->pluck('value', 'key');

        $pdf = Pdf::loadView('reports.pdf_vat_sales', [
            'data' => $data,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'companyName' => $settings['company_name'] ?? 'Comercio Responsable Inscripto',
            'companyCuit' => $settings['tax_id'] ?? '',
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('libro_iva_ventas_'.str_replace('-', '', $startDate).'_al_'.str_replace('-', '', $endDate).'.pdf');
    }

    /**
     * Export Libro IVA Compras (ARCA/AFIP) to Excel.
     */
    public function exportVatPurchasesExcel(array $data, string $startDate, string $endDate): BinaryFileResponse
    {
        return Excel::download(
            new VatPurchasesExport($data),
            'libro_iva_compras_'.str_replace('-', '', $startDate).'_al_'.str_replace('-', '', $endDate).'.xlsx'
        );
    }

    /**
     * Export Libro IVA Compras (ARCA/AFIP) to PDF.
     */
    public function exportVatPurchasesPdf(array $data, string $startDate, string $endDate): Response
    {
        $settings = BusinessSetting::whereIn('key', ['company_name', 'tax_id'])->pluck('value', 'key');

        $pdf = Pdf::loadView('reports.pdf_vat_purchases', [
            'data' => $data,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'companyName' => $settings['company_name'] ?? 'Comercio Responsable Inscripto',
            'companyCuit' => $settings['tax_id'] ?? '',
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('libro_iva_compras_'.str_replace('-', '', $startDate).'_al_'.str_replace('-', '', $endDate).'.pdf');
    }
}
