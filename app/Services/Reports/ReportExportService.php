<?php

namespace App\Services\Reports;

use App\Exports\ExpensesAnalysisExport;
use App\Exports\MonthlyBalanceExport;
use App\Exports\ProfitByCategoryExport;
use App\Exports\ProfitByRubroExport;
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
    public function exportProfitByRubroExcel(string $startDate, string $endDate, mixed $rubroFilter): BinaryFileResponse
    {
        return Excel::download(
            new ProfitByRubroExport($startDate, $endDate, $rubroFilter),
            'reporte_ganancias_rubros.xlsx'
        );
    }

    /**
     * Export Profit by Category / Brand to Excel.
     */
    public function exportProfitByCategoryExcel(string $startDate, string $endDate, string $type): BinaryFileResponse
    {
        $filename = $type === 'brand' ? 'reporte_ganancias_marcas.xlsx' : 'reporte_ganancias_categorias.xlsx';

        return Excel::download(
            new ProfitByCategoryExport($startDate, $endDate, $type),
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
        string $type = 'category'
    ): Response {
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

    /**
     * Export Monthly Balance to Excel.
     */
    public function exportMonthlyBalanceExcel(string $startMonth, string $endMonth): BinaryFileResponse
    {
        return Excel::download(
            new MonthlyBalanceExport($startMonth, $endMonth),
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
                $filters['minAmount']
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
