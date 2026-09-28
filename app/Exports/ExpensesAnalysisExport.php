<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExpensesAnalysisExport implements FromCollection, WithCustomStartCell, WithHeadings, WithMapping, WithStyles
{
    protected $startDate;

    protected $endDate;

    protected $includeSuppliers;

    protected $paymentMethod;

    protected $minAmount;

    protected $totalExpenses;

    public function __construct(
        $startDate,
        $endDate,
        $includeSuppliers = true,
        $paymentMethod = null,
        $minAmount = null
    ) {
        $this->startDate = $startDate;
        $this->endDate = $endDate;

        if (is_bool($includeSuppliers)) {
            $this->includeSuppliers = $includeSuppliers;
        } else {
            $this->includeSuppliers = filter_var($includeSuppliers, FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($paymentMethod) && $paymentMethod !== 'all') {
            $normalized = strtolower(trim((string) $paymentMethod));
            $map = [
                'efectivo' => 'cash',
                'cash' => 'cash',
                'transferencia' => 'transfer',
                'transfer' => 'transfer',
                'cheque' => 'check',
                'check' => 'check',
            ];
            $this->paymentMethod = $map[$normalized] ?? $paymentMethod;
        } else {
            $this->paymentMethod = null;
        }

        $this->minAmount = ($minAmount !== null && is_numeric($minAmount) && (float) $minAmount > 0)
            ? (float) $minAmount
            : null;
    }

    public function collection()
    {
        $types = $this->includeSuppliers ? ['expense', 'supplier_payment'] : ['expense'];

        $query = DB::table('cash_movements')
            ->leftJoin('expense_categories', 'cash_movements.expense_category_id', '=', 'expense_categories.id')
            ->whereIn('cash_movements.type', $types)
            ->whereNull('cash_movements.deleted_at')
            ->whereBetween('cash_movements.created_at', [
                Carbon::parse($this->startDate)->startOfDay(),
                Carbon::parse($this->endDate)->endOfDay(),
            ]);

        if ($this->paymentMethod !== null) {
            $query->where('cash_movements.payment_method', $this->paymentMethod);
        }

        if ($this->minAmount !== null && $this->minAmount > 0) {
            $query->where('cash_movements.amount', '>=', $this->minAmount);
        }

        $expenses = $query
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

        $this->totalExpenses = $expenses->sum('total_amount');

        return $expenses;
    }

    public function headings(): array
    {
        return [
            'Categoría',
            'Cantidad de Movimientos',
            'Monto Total',
            'Porcentaje del Total',
        ];
    }

    public function map($row): array
    {
        $percentage = $this->totalExpenses > 0 ? ($row->total_amount / $this->totalExpenses) * 100 : 0;

        return [
            $row->category_name,
            $row->transactions,
            '$'.number_format($row->total_amount, 2, '.', ''),
            number_format($percentage, 1, '.', '').'%',
        ];
    }

    public function startCell(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet)
    {
        // Título del reporte en A1
        $sheet->setCellValue('A1', 'Análisis de Gastos ('.$this->startDate.' al '.$this->endDate.')');
        $sheet->mergeCells('A1:D1');

        // Estilos
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:D2')->getFont()->setBold(true);
        $sheet->getStyle('A2:D2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD3D3D3');

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(25);

        // Total en la última fila
        $highestRow = $sheet->getHighestRow();
        $totalRow = $highestRow + 2;
        $sheet->setCellValue('A'.$totalRow, 'TOTAL');
        $sheet->setCellValue('C'.$totalRow, '$'.number_format($this->totalExpenses, 2, '.', ''));
        $sheet->getStyle('A'.$totalRow.':C'.$totalRow)->getFont()->setBold(true);
    }
}
