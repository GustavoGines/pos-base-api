<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\BatchStockService;
use App\Services\ReportCacheService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestión granular de lotes por producto (consulta FEFO, altas manuales y bajas por merma).
 */
class ProductBatchController extends Controller
{
    /**
     * Relaciones estándar de producto para sincronización integral con el catálogo en frontend.
     */
    protected function productRelations(): array
    {
        $relations = [
            'category.rubro',
            'brand',
            'supplier',
            'children',
            'priceTiers',
        ];

        if (BatchStockService::hasProductionRecipeSchema()) {
            $ingCols = 'id,name,internal_code,cost_price,stock,unit_type,is_sold_by_weight';
            if (BatchStockService::hasLotTrackingSchema()) {
                $ingCols .= ',track_batches';
            }
            $relations[] = "recipeItems.ingredient:{$ingCols}";
        }

        return $relations;
    }

    /**
     * Lista los lotes de un producto ordenados con los lotes activos / déficit primero (FEFO).
     * GET /api/catalog/products/{product}/batches
     */
    public function index(Request $request, Product $product)
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'track_batches' => false,
                'lot_deduction_policy' => 'fefo',
                'allow_sell_expired' => false,
                'stock' => (float) $product->stock,
                'expiration_date' => $product->expiration_date?->format('Y-m-d'),
                'batches' => [],
            ]);
        }

        $includeClosed = $request->boolean('include_closed', true);

        $query = ProductBatch::with('supplier:id,name')
            ->where('product_id', $product->id);

        if (! $includeClosed) {
            $query->whereIn('status', [ProductBatch::STATUS_ACTIVE, ProductBatch::STATUS_DEFICIT]);
        }

        $batches = $query
            ->orderByRaw("CASE WHEN status = 'deficit' THEN 0 WHEN status = 'active' THEN 1 ELSE 2 END ASC")
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get()
            ->map(fn (ProductBatch $b) => $this->formatBatch($b));

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'track_batches' => (bool) $product->track_batches,
            'lot_deduction_policy' => $product->lot_deduction_policy ?? 'fefo',
            'allow_sell_expired' => (bool) $product->allow_sell_expired,
            'stock' => (float) $product->stock,
            'expiration_date' => $product->expiration_date?->format('Y-m-d'),
            'batches' => $batches,
        ]);
    }

    /**
     * Registra manualmente un lote entrante para un producto (activa control por lotes si aún no lo tenía).
     * POST /api/catalog/products/{product}/batches
     */
    public function store(Request $request, Product $product)
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'message' => 'El módulo de lotes requiere ejecutar las migraciones pendientes (php artisan migrate).',
            ], 503);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'batch_code' => 'nullable|string|max:64',
            'manufactured_at' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $created = DB::transaction(function () use ($request, $product, $validated) {
                $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

                if ($lockedProduct->is_combo || $lockedProduct->is_service) {
                    throw new \InvalidArgumentException('Los combos y servicios no administran lotes físicos directos.');
                }

                $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                $adminId = $request->attributes->get('authorized_by_admin_id')
                    ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

                if (! $lockedProduct->track_batches) {
                    $lockedProduct->track_batches = true;
                    $lockedProduct->save();
                }

                $batchService = app(BatchStockService::class);
                $batchService->ensureInitialBatchOnEnable($lockedProduct, $actorUser?->id);

                return $batchService->receiveStockBatch($lockedProduct, (float) $validated['quantity'], [
                    'source_type' => ProductBatch::SOURCE_ADJUSTMENT,
                    'batch_code' => $validated['batch_code'] ?? null,
                    'manufactured_at' => $validated['manufactured_at'] ?? null,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'unit_cost' => $validated['unit_cost'] ?? (float) $lockedProduct->cost_price,
                    'notes' => $validated['notes'] ?? null,
                    'user_id' => $actorUser?->id,
                    'authorized_by_admin_id' => $adminId,
                    'movement_type' => 'in',
                    'movement_notes' => $validated['notes'] ?? 'Ingreso manual de lote',
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->refresh();
        $affectedCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos([$product->id]);
        SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge([$product->id], $affectedCombos))));
        ReportCacheService::flush();

        return response()->json([
            'message' => 'Lote registrado correctamente.',
            'batch' => $this->formatBatch($created),
            'product' => $product->load($this->productRelations()),
        ], 201);
    }

    /**
     * Actualiza metadatos de un lote existente (código, vencimiento o notas) y sincroniza el producto.
     * PATCH /api/batches/{batch}
     */
    public function update(Request $request, ProductBatch $batch)
    {
        $validated = $request->validate([
            'batch_code' => 'sometimes|string|max:64',
            'manufactured_at' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $updatedBatch = DB::transaction(function () use ($batch, $validated) {
            // Orden canónico: Product primero -> ProductBatch después
            $lockedProduct = Product::where('id', $batch->product_id)->lockForUpdate()->firstOrFail();
            $lockedBatch = ProductBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

            $expChanged = array_key_exists('expiration_date', $validated)
                && ($validated['expiration_date'] !== $lockedBatch->expiration_date?->format('Y-m-d'));

            $lockedBatch->fill($validated);
            if ($expChanged) {
                $lockedBatch->alert_snoozed_until = null;
                $lockedBatch->alert_last_stage = null;
            }
            $lockedBatch->save();

            app(BatchStockService::class)->syncProductMaterializedFields($lockedProduct);

            return $lockedBatch->fresh('supplier:id,name');
        });

        return response()->json([
            'message' => 'Lote actualizado correctamente.',
            'batch' => $this->formatBatch($updatedBatch),
            'product' => $batch->product()->with($this->productRelations())->first(),
        ]);
    }

    /**
     * Registra la baja por merma / vencimiento de un lote específico.
     * POST /api/batches/{batch}/write-off
     */
    public function writeOff(Request $request, ProductBatch $batch)
    {
        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.001',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $result = DB::transaction(function () use ($request, $batch, $validated) {
                // Orden canónico anti-deadlock: Product primero -> ProductBatch después
                $lockedProduct = Product::where('id', $batch->product_id)->lockForUpdate()->firstOrFail();
                $beforeRemaining = (float) ProductBatch::where('id', $batch->id)->lockForUpdate()->value('remaining_quantity');

                $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                $adminId = $request->attributes->get('authorized_by_admin_id')
                    ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

                $writtenOffBatch = app(BatchStockService::class)->writeOffBatch(
                    $batch,
                    isset($validated['quantity']) ? (float) $validated['quantity'] : null,
                    [
                        'user_id' => $actorUser?->id,
                        'authorized_by_admin_id' => $adminId,
                        'notes' => $validated['notes'] ?? "Merma / baja por vencimiento ({$batch->batch_code})",
                    ]
                );

                $writtenOffQty = max(0.0, round($beforeRemaining - (float) $writtenOffBatch->remaining_quantity, 3));
                $unitCost = $writtenOffBatch->unit_cost !== null
                    ? (float) $writtenOffBatch->unit_cost
                    : (float) $lockedProduct->cost_price;

                return [
                    'batch' => $writtenOffBatch,
                    'written_off_quantity' => $writtenOffQty,
                    'cost_lost' => round($writtenOffQty * $unitCost, 2),
                ];
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product = Product::find($batch->product_id);
        if ($product) {
            $affectedCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos([$product->id]);
            SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge([$product->id], $affectedCombos))));
        }
        ReportCacheService::flush();

        return response()->json([
            'message' => 'Baja por merma registrada correctamente.',
            'batch' => $this->formatBatch($result['batch']),
            'product' => $product?->load($this->productRelations()),
            'written_off_quantity' => $result['written_off_quantity'],
            'cost_lost' => $result['cost_lost'],
        ]);
    }

    /**
     * Lista los lotes activos vencidos (o que vencen hoy) con stock disponible para barrido de cierre.
     * GET /api/batches/expired
     */
    public function expiredBatches(Request $request)
    {
        $includeToday = $request->boolean('include_today', true);
        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'include_today' => $includeToday,
                'expired_count' => 0,
                'expiring_today_count' => 0,
                'total_units' => 0.0,
                'total_estimated_cost' => 0.0,
                'batches' => [],
            ]);
        }

        $today = now()->startOfDay();
        $cutoffDate = ($includeToday ? $today : $today->copy()->subDay())->format('Y-m-d');

        $batches = ProductBatch::with([
            'product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,cost_price,selling_price,vencimiento_dias,track_batches',
            'supplier:id,name',
        ])
            ->where('status', ProductBatch::STATUS_ACTIVE)
            ->where('remaining_quantity', '>', 0)
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<=', $cutoffDate)
            ->whereHas('product', function ($q) {
                $q->where('active', true)
                    ->where('is_combo', false)
                    ->where('is_service', false)
                    ->whereNull('deleted_at');
            })
            ->orderBy('expiration_date', 'asc')
            ->orderBy('id', 'asc')
            ->limit(250)
            ->get();

        $expiredCount = 0;
        $todayCount = 0;
        $totalUnits = 0.0;
        $totalCost = 0.0;

        $mapped = $batches->map(function (ProductBatch $b) use ($today, &$expiredCount, &$todayCount, &$totalUnits, &$totalCost) {
            $expDay = $b->expiration_date?->copy()->startOfDay();
            $daysDiff = $expDay ? (int) $today->diffInDays($expDay, false) : 0;
            $expiresToday = $daysDiff === 0;
            if ($expiresToday) {
                $todayCount++;
            } else {
                $expiredCount++;
            }

            $qty = round((float) $b->remaining_quantity, 3);
            $unitCost = $b->unit_cost !== null
                ? round((float) $b->unit_cost, 2)
                : round((float) ($b->product?->cost_price ?? 0.0), 2);
            $estLoss = round($qty * $unitCost, 2);
            $totalUnits = round($totalUnits + $qty, 3);
            $totalCost = round($totalCost + $estLoss, 2);

            return array_merge($this->formatBatch($b), [
                'product_name' => $b->product?->name ?? 'Producto #'.$b->product_id,
                'internal_code' => $b->product?->internal_code,
                'unit_type' => $b->product?->unit_type ?? 'un',
                'is_sold_by_weight' => (bool) ($b->product?->is_sold_by_weight ?? false),
                'days_overdue' => max(0, -$daysDiff),
                'expires_today' => $expiresToday,
                'estimated_cost_lost' => $estLoss,
            ]);
        })->values();

        return response()->json([
            'include_today' => $includeToday,
            'expired_count' => $expiredCount,
            'expiring_today_count' => $todayCount,
            'total_units' => $totalUnits,
            'total_estimated_cost' => $totalCost,
            'batches' => $mapped,
        ]);
    }

    /**
     * Divide una porción de un lote activo (ej. Freezer -> Vitrina) creando un sub-lote con nueva fecha de vencimiento.
     * POST /api/batches/{batch}/split
     */
    public function split(Request $request, ProductBatch $batch)
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'message' => 'El módulo de lotes requiere ejecutar las migraciones pendientes (php artisan migrate).',
            ], 503);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'expiration_date' => 'nullable|date',
            'batch_code' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:500',
        ]);

        $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $adminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

        try {
            $result = DB::transaction(function () use ($batch, $validated, $actorUser, $adminId) {
                return app(BatchStockService::class)->splitBatch($batch, (float) $validated['quantity'], [
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'batch_code' => $validated['batch_code'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'user_id' => $actorUser?->id,
                    'authorized_by_admin_id' => $adminId,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ReportCacheService::flush();

        /** @var Product $product */
        $product = $result['product'];

        return response()->json([
            'message' => 'Sub-lote de vitrina creado correctamente.',
            'source_batch' => $this->formatBatch($result['source_batch']),
            'batch' => $this->formatBatch($result['new_batch']),
            'product' => $product->load($this->productRelations()),
        ], 201);
    }

    /**
     * Registra la merma masiva de lotes vencidos en 1 clic al cierre de jornada.
     * POST /api/batches/write-off-expired
     */
    public function writeOffExpired(Request $request)
    {
        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'message' => 'El módulo de lotes requiere ejecutar las migraciones pendientes (php artisan migrate).',
            ], 503);
        }

        $validated = $request->validate([
            'include_today' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
            'items' => 'nullable|array|max:250',
            'items.*.batch_id' => 'required_with:items|integer|exists:product_batches,id',
            'items.*.quantity' => 'nullable|numeric|min:0.001',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $adminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

        try {
            $result = DB::transaction(function () use ($validated, $actorUser, $adminId) {
                return app(BatchStockService::class)->writeOffExpiredBatches(
                    $validated['items'] ?? [],
                    [
                        'include_today' => (bool) ($validated['include_today'] ?? true),
                        'notes' => $validated['notes'] ?? null,
                        'user_id' => $actorUser?->id,
                        'authorized_by_admin_id' => $adminId,
                    ]
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($result['affected_product_ids'])) {
            $affected = $result['affected_product_ids'];
            $parentCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos($affected);
            SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge($affected, $parentCombos))));
            ReportCacheService::flush();
        }

        return response()->json([
            'message' => $result['written_off_count'] > 0
                ? "Se registraron {$result['written_off_count']} mermas de lotes vencidos."
                : 'No había lotes vencidos con stock disponible para dar de baja.',
            'written_off_count' => $result['written_off_count'],
            'total_units_lost' => $result['total_units_lost'],
            'total_cost_lost' => $result['total_cost_lost'],
            'batches' => collect($result['batches'])->map(fn (ProductBatch $b) => $this->formatBatch($b))->values(),
        ]);
    }

    /**
     * Reporte valorizado de mermas por vencimiento / deterioro (`type = 'waste'`).
     * GET /api/reports/waste
     */
    public function wasteReport(Request $request)
    {
        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [Carbon::parse($endDate)->startOfDay(), Carbon::parse($startDate)->endOfDay()];
        }

        if (! BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_events' => 0,
                'total_units_lost' => 0.0,
                'total_cost_lost' => 0.0,
                'movements' => [],
            ]);
        }

        $movements = StockMovement::with([
            'product:id,name,internal_code,unit_type,is_sold_by_weight,cost_price',
            'batch:id,batch_code,expiration_date,source_type',
            'user:id,name',
        ])
            ->where('type', 'waste')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get()
            ->map(function (StockMovement $mv) {
                $qty = abs((float) $mv->quantity);
                $unitCost = $mv->unit_cost !== null
                    ? (float) $mv->unit_cost
                    : (float) ($mv->product?->cost_price ?? 0);

                return [
                    'id' => $mv->id,
                    'created_at' => $mv->created_at?->toIso8601String(),
                    'product_id' => $mv->product_id,
                    'product_name' => $mv->product?->name ?? 'Producto eliminado',
                    'internal_code' => $mv->product?->internal_code,
                    'unit_type' => $mv->product?->unit_type ?? 'un',
                    'batch_id' => $mv->batch_id,
                    'batch_code' => $mv->batch?->batch_code,
                    'expiration_date' => $mv->batch?->expiration_date?->format('Y-m-d'),
                    'quantity' => round($qty, 3),
                    'unit_cost' => round($unitCost, 2),
                    'total_cost_lost' => round($qty * $unitCost, 2),
                    'user_name' => $mv->user?->name ?? 'Sistema',
                    'notes' => $mv->notes,
                ];
            });

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_events' => $movements->count(),
            'total_units_lost' => round((float) $movements->sum('quantity'), 3),
            'total_cost_lost' => round((float) $movements->sum('total_cost_lost'), 2),
            'movements' => $movements->values(),
        ]);
    }

    private function formatBatch(ProductBatch $b): array
    {
        $today = now()->startOfDay();
        $daysUntil = $b->expiration_date
            ? (int) $today->diffInDays($b->expiration_date->copy()->startOfDay(), false)
            : null;

        return [
            'id' => $b->id,
            'product_id' => $b->product_id,
            'batch_code' => $b->batch_code,
            'source_type' => $b->source_type,
            'supplier_invoice_id' => $b->supplier_invoice_id,
            'supplier_id' => $b->supplier_id,
            'supplier_name' => $b->supplier?->name,
            'production_order_id' => $b->production_order_id,
            'manufactured_at' => $b->manufactured_at?->format('Y-m-d'),
            'expiration_date' => $b->expiration_date?->format('Y-m-d'),
            'days_until_expiry' => $daysUntil,
            'is_expired' => $b->isExpired(),
            'initial_quantity' => (float) $b->initial_quantity,
            'remaining_quantity' => (float) $b->remaining_quantity,
            'reserved_quantity' => (float) $b->reserved_quantity,
            'available_to_sell' => $b->available_to_sell,
            'unit_cost' => $b->unit_cost !== null ? (float) $b->unit_cost : null,
            'status' => $b->status,
            'notes' => $b->notes,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
