<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportExpenseService
{
    /**
     * Parse and normalize query filters for expense analysis.
     */
    public function parseExpenseFilters(Request $request): array
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

        $includeWithdrawals = true;
        if ($request->has('include_withdrawals')) {
            $rawWithdrawals = $request->query('include_withdrawals');
            if ($rawWithdrawals !== '' && $rawWithdrawals !== null) {
                $includeWithdrawals = filter_var($rawWithdrawals, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $cashRegisterId = null;
        if ($request->filled('cash_register_id') && is_numeric($request->query('cash_register_id'))) {
            $regVal = (int) $request->query('cash_register_id');
            if ($regVal > 0) {
                $cashRegisterId = $regVal;
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
            'includeWithdrawals' => $includeWithdrawals,
            'cashRegisterId' => $cashRegisterId,
            'paymentMethod' => $paymentMethod,
            'minAmount' => $minAmount,
        ];
    }

    /**
     * Build base query for cash movements filtered by date, supplier/withdrawal inclusion, payment method, register, and min amount.
     */
    public function buildBaseExpensesQuery(
        string $startDate,
        string $endDate,
        bool $includeSuppliers = true,
        ?string $paymentMethod = null,
        ?float $minAmount = null,
        bool $includeWithdrawals = true,
        ?int $cashRegisterId = null
    ) {
        $types = ['expense'];
        if ($includeSuppliers) {
            $types[] = 'supplier_payment';
        }
        if ($includeWithdrawals) {
            $types[] = 'withdrawal';
        }

        $query = DB::table('cash_movements')
            ->leftJoin('expense_categories', 'cash_movements.expense_category_id', '=', 'expense_categories.id')
            ->leftJoin('cash_shifts', 'cash_movements.cash_shift_id', '=', 'cash_shifts.id')
            ->leftJoin('cash_registers', 'cash_shifts.cash_register_id', '=', 'cash_registers.id')
            ->whereIn('cash_movements.type', $types)
            ->where(function ($q) {
                $q->whereNull('cash_movements.category')
                    ->orWhere('cash_movements.category', '!=', 'refund');
            })
            ->whereNull('cash_movements.deleted_at')
            ->whereBetween('cash_movements.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);

        if ($cashRegisterId !== null && $cashRegisterId > 0) {
            $query->where('cash_shifts.cash_register_id', $cashRegisterId);
        }

        if ($paymentMethod !== null) {
            $query->where('cash_movements.payment_method', $paymentMethod);
        }

        if ($minAmount !== null && $minAmount > 0) {
            $query->where('cash_movements.amount', '>=', $minAmount);
        }

        return $query;
    }

    /**
     * Compute full expense analysis dataset.
     */
    public function getAnalysis(array $filters): array
    {
        $movements = $this->buildBaseExpensesQuery(
            $filters['startDate'],
            $filters['endDate'],
            $filters['includeSuppliers'],
            $filters['paymentMethod'],
            $filters['minAmount'],
            $filters['includeWithdrawals'] ?? true,
            $filters['cashRegisterId'] ?? null
        )
            ->select(
                'cash_movements.id',
                'cash_movements.amount',
                'cash_movements.description',
                'cash_movements.created_at',
                'cash_movements.type',
                'cash_movements.cash_shift_id',
                'cash_shifts.cash_register_id',
                DB::raw("COALESCE(cash_registers.name, 'Caja Principal') as cash_register_name"),
                DB::raw("
                CASE 
                    WHEN cash_movements.type = 'supplier_payment' THEN 'Pago a Proveedor'
                    WHEN cash_movements.type = 'withdrawal' THEN
                        CASE
                            WHEN cash_movements.category IS NULL OR TRIM(cash_movements.category) = '' OR cash_movements.category = 'Retiro'
                            THEN 'Retiro de Caja'
                            ELSE cash_movements.category
                        END
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
                        'type' => $m->type,
                        'cash_shift_id' => $m->cash_shift_id ? (int) $m->cash_shift_id : null,
                        'cash_register_id' => $m->cash_register_id ? (int) $m->cash_register_id : null,
                        'cash_register_name' => $m->cash_register_name ?? 'Caja Principal',
                    ];
                })->values()->all(),
            ];
        }

        usort($expenses, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        $expenses = collect($expenses);

        return [
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
        ];
    }
}
