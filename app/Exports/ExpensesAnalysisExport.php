<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExpensesAnalysisExport implements FromCollection, WithHeadings, WithMapping, WithCustomStartCell, WithStyles
{
    protected $startDate;
    protected $endDate;
    protected $totalExpenses;

    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        $expenses = DB::table('cash_movements')
            ->leftJoin('expense_categories', 'cash_movements.expense_category_id', '=', 'expense_categories.id')
            ->where('cash_movements.type', 'expense')
            ->whereNull('cash_movements.deleted_at')
            ->whereBetween('cash_movements.created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->selectRaw("
                COALESCE(expense_categories.name, 'Sin Categoría') as category_name,
                SUM(cash_movements.amount) as total_amount,
                COUNT(*) as transactions
            ")
            ->groupBy(DB::raw("COALESCE(expense_categories.name, 'Sin Categoría')"))
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
            'Porcentaje del Total'
        ];
    }

    public function map($row): array
    {
        $percentage = $this->totalExpenses > 0 ? ($row->total_amount / $this->totalExpenses) * 100 : 0;
        return [
            $row->category_name,
            $row->transactions,
            '$' . number_format($row->total_amount, 2, '.', ''),
            number_format($percentage, 1, '.', '') . '%'
        ];
    }

    public function startCell(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet)
    {
        // Título del reporte en A1
        $sheet->setCellValue('A1', 'Análisis de Gastos (' . $this->startDate . ' al ' . $this->endDate . ')');
        $sheet->mergeCells('A1:D1');
        
        // Estilos
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:D2')->getFont()->setBold(true);
        $sheet->getStyle('A2:D2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD3D3D3');
        
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(25);
        
        // Total en la última fila
        $highestRow = $sheet->getHighestRow();
        $totalRow = $highestRow + 2;
        $sheet->setCellValue('A' . $totalRow, 'TOTAL');
        $sheet->setCellValue('C' . $totalRow, '$' . number_format($this->totalExpenses, 2, '.', ''));
        $sheet->getStyle('A' . $totalRow . ':C' . $totalRow)->getFont()->setBold(true);
    }
}
