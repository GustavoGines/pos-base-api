<?php

namespace App\Exports;

use App\Models\CashMovement;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CashMovementsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $shiftId;

    protected $category;

    protected $expenseCategoryId;

    protected $startDate;

    protected $endDate;

    protected $cashRegisterId;

    public function __construct($shiftId, $category = null, $expenseCategoryId = null, $startDate = null, $endDate = null, $cashRegisterId = null)
    {
        $this->shiftId = $shiftId;
        $this->category = $category;
        $this->expenseCategoryId = $expenseCategoryId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->cashRegisterId = $cashRegisterId;
    }

    public function query()
    {
        $query = CashMovement::with(['user', 'supplier', 'cashShift.cashRegister:id,name']);
        if ($this->shiftId) {
            $query->where('cash_shift_id', $this->shiftId);
        } elseif ($this->cashRegisterId) {
            $query->whereHas('cashShift', fn ($q) => $q->where('cash_register_id', $this->cashRegisterId));
        }

        if ($this->category) {
            $cat = $this->category;
            if ($cat === 'Retiro de Caja' || $cat === 'Retiro') {
                $query->where(fn ($q) => $q->where('type', 'withdrawal')->orWhereIn('category', ['Retiro', 'Retiro de Caja']));
            } elseif ($cat === 'Gasto') {
                $query->where(fn ($q) => $q
                    ->where(fn ($sq) => $sq->where('type', 'expense')->where(fn ($rq) => $rq->whereNull('category')->orWhere('category', '!=', 'refund')))
                    ->orWhere('category', 'Gasto'));
            } elseif ($cat === 'Ingreso Manual') {
                $query->where(fn ($q) => $q->where('type', 'deposit')->orWhere('category', 'Ingreso Manual'));
            } elseif ($cat === 'Pago a Proveedor') {
                $query->where(fn ($q) => $q->where('type', 'supplier_payment')->orWhere('category', 'Pago a Proveedor'));
            } else {
                $query->where('category', $cat);
            }
        }

        if ($this->expenseCategoryId) {
            $query->where('expense_category_id', $this->expenseCategoryId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate.' 00:00:00', $this->endDate.' 23:59:59']);
        }

        return $query->latest();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Fecha',
            'Caja',
            'Turno',
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
        $registerName = $movement->cashShift?->cashRegister?->name
            ?? ($movement->cashShift?->cash_register_id ? 'Caja '.$movement->cashShift->cash_register_id : 'Caja Principal');

        return [
            $movement->id,
            $movement->created_at->format('Y-m-d H:i:s'),
            $registerName,
            $movement->cash_shift_id ? '#'.$movement->cash_shift_id : '',
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
