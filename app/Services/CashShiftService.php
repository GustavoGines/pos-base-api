<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\ThirdPartyCheck;
use Exception;
use Illuminate\Support\Facades\DB;

class CashShiftService
{
    /**
     * Verifica si la licencia actual tiene el Addon 'multi_caja'.
     */
    public function hasMultiCajaPermission(): bool
    {
        // 1. Revisar el diccionario de features explícitas (SaaS)
        $featuresJson = BusinessSetting::where('key', 'license_features_dict')->value('value');
        if (! empty($featuresJson)) {
            $decoded = json_decode($featuresJson, true);
            if (is_array($decoded) && ! empty($decoded['multi_caja'])) {
                return true;
            }
        }

        // 2. Failsafe por plan: Plan PRO/Premium incluye TODOS los módulos sin necesidad de addons explícitos.
        $planType = strtolower(BusinessSetting::where('key', 'app_plan')->value('value') ?? '');
        if (in_array($planType, ['pro', 'premium'])) {
            return true;
        }

        return false;
    }

    /**
     * Obtiene la Caja Principal obligatoria, creándola si no existe.
     */
    private function getPrimaryRegister(): CashRegister
    {
        return CashRegister::firstOrCreate(
            ['id' => 1],
            ['name' => 'Caja Principal', 'is_active' => true]
        );
    }

    /**
     * Abre un turno validando la licencia, aislando concurrencia (Locking)
     * y limitando cajas según plan.
     */
    public function openShift(int $userId, float $openingBalance, ?int $registerId = null): CashShift
    {
        return DB::transaction(function () use ($userId, $openingBalance, $registerId) {
            $isPro = $this->hasMultiCajaPermission();

            if (! $isPro) {
                // Plan Básico: Forzar apertura en Caja Principal
                $register = $this->getPrimaryRegister();
            } else {
                // Plan Pro: Usa la caja indicada, o fallback a Principal
                $register = $registerId ? CashRegister::findOrFail($registerId) : $this->getPrimaryRegister();
            }

            // PESSIMISTIC LOCK: Bloquea la Caja Física para que nadie más la modifique
            // mientras consultamos si tiene turnos abiertos y creamos el nuevo.
            $lockedRegister = CashRegister::where('id', $register->id)->lockForUpdate()->first();

            // Validación de existencia de turno activo para ESTA caja local
            $openShift = CashShift::where('cash_register_id', $lockedRegister->id)
                ->where('status', 'open')
                ->first();

            if ($openShift) {
                throw new Exception('Ya existe un turno abierto en esta caja.', 403);
            }

            // Límite Básico Global: Prohibir a toda costa más de 1 turno en todo el local
            if (! $isPro) {
                $globalOpenCount = CashShift::where('status', 'open')->count();
                if ($globalOpenCount > 0) {
                    throw new Exception('Límite de cajas alcanzado. Actualice su plan a PRO para abrir turnos paralelos.', 403);
                }
            }

            return CashShift::create([
                'cash_register_id' => $lockedRegister->id,
                'user_id' => $userId,
                'opened_at' => now(),
                'opening_balance' => $openingBalance,
                'status' => 'open',
            ]);
        });
    }

    /**
     * Obtiene el turno activo según reglas del Plan.
     *
     * Plan Básico: Siempre busca en la Caja Principal.
     * Plan PRO:    Si se pasa $registerId, busca en esa caja específica.
     *              Si NO se pasa $registerId (ej: login de empleado sin selector de caja),
     *              hace fallback a la Caja Principal para no dejar al empleado huérfano.
     */
    public function getCurrentShift(?int $registerId = null): ?CashShift
    {
        $isPro = $this->hasMultiCajaPermission();

        if (! $isPro) {
            // Plan Básico: Una sola caja, turno global del local
            $register = $this->getPrimaryRegister();

            return CashShift::where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->first();
        } else {
            if ($registerId) {
                // Plan PRO con caja específica: buscar en esa caja
                return CashShift::where('cash_register_id', $registerId)
                    ->where('status', 'open')
                    ->first();
            }
            // Plan PRO sin caja indicada: fallback a Caja Principal
            // Esto evita que un empleado nuevo quede huérfano y abra un turno paralelo
            $register = $this->getPrimaryRegister();

            return CashShift::where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->first();
        }
    }

    /**
     * Cierre ciego: El cajero manda el arqueo constatado físico.
     * Nosotros calculamos el balance y hallamos la difference sin revelar monto antes.
     */
    public function calculateLiveTotals(CashShift $shift): array
    {
        $shiftId = $shift->id;

        // Sumatoria Financiera: Solo ventas COMPLETADAS
        $cashSales = SalePayment::whereHas('sale', fn ($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
            ->whereHas('paymentMethod', fn ($q) => $q->where('is_cash', true))
            ->sum('total_amount');
        $cashSales += CustomerTransaction::where('cash_shift_id', $shiftId)->where('type', 'payment')->where('payment_method', 'cash')->sum('amount');

        $cardSales = SalePayment::whereHas('sale', fn ($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
            ->whereHas('paymentMethod', fn ($q) => $q->where('code', 'like', 'card_%'))
            ->sum('total_amount');
        $cardSales += CustomerTransaction::where('cash_shift_id', $shiftId)->where('type', 'payment')->where('payment_method', 'card')->sum('amount');

        $transferSales = SalePayment::whereHas('sale', fn ($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
            ->whereHas('paymentMethod', fn ($q) => $q->where('code', 'transfer'))
            ->sum('total_amount');
        $transferSales += CustomerTransaction::where('cash_shift_id', $shiftId)->where('type', 'payment')->where('payment_method', 'transfer')->sum('amount');

        $totalSurcharge = Sale::where('cash_shift_id', $shiftId)
            ->where('status', 'completed')
            ->sum('total_surcharge');

        // Cheques recibidos en el turno (ignorando los anulados por ventas anuladas)
        $checkSales = ThirdPartyCheck::where('cash_shift_id', $shiftId)->where('status', '!=', 'voided')->sum('amount');
        $checkCount = ThirdPartyCheck::where('cash_shift_id', $shiftId)->where('status', '!=', 'voided')->count();
        $checkDetails = ThirdPartyCheck::where('cash_shift_id', $shiftId)
            ->where('status', '!=', 'voided')
            ->get(['id', 'bank_name', 'check_number', 'amount', 'payment_date', 'issuer_name'])
            ->toArray();

        // Ventas en Cuenta Corriente
        $ccSales = SalePayment::whereHas('sale', fn ($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
            ->whereHas('paymentMethod', fn ($q) => $q->where('code', 'cuenta_corriente'))
            ->sum('total_amount');

        $ccSalesCount = Sale::where('cash_shift_id', $shiftId)
            ->where('status', 'completed')
            ->whereHas('payments.paymentMethod', fn ($q) => $q->where('code', 'cuenta_corriente'))
            ->count();

        // Movimientos manuales de caja
        $cashDeposits = CashMovement::where('cash_shift_id', $shiftId)->where('payment_method', 'cash')->where('type', 'deposit')->sum('amount');
        $cashExpenses = CashMovement::where('cash_shift_id', $shiftId)->where('payment_method', 'cash')->where('type', 'expense')->sum('amount');
        $cashWithdrawals = CashMovement::where('cash_shift_id', $shiftId)->where('payment_method', 'cash')->where('type', 'withdrawal')->sum('amount');
        $cashSupplierPayments = CashMovement::where('cash_shift_id', $shiftId)->where('payment_method', 'cash')->where('type', 'supplier_payment')->sum('amount');
        $cashRefunds = CustomerTransaction::where('cash_shift_id', $shiftId)->where('type', 'refund')->where('payment_method', 'cash')->sum('amount');

        // El efectivo esperado
        $expectedBalance = $shift->opening_balance + $cashSales + $cashDeposits - $cashExpenses - $cashWithdrawals - $cashSupplierPayments - $cashRefunds;

        // Total Ventas Neto (Cálculo total de ventas para la UI)
        // NOTA: No sumamos $totalSurcharge porque el monto de los pagos (cashSales, checkSales, etc) YA INCLUYE el recargo que pagó el cliente.
        $totalSales = $cashSales + $cardSales + $transferSales + $checkSales + $ccSales;

        return [
            'expected_balance' => $expectedBalance,
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'transfer_sales' => $transferSales,
            'total_surcharge' => $totalSurcharge,
            'check_sales' => $checkSales,
            'check_count' => $checkCount,
            'check_details' => $checkDetails,
            'cc_sales' => $ccSales,
            'cc_sales_count' => $ccSalesCount,
            'total_expenses' => $cashExpenses,
            'total_withdrawals' => $cashWithdrawals,
            'total_deposits' => $cashDeposits,
            'total_supplier_payments' => $cashSupplierPayments,
            'total_refunds' => $cashRefunds,
            'total_sales' => $totalSales,
        ];
    }

    public function closeShift(int $shiftId, float $actualBalance, ?int $closerUserId = null, ?int $differenceAuthorizedByAdminId = null): CashShift
    {
        return DB::transaction(function () use ($shiftId, $actualBalance, $closerUserId, $differenceAuthorizedByAdminId) {
            $shift = CashShift::where('id', $shiftId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new Exception('El turno no existe o ya está cerrado.', 404);
            }

            $totals = $this->calculateLiveTotals($shift);
            $difference = $actualBalance - $totals['expected_balance'];

            $shift->update([
                'closed_at' => now(),
                'expected_balance' => $totals['expected_balance'],
                'actual_balance' => $actualBalance,
                'difference' => $difference,
                'difference_authorized_by_admin_id' => $differenceAuthorizedByAdminId,
                'cash_sales' => $totals['cash_sales'],
                'card_sales' => $totals['card_sales'],
                'transfer_sales' => $totals['transfer_sales'],
                'total_surcharge' => $totals['total_surcharge'],
                'check_sales' => $totals['check_sales'],
                'check_count' => $totals['check_count'],
                'check_details' => json_encode($totals['check_details']),
                'cc_sales' => $totals['cc_sales'],
                'cc_sales_count' => $totals['cc_sales_count'],
                'total_expenses' => $totals['total_expenses'],
                'total_withdrawals' => $totals['total_withdrawals'],
                'total_deposits' => $totals['total_deposits'],
                'total_supplier_payments' => $totals['total_supplier_payments'],
                'total_refunds' => $totals['total_refunds'],
                'status' => 'closed',
                'closed_by_user_id' => $closerUserId,
            ]);

            return $shift;
        });
    }
}
