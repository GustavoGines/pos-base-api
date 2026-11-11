<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryAlertService
{
    /**
     * Motor de Predicción de Quiebre de Stock (Velocidad de Venta).
     *
     * Algoritmo:
     *   avg_daily_units  = SUM(qty vendidas en últimos 15 días) / 15
     *   days_of_coverage = stock_actual / avg_daily_units
     *
     * Solo devuelve productos activos donde:
     *   - Tienen stock > 0 (si el stock ya es 0, es un quiebre consumado, no predictivo)
     *   - avg_daily_units > 0  (el producto vendió algo en los últimos 15 días)
     *   - days_of_coverage < $threshold (umbral configurable, default 7 días)
     */
    public function getPredictiveAlerts(int $threshold = 3, int $periodDays = 15): array
    {
        $since = now()->subDays($periodDays)->startOfDay();

        // Subquery: unidades vendidas por producto en los últimos N días
        $salesVelocity = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', $since)
            ->selectRaw('product_id, SUM(quantity) as total_sold')
            ->groupBy('product_id');

        // Query unificada: Alertas Reactivas (Quiebre/Stock Min) + Predictivas (Velocidad de venta)
        if (DB::getDriverName() === 'sqlite') {
            $rawRows = DB::table('products')
                ->leftJoinSub($salesVelocity, 'vel', 'vel.product_id', '=', 'products.id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->where('products.active', true)
                ->where('products.is_combo', false)
                ->where('products.is_service', false)
                ->whereNull('products.deleted_at')
                ->select([
                    'products.id as product_id',
                    'products.name as product_name',
                    'products.internal_code as internal_code',
                    'products.barcode as barcode',
                    'products.is_sold_by_weight as is_sold_by_weight',
                    DB::raw("COALESCE(categories.name, 'Sin Categoría') as category"),
                    'products.stock as current_stock',
                    'products.min_stock as min_stock',
                    'products.created_at as created_at',
                    DB::raw('COALESCE(vel.total_sold, 0) as total_sold'),
                ])
                ->get();

            $now = now();
            $products = $rawRows->map(function ($r) use ($now, $periodDays) {
                $createdAt = $r->created_at ? \Carbon\Carbon::parse($r->created_at) : $now->copy()->subDays($periodDays);
                $ageDays = max(0, (int) $createdAt->diffInDays($now));
                $effectiveDays = min(max($ageDays, 1), $periodDays);
                $totalSold = (float) $r->total_sold;
                $r->avg_daily_units = round($totalSold / $effectiveDays, 2);

                if ($totalSold > 0 && $ageDays >= 2) {
                    $usableStock = max(0.0, (float) $r->current_stock - (float) ($r->min_stock ?? 0));
                    $r->days_of_coverage = round($usableStock / ($totalSold / $effectiveDays), 1);
                } else {
                    $r->days_of_coverage = 9999;
                }

                return $r;
            })->filter(function ($r) use ($threshold) {
                return $r->days_of_coverage <= $threshold
                    || (float) $r->current_stock <= 0
                    || ($r->min_stock !== null && (float) $r->current_stock <= (float) $r->min_stock);
            })->sortBy([
                fn ($a, $b) => (float) $a->current_stock <=> (float) $b->current_stock,
                fn ($a, $b) => (float) $a->days_of_coverage <=> (float) $b->days_of_coverage,
            ])->values();
        } else {
            $products = DB::table('products')
                ->leftJoinSub($salesVelocity, 'vel', 'vel.product_id', '=', 'products.id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->where('products.active', true)
                ->where('products.is_combo', false)
                ->where('products.is_service', false)
                ->whereNull('products.deleted_at')
                ->selectRaw(sprintf("
                    products.id                     as product_id,
                    products.name                   as product_name,
                    products.internal_code          as internal_code,
                    products.barcode                as barcode,
                    products.is_sold_by_weight      as is_sold_by_weight,
                    COALESCE(categories.name, 'Sin Categoría') as category,
                    products.stock                  as current_stock,
                    products.min_stock              as min_stock,
                    
                    -- COLD START: Cálculo dinámico de días de vida para no subestimar promedios (Tope max: 15, Tope min: 1)
                    ROUND(COALESCE(vel.total_sold, 0) / LEAST(GREATEST(DATEDIFF(NOW(), COALESCE(products.created_at, NOW() - INTERVAL %1\$d DAY)), 1), %1\$d), 2) as avg_daily_units,
                    
                    -- ALERTA PREDICTIVA: Excluye recién nacidos (< 2 días / 48 hrs) asignando 9999 (silencio estadístico)
                    IF(COALESCE(vel.total_sold, 0) > 0 AND DATEDIFF(NOW(), COALESCE(products.created_at, NOW() - INTERVAL %1\$d DAY)) >= 2, 
                       ROUND( IF(products.stock > COALESCE(products.min_stock, 0), products.stock - COALESCE(products.min_stock, 0), 0) / (vel.total_sold / LEAST(GREATEST(DATEDIFF(NOW(), COALESCE(products.created_at, NOW() - INTERVAL %1\$d DAY)), 1), %1\$d)), 1), 
                       9999) as days_of_coverage
                ", $periodDays))
                ->havingRaw('
                    days_of_coverage <= ? 
                    OR current_stock <= 0 
                    OR (min_stock IS NOT NULL AND current_stock <= min_stock)
                ', [$threshold])
                ->orderByRaw('current_stock ASC, days_of_coverage ASC')
                ->get();
        }

        // Clasificación semafórica unificada en PHP (Zero-Processing para Flutter)
        $alerts = $products->map(function ($row) {
            $row->alert_level = match (true) {
                $row->current_stock <= 0 => 'critical',
                ! is_null($row->min_stock) && $row->current_stock <= $row->min_stock => 'critical',
                $row->days_of_coverage <= 3 => 'critical',
                default => 'info',
            };

            $row->alert_type = match (true) {
                $row->current_stock <= 0 => 'out_of_stock',
                ! is_null($row->min_stock) && $row->current_stock <= $row->min_stock => 'low_stock',
                default => 'predictive',
            };

            return $row;
        });

        return [
            'period_analyzed_days' => $periodDays,
            'threshold_days' => $threshold,
            'generated_at' => now()->toIso8601String(),
            'alerts' => $alerts,
            'expiration_alerts' => $this->getExpirationAlerts(),
        ];
    }

    /**
     * Motor de Alertas de Vencimiento en Cascada con soporte de Snooze inteligente.
     *
     * Etapas de cascada (stages):
     *   - stage 0  ('expired'):     days_until_expiry <= 0
     *   - stage 3  ('critical_3d'): days_until_expiry <= 3
     *   - stage 7  ('warning_7d'):  days_until_expiry <= 7
     *   - stage 15 ('pre_alert'):   days_until_expiry <= COALESCE(expiry_alert_days, 15)
     *
     * Regla de Snooze en cascada:
     *   Si `expiry_alert_snoozed_until > NOW()` y la etapa actual (`stage`) es mayor o igual
     *   a `expiry_alert_last_stage`, la alerta permanece silenciada.
     *   Si el producto entra en un umbral más urgente (`stage < expiry_alert_last_stage`),
     *   la alerta se reactiva automáticamente para no pasar por alto un vencimiento inminente.
     */
    public function getExpirationAlerts(): array
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $candidates = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.active', true)
            ->where('products.is_combo', false)
            ->where('products.is_service', false)
            ->where('products.stock', '>', 0)
            ->whereNull('products.deleted_at')
            ->whereNotNull('products.expiration_date')
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'products.internal_code',
                'products.barcode',
                'products.is_sold_by_weight',
                'products.stock as current_stock',
                'products.expiration_date',
                'products.expiry_alert_days',
                'products.expiry_alert_snoozed_until',
                'products.expiry_alert_last_stage',
                DB::raw("COALESCE(categories.name, 'Sin Categoría') as category"),
            ])
            ->orderBy('products.expiration_date', 'asc')
            ->limit(150)
            ->get();

        $results = [];

        foreach ($candidates as $row) {
            $expDate = \Carbon\Carbon::parse($row->expiration_date)->startOfDay();
            $daysUntil = (int) $today->diffInDays($expDate, false);
            $preAlertThreshold = max(1, (int) ($row->expiry_alert_days ?? 15));

            if ($daysUntil > $preAlertThreshold) {
                continue;
            }

            if ($daysUntil <= 0) {
                $stage = 0;
                $stageCode = 'expired';
                $alertLevel = 'critical';
            } elseif ($daysUntil <= 3) {
                $stage = 3;
                $stageCode = 'critical_3d';
                $alertLevel = 'critical';
            } elseif ($daysUntil <= 7) {
                $stage = 7;
                $stageCode = 'warning_7d';
                $alertLevel = 'warning';
            } else {
                $stage = 15;
                $stageCode = 'pre_alert';
                $alertLevel = 'info';
            }

            // Verificar Snooze en cascada
            if (! empty($row->expiry_alert_snoozed_until)) {
                $snoozedUntil = \Carbon\Carbon::parse($row->expiry_alert_snoozed_until);
                $lastStage = $row->expiry_alert_last_stage !== null ? (int) $row->expiry_alert_last_stage : $stage;

                // Sigue silenciada sólo si la fecha de snooze sigue vigente Y no avanzó a una etapa más urgente
                if ($snoozedUntil->isFuture() && $stage >= $lastStage) {
                    continue;
                }
            }

            $results[] = [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'name' => $row->product_name,
                'internal_code' => $row->internal_code,
                'barcode' => $row->barcode,
                'is_sold_by_weight' => (bool) $row->is_sold_by_weight,
                'category' => $row->category,
                'current_stock' => (float) $row->current_stock,
                'expiration_date' => $expDate->format('Y-m-d'),
                'days_until_expiry' => $daysUntil,
                'days_until_expiration' => $daysUntil,
                'stage' => $stage,
                'stage_code' => $stageCode,
                'alert_level' => $alertLevel,
                'alert_type' => 'expiration',
            ];
        }

        return $results;
    }
}
