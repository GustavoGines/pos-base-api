<?php

namespace App\Exports;

use App\Models\CashMovement;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CashMovementsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $shiftId;
    protected $category;
    protected $expenseCategoryId;

    public function __construct($shiftId, $category = null, $expenseCategoryId = null)
    {
        $this->shiftId = $shiftId;
        $this->category = $category;
        $this->expenseCategoryId = $expenseCategoryId;
    }

    public function query()
    {
        $query = CashMovement::with(['user', 'supplier'])
            ->where('cash_shift_id', $this->shiftId);
            
        if ($this->category) {
            $query->where('category', $this->category);
        }
        
        if ($this->expenseCategoryId) {
            $query->where('expense_category_id', $this->expenseCategoryId);
        }

        return $query->latest();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Fecha',
            'Tipo',
            'Categoría',
            'Monto',
            'Método Pago',
            'Usuario',
            'Proveedor/Cliente',
            'Descripción',
        ];
    }

    public function map($movement): array
    {
        return [
            $movement->id,
            $movement->created_at->format('Y-m-d H:i:s'),
            $movement->type,
            $movement->category,
            $movement->amount,
            $movement->payment_method,
            $movement->user ? $movement->user->name : '',
            $movement->supplier ? $movement->supplier->name : '',
            $movement->description,
        ];
    }
}
