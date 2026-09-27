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
        $products = DB::table('products')
            ->leftJoinSub($salesVelocity, 'vel', 'vel.product_id', '=', 'products.id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.active', true)
            ->where('products.is_combo', false)
            ->selectRaw(sprintf("
                products.id                     as product_id,
                products.name                   as product_name,
                products.internal_code          as internal_code,
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
        ];
    }
}
