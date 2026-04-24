<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use Illuminate\Support\Facades\DB;
use Exception;

class CashShiftService
{
    /**
     * Verifica si la licencia actual tiene el Addon 'multi_caja'.
     */
    public function hasMultiCajaPermission(): bool
    {
        // Plan PRO/Enterprise incluye TODOS los módulos sin necesidad de addons explícitos.
        $planType = BusinessSetting::where('key', 'license_plan_type')->value('value');
        if (in_array($planType, ['pro', 'enterprise'])) {
            return true;
        }

        // Plan Básico: Verificar si tiene el addon específico 'multi_caja'
        $addonsJson = BusinessSetting::where('key', 'license_allowed_addons')->value('value');
        $addons = [];
        if ($addonsJson) {
            $parsed = json_decode($addonsJson, true);
            if (is_array($parsed)) {
                $addons = $parsed;
            } else if (is_string($parsed)) {
                $addons = json_decode($parsed, true) ?? [];
            }
        }
        return in_array('multi_caja', $addons);
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

            if (!$isPro) {
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
                throw new Exception("Ya existe un turno abierto en esta caja.", 403);
            }

            // Límite Básico Global: Prohibir a toda costa más de 1 turno en todo el local
            if (!$isPro) {
                $globalOpenCount = CashShift::where('status', 'open')->count();
                if ($globalOpenCount > 0) {
                    throw new Exception("Límite de cajas alcanzado. Actualice su plan a PRO para abrir turnos paralelos.", 403);
                }
            }

            return CashShift::create([
                'cash_register_id' => $lockedRegister->id,
                'user_id'          => $userId,
                'opened_at'        => now(),
                'opening_balance'  => $openingBalance,
                'status'           => 'open',
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

        if (!$isPro) {
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
    public function closeShift(int $shiftId, float $actualBalance, ?int $closerUserId = null): CashShift
    {
        return DB::transaction(function () use ($shiftId, $actualBalance, $closerUserId) {
            // Lock para que nuevas ventas no adulteren lo calculado en el microsegundo de cierre
            $shift = CashShift::where('id', $shiftId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (!$shift) {
                throw new Exception("El turno no existe o ya está cerrado.", 404);
            }

            // Sumatoria Financiera: Solo ventas COMPLETADAS
            // Recorremos los pagos cruzados con métodos de pago para saber qué es efectivo
            $cashSales = \App\Models\SalePayment::whereHas('sale', fn($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
                ->whereHas('paymentMethod', fn($q) => $q->where('is_cash', true))
                ->sum('total_amount');

            $cardSales = \App\Models\SalePayment::whereHas('sale', fn($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
                ->whereHas('paymentMethod', fn($q) => $q->where('code', 'like', 'card_%'))
                ->sum('total_amount');

            $transferSales = \App\Models\SalePayment::whereHas('sale', fn($q) => $q->where('cash_shift_id', $shiftId)->where('status', 'completed'))
                ->whereHas('paymentMethod', fn($q) => $q->where('code', 'transfer'))
                ->sum('total_amount');

            $totalSurcharge = \App\Models\Sale::where('cash_shift_id', $shiftId)
                ->where('status', 'completed')
                ->sum('total_surcharge');

            // Cheques recibidos en el turno
            $checkSales = \App\Models\ThirdPartyCheck::where('cash_shift_id', $shiftId)->sum('amount');
            $checkCount = \App\Models\ThirdPartyCheck::where('cash_shift_id', $shiftId)->count();
            $checkDetails = \App\Models\ThirdPartyCheck::where('cash_shift_id', $shiftId)
                ->get(['id', 'bank_name', 'check_number', 'amount', 'payment_date', 'issuer_name'])
                ->toArray();

            // El efectivo físico esperado en la gaveta = Fondo Inicial + SOLO pagos en métodos is_cash
            $expectedBalance = $shift->opening_balance + $cashSales;
            
            // Desfase (Sobrante/Faltante) comparado contra lo físico contado
            $difference = $actualBalance - $expectedBalance;

            $shift->update([
                'closed_at'         => now(),
                'expected_balance'  => $expectedBalance,
                'actual_balance'    => $actualBalance,
                'difference'        => $difference,
                'cash_sales'        => $cashSales,
                'card_sales'        => $cardSales,
                'transfer_sales'    => $transferSales,
                'total_surcharge'   => $totalSurcharge,
                'check_sales'       => $checkSales,
                'check_count'       => $checkCount,
                'check_details'     => json_encode($checkDetails),
                'status'            => 'closed',
                'closed_by_user_id' => $closerUserId,
            ]);

            return $shift;
        });
    }
}
