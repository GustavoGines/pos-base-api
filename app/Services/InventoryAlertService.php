<?php

namespace App\Services;

use App\Models\BusinessSetting;
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

        $batchEnabled = $this->isBatchExpirationEnabled();

        return [
            'period_analyzed_days' => $periodDays,
            'threshold_days' => $threshold,
            'generated_at' => now()->toIso8601String(),
            'alerts' => $alerts,
            'expiration_alerts' => $batchEnabled ? $this->getExpirationAlerts() : [],
            'deficit_alerts' => $batchEnabled ? $this->getDeficitBatchAlerts() : [],
        ];
    }

    /**
     * Evalúa si la licencia habilita el módulo de Vencimientos y Control por Lotes (Opción 1A).
     */
    protected function isBatchExpirationEnabled(): bool
    {
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');

        $features = [];
        if (! empty($featuresJson)) {
            $decoded = json_decode((string) $featuresJson, true);
            if (is_array($decoded)) {
                $features = $decoded;
            }
        }

        if (! empty($features['production']) && ($features['production'] === true || $features['production'] === 1)) {
            return true;
        }

        if (array_key_exists('batch_expiration', $features)) {
            return $features['batch_expiration'] === true || $features['batch_expiration'] === 1;
        }

        $businessType = (string) (DB::table('business_settings')
            ->where('key', 'license_business_type')
            ->value('value') ?? 'retail');

        return BusinessSetting::hasNativeBatchExpiration(
            $businessType,
            BusinessSetting::getSecondaryBusinessTypes()
        );
    }

    /**
     * Evalúa etapa y criticidad de vencimiento, adaptando los umbrales de cascada cuando
     * la vida útil total del producto o lote es corta (<= 5 días en gastronomía/pastelería).
     *
     * @return array{stage: int, stage_code: string, alert_level: string, label: string, days_until: int}|null
     */
    public static function classifyExpirationStage(
        ?\Carbon\Carbon $expirationDate,
        int $configuredPreAlertDays = 15,
        ?int $shelfLifeDays = null,
        ?\Carbon\Carbon $referenceToday = null
    ): ?array {
        if (! $expirationDate) {
            return null;
        }

        $today = ($referenceToday ?? now())->copy()->startOfDay();
        $daysUntil = (int) $today->diffInDays($expirationDate->copy()->startOfDay(), false);

        $preAlertThreshold = max(1, $configuredPreAlertDays);
        $isShortShelfLife = $shelfLifeDays !== null && $shelfLifeDays > 0 && $shelfLifeDays <= 5;

        if ($isShortShelfLife) {
            $criticalDays = min(3, max(1, (int) floor($shelfLifeDays * 0.35)));
            $warningDays = min(7, max($criticalDays + 1, (int) floor($shelfLifeDays * 0.65)));
            $preAlertThreshold = min($preAlertThreshold, max($warningDays + 1, $shelfLifeDays));
        } else {
            $criticalDays = 3;
            $warningDays = 7;
        }

        if ($daysUntil > $preAlertThreshold) {
            return null;
        }

        if ($daysUntil <= 0) {
            return ['stage' => 0, 'stage_code' => 'expired', 'alert_level' => 'critical', 'label' => 'Vencido', 'days_until' => $daysUntil];
        }
        if ($daysUntil <= $criticalDays) {
            return ['stage' => 3, 'stage_code' => 'critical_3d', 'alert_level' => 'critical', 'label' => 'Crítico', 'days_until' => $daysUntil];
        }
        if ($daysUntil <= $warningDays) {
            return ['stage' => 7, 'stage_code' => 'warning_7d', 'alert_level' => 'warning', 'label' => 'Próximo', 'days_until' => $daysUntil];
        }

        return ['stage' => 15, 'stage_code' => 'pre_alert', 'alert_level' => 'info', 'label' => 'Pre-alerta', 'days_until' => $daysUntil];
    }

    /**
     * Lotes automáticos de contingencia (deficit_auto) pendientes de conciliar con producción o compra.
     */
    public function getDeficitBatchAlerts(): array
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return [];
        }

        return DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('product_batches.status', 'deficit')
            ->where('product_batches.remaining_quantity', '<', 0)
            ->whereNull('product_batches.deleted_at')
            ->where('products.active', true)
            ->whereNull('products.deleted_at')
            ->orderBy('product_batches.remaining_quantity', 'asc')
            ->limit(100)
            ->select([
                'product_batches.id as batch_id',
                'product_batches.batch_code',
                'product_batches.remaining_quantity',
                'product_batches.updated_at',
                'products.id as product_id',
                'products.name as product_name',
                'products.internal_code',
                'products.is_sold_by_weight',
                DB::raw("COALESCE(categories.name, 'Sin Categoría') as category"),
            ])
            ->get()
            ->map(fn ($row) => [
                'batch_id' => (int) $row->batch_id,
                'batch_code' => $row->batch_code,
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'internal_code' => $row->internal_code,
                'is_sold_by_weight' => (bool) $row->is_sold_by_weight,
                'category' => $row->category,
                'deficit_quantity' => abs((float) $row->remaining_quantity),
                'current_stock' => (float) $row->remaining_quantity,
                'alert_type' => 'deficit_batch',
                'alert_level' => 'critical',
                'message' => 'Venta sin lote respaldatorio: pendiente de conciliar con producción o compra.',
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Motor de Alertas de Vencimiento en Cascada con soporte de Snooze inteligente
     * y seguimiento granular por lote para productos con `track_batches = true`.
     */
    public function getExpirationAlerts(): array
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $results = [];
        $hasLotSchema = BatchStockService::hasLotTrackingSchema();

        // 1. Productos clásicos sin control granular por lotes (100% compatibilidad v1.8.5/v2.1.0)
        $legacyQuery = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.active', true)
            ->where('products.is_combo', false)
            ->where('products.is_service', false);

        if ($hasLotSchema) {
            $legacyQuery->where('products.track_batches', false);
        }

        $legacyCandidates = $legacyQuery
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
                'products.vencimiento_dias',
                'products.expiry_alert_days',
                'products.expiry_alert_snoozed_until',
                'products.expiry_alert_last_stage',
                DB::raw("COALESCE(categories.name, 'Sin Categoría') as category"),
            ])
            ->orderBy('products.expiration_date', 'asc')
            ->limit(150)
            ->get();

        foreach ($legacyCandidates as $row) {
            $expDate = \Carbon\Carbon::parse($row->expiration_date)->startOfDay();
            $classified = self::classifyExpirationStage(
                $expDate,
                (int) ($row->expiry_alert_days ?? 15),
                $row->vencimiento_dias !== null ? (int) $row->vencimiento_dias : null,
                $today
            );

            if ($classified === null) {
                continue;
            }

            if ($this->isAlertSnoozed($row->expiry_alert_snoozed_until, $row->expiry_alert_last_stage, $classified['stage'])) {
                continue;
            }

            $results[] = [
                'product_id' => (int) $row->product_id,
                'batch_id' => null,
                'batch_code' => null,
                'product_name' => $row->product_name,
                'name' => $row->product_name,
                'internal_code' => $row->internal_code,
                'barcode' => $row->barcode,
                'is_sold_by_weight' => (bool) $row->is_sold_by_weight,
                'category' => $row->category,
                'current_stock' => (float) $row->current_stock,
                'expiration_date' => $expDate->format('Y-m-d'),
                'days_until_expiry' => $classified['days_until'],
                'days_until_expiration' => $classified['days_until'],
                'stage' => $classified['stage'],
                'stage_code' => $classified['stage_code'],
                'expiry_stage_label' => $classified['label'],
                'alert_level' => $classified['alert_level'],
                'alert_type' => 'expiration',
            ];
        }

        // 2. Productos con control granular por lotes (1 alerta por cada lote activo próximo a vencer)
        $batchCandidates = ! $hasLotSchema ? collect() : DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.active', true)
            ->where('products.is_combo', false)
            ->where('products.is_service', false)
            ->where('products.track_batches', true)
            ->whereNull('products.deleted_at')
            ->where('product_batches.status', 'active')
            ->where('product_batches.remaining_quantity', '>', 0)
            ->whereNull('product_batches.deleted_at')
            ->whereNotNull('product_batches.expiration_date')
            ->select([
                'product_batches.id as batch_id',
                'product_batches.batch_code',
                'product_batches.source_type',
                'product_batches.manufactured_at',
                'product_batches.expiration_date',
                'product_batches.remaining_quantity',
                'product_batches.reserved_quantity',
                'product_batches.unit_cost',
                'product_batches.alert_snoozed_until',
                'product_batches.alert_last_stage',
                'products.id as product_id',
                'products.name as product_name',
                'products.internal_code',
                'products.barcode',
                'products.is_sold_by_weight',
                'products.stock as total_product_stock',
                'products.vencimiento_dias',
                'products.expiry_alert_days',
                DB::raw("COALESCE(categories.name, 'Sin Categoría') as category"),
            ])
            ->orderBy('product_batches.expiration_date', 'asc')
            ->orderBy('product_batches.id', 'asc')
            ->limit(200)
            ->get();

        foreach ($batchCandidates as $row) {
            $expDate = \Carbon\Carbon::parse($row->expiration_date)->startOfDay();
            $shelfLife = $row->vencimiento_dias !== null ? (int) $row->vencimiento_dias : null;
            if ($shelfLife === null && ! empty($row->manufactured_at)) {
                $mfgDate = \Carbon\Carbon::parse($row->manufactured_at)->startOfDay();
                $span = (int) $mfgDate->diffInDays($expDate, false);
                if ($span > 0) {
                    $shelfLife = $span;
                }
            }

            $classified = self::classifyExpirationStage(
                $expDate,
                (int) ($row->expiry_alert_days ?? 15),
                $shelfLife,
                $today
            );

            if ($classified === null) {
                continue;
            }

            if ($this->isAlertSnoozed($row->alert_snoozed_until, $row->alert_last_stage, $classified['stage'])) {
                continue;
            }

            $remaining = (float) $row->remaining_quantity;
            $reserved = (float) ($row->reserved_quantity ?? 0);

            $results[] = [
                'product_id' => (int) $row->product_id,
                'batch_id' => (int) $row->batch_id,
                'batch_code' => $row->batch_code,
                'source_type' => $row->source_type,
                'product_name' => $row->product_name,
                'name' => $row->product_name,
                'internal_code' => $row->internal_code,
                'barcode' => $row->barcode,
                'is_sold_by_weight' => (bool) $row->is_sold_by_weight,
                'category' => $row->category,
                'current_stock' => $remaining,
                'total_product_stock' => (float) $row->total_product_stock,
                'available_to_sell' => max(0.0, round($remaining - $reserved, 3)),
                'unit_cost' => $row->unit_cost !== null ? (float) $row->unit_cost : null,
                'expiration_date' => $expDate->format('Y-m-d'),
                'days_until_expiry' => $classified['days_until'],
                'days_until_expiration' => $classified['days_until'],
                'stage' => $classified['stage'],
                'stage_code' => $classified['stage_code'],
                'expiry_stage_label' => $classified['label'],
                'alert_level' => $classified['alert_level'],
                'alert_type' => 'expiration',
            ];
        }

        usort($results, function (array $a, array $b): int {
            $dateCmp = strcmp($a['expiration_date'], $b['expiration_date']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return ($a['product_id'] <=> $b['product_id']) ?: (($a['batch_id'] ?? 0) <=> ($b['batch_id'] ?? 0));
        });

        return array_slice($results, 0, 200);
    }

    protected function isAlertSnoozed(mixed $snoozedUntilRaw, mixed $lastStageRaw, int $currentStage): bool
    {
        if (empty($snoozedUntilRaw)) {
            return false;
        }

        $snoozedUntil = \Carbon\Carbon::parse($snoozedUntilRaw);
        $lastStage = $lastStageRaw !== null ? (int) $lastStageRaw : $currentStage;

        return $snoozedUntil->isFuture() && $currentStage >= $lastStage;
    }
}
