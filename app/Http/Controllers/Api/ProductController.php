<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductPriceTier;
use App\Models\Rubro;
use App\Services\BarcodeService;
use App\Services\BatchStockService;
use App\Services\InventoryAlertService;
use App\Services\ProductionOrderService;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Relaciones estándar de producto para respuestas de catálogo,
     * protegiendo consultas si las migraciones de recetas/lotes aún no se ejecutaron.
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

    public function index(Request $request)
    {
        $query = Product::with($this->productRelations());

        if ($search = $request->query('search')) {
            $query->matchingSearch($search);
        }

        if ($request->filled('category_id')) {
            $query->where('products.category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('products.brand_id', (int) $request->query('brand_id'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('products.supplier_id', (int) $request->query('supplier_id'));
        }

        if ($request->filled('active')) {
            $query->where('products.active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_sold_by_weight')) {
            $query->where('products.is_sold_by_weight', filter_var($request->query('is_sold_by_weight'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('needs_catalog_review')) {
            $query->where('products.needs_catalog_review', filter_var($request->query('needs_catalog_review'), FILTER_VALIDATE_BOOLEAN));
        }

        // OF-01: Carga puntual por IDs (ej. productos en oferta activa que quedaron fuera del bloque
        // inicial de 500 del Acceso Rápido POS). Acepta "ids=1,2,3" o "ids[]=1&ids[]=2". Parámetro
        // opcional y aditivo: clientes v1.8.5 no lo envían y su comportamiento no cambia.
        if ($request->has('ids')) {
            $rawIds = $request->query('ids');
            $rawIds = is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds);
            $ids = array_values(array_unique(array_filter(
                array_map(fn ($id) => is_scalar($id) ? (int) $id : 0, $rawIds),
                fn (int $id) => $id > 0
            )));

            // Cap de seguridad (mismo tope que per_page). Lista vacía o inválida => sin resultados.
            $query->whereIn('products.id', array_slice($ids, 0, 500) ?: [0]);
        }

        $allowedSorts = [
            'id', 'name', 'selling_price', 'cost_price', 'stock',
            'barcode', 'internal_code', 'supplier_sku', 'storage_location', 'technical_spec', 'unit_type',
            'category_id', 'brand_id', 'supplier_id',
            'is_sold_by_weight', 'active', 'sales_count', 'vencimiento_dias',
            'expiration_date', 'needs_catalog_review',
        ];
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';
        $hasExtendedCols = Product::hasExtendedCatalogColumns();

        if ($sortBy && in_array($sortBy, $allowedSorts)) {
            $orderNullsLast = static function ($builder, string $expression, string $direction): void {
                $builder->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END ASC")
                    ->orderByRaw("{$expression} {$direction}");
            };

            // Ordenar por nombre de la relación (no por ID numérico) dejando los valores vacíos al final
            if ($sortBy === 'brand_id') {
                $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                    ->select('products.*');
                $orderNullsLast($query, 'brands.name', $sortDir);
                $query->orderBy('products.name', 'asc');
            } elseif ($sortBy === 'category_id') {
                $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->select('products.*');
                $orderNullsLast($query, 'categories.name', $sortDir);
                $query->orderBy('products.name', 'asc');
            } elseif ($sortBy === 'supplier_id') {
                $query->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
                    ->select('products.*');
                $orderNullsLast($query, 'suppliers.name', $sortDir);
                $query->orderBy('products.name', 'asc');
            } elseif ($sortBy === 'expiration_date') {
                // Primero fechas concretas (NULLS LAST), luego vida útil en días (NULLS LAST), al final sin vencimiento
                $orderNullsLast($query, 'products.expiration_date', $sortDir);
                $orderNullsLast($query, 'products.vencimiento_dias', $sortDir);
                $query->orderBy('products.name', 'asc');
            } elseif ($sortBy === 'vencimiento_dias') {
                $orderNullsLast($query, 'products.vencimiento_dias', $sortDir);
                $query->orderBy('products.name', 'asc');
            } elseif ($sortBy === 'barcode') {
                $query->orderByRaw("CASE WHEN products.barcode IS NULL OR products.barcode = '' THEN 1 ELSE 0 END ASC")
                    ->orderBy('products.barcode', $sortDir)
                    ->orderBy('products.name', 'asc');
            } elseif (in_array($sortBy, ['supplier_sku', 'storage_location', 'technical_spec'], true)) {
                if ($hasExtendedCols) {
                    $query->orderByRaw("CASE WHEN products.{$sortBy} IS NULL OR products.{$sortBy} = '' THEN 1 ELSE 0 END ASC")
                        ->orderBy("products.{$sortBy}", $sortDir)
                        ->orderBy('products.name', 'asc');
                } else {
                    $query->orderBy('products.name', 'asc');
                }
            } elseif ($sortBy === 'needs_catalog_review') {
                $query->where('products.needs_catalog_review', true)
                    ->orderBy('products.id', $sortDir);
            } else {
                $column = $sortBy === 'name' ? 'products.name' : 'products.' . $sortBy;
                $query->orderBy($column, $sortDir);
            }
        } else {
            // Default sorting when no specific sort is requested:
            // 1° Más vendidos (sales_count DESC), 2° Productos por peso como desempate, 3° Nombre ASC
            $query->orderBy('products.sales_count', 'desc')
                ->orderBy('products.is_sold_by_weight', 'desc')
                ->orderBy('products.name', 'asc');
        }

        $perPage = min((int) $request->query('per_page', 100), 500); // Cap de seguridad: máx 500

        return response()->json($query->paginate($perPage));
    }

    public function store(StoreProductRequest $request, BarcodeService $barcodeService)
    {
        $validated = $request->validated();

        // Flujo de Código Interno (PLU)
        if (empty($request->internal_code)) {
            $validated['internal_code'] = $barcodeService->generateUniqueInternalCode();
        } else {
            $validated['internal_code'] = str_pad($request->internal_code, 5, '0', STR_PAD_LEFT);
        }

        // Si el código de barras está vacío:
        // - Si es producto de balanza (granel), lo dejamos NULO para no interferir con códigos EAN13 de balanza.
        // - Si es por unidad, le generamos un código de barras EAN-13 Interno basado en su PLU.
        if (empty($validated['barcode'])) {
            $validated['barcode'] = empty($request->is_sold_by_weight)
                ? $barcodeService->generateInternalEan13($validated['internal_code'])
                : null;
        }

        if (! isset($validated['iva_rate']) || $validated['iva_rate'] === null) {
            $validated['iva_rate'] = 21.00;
        }

        if (array_key_exists('calc_config', $validated)) {
            if (! Schema::hasColumn('products', 'calc_config')) {
                unset($validated['calc_config']);
            } else {
                $validated['calc_config'] = $this->sanitizeCalcConfig($validated['calc_config']);
            }
        }

        $product = DB::transaction(function () use ($request, $validated) {
            $hasLotSchema = BatchStockService::hasLotTrackingSchema();
            $hasRecipeSchema = BatchStockService::hasProductionRecipeSchema();
            $initialStock = (float) ($validated['stock'] ?? 0);
            $createBatchForOpening = $hasLotSchema
                && ! empty($validated['track_batches'])
                && empty($validated['is_combo'])
                && empty($validated['is_service'])
                && $initialStock > 0;

            if ($createBatchForOpening) {
                $validated['stock'] = 0.0;
            }

            $product = Product::create($validated);

            // Auditoría: Registrar stock inicial si es mayor a 0
            if ($createBatchForOpening) {
                $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                app(BatchStockService::class)->receiveStockBatch($product, $initialStock, [
                    'source_type' => ProductBatch::SOURCE_OPENING,
                    'batch_code' => $request->input('initial_batch_code'),
                    'expiration_date' => $product->expiration_date,
                    'unit_cost' => (float) $product->cost_price,
                    'user_id' => $actorUser?->id,
                    'authorized_by_admin_id' => $request->attributes->get('authorized_by_admin_id')
                        ?? ($actorUser?->isAdmin() ? $actorUser->id : null),
                    'movement_type' => 'in',
                    'movement_notes' => 'Stock inicial (Creación de producto)',
                ]);
            } elseif ($product->stock > 0) {
                $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
                $product->stockMovements()->create([
                    'user_id' => $actorUser?->id,
                    'authorized_by_admin_id' => $request->attributes->get('authorized_by_admin_id')
                        ?? ($actorUser?->isAdmin() ? $actorUser->id : null),
                    'type' => 'in',
                    'quantity' => $product->stock,
                    'unit_cost' => $product->cost_price,
                    'notes' => 'Stock inicial (Creación de producto)',
                ]);
            }

            if (! empty($validated['is_combo']) && $request->has('combo_ingredients')) {
                $syncData = [];
                foreach ($request->combo_ingredients as $ingredient) {
                    $syncData[$ingredient['id']] = ['quantity' => $ingredient['quantity']];
                }
                $product->children()->sync($syncData);
                $product->load('children');
                $product->update([
                    'cost_price' => $product->calculateComboCostPrice(),
                    'stock' => $product->calculateComboAvailableStock(),
                ]);
            }

            // Sincronizar ingredientes de elaboración propia si vienen en el payload
            if ($hasRecipeSchema && $request->has('recipe_ingredients')) {
                $recipeList = $request->input('recipe_ingredients') ?? [];
                ProductionOrderService::syncProductRecipe($product, $recipeList);
                $product->update([
                    'has_production_recipe' => ! empty($recipeList),
                ]);
            }

            // Sincronizar tramos de precio si vienen en el payload
            if ($request->has('price_tiers')) {
                $this->syncPriceTiers($product, $request->price_tiers ?? []);
            }

            return $product;
        });

        return response()->json($product->load($this->productRelations()), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load($this->productRelations()));
    }

    public function update(UpdateProductRequest $request, Product $product, BarcodeService $barcodeService)
    {
        $validated = $request->validated();

        $stockChanged = false;

        DB::transaction(function () use ($request, &$product, &$validated, $barcodeService, &$stockChanged) {
            $product = Product::lockForUpdate()->findOrFail($product->id);

            // Flujo de Código Interno (PLU) en actualización
            if (empty($request->internal_code)) {
                $validated['internal_code'] = $product->internal_code;
            } else {
                $validated['internal_code'] = str_pad((string) $request->internal_code, 5, '0', STR_PAD_LEFT);
            }

            if (array_key_exists('barcode', $validated) && empty($validated['barcode'])) {
                $isWeight = $request->has('is_sold_by_weight') ? $request->is_sold_by_weight : $product->is_sold_by_weight;
                $validated['barcode'] = empty($isWeight) ? $barcodeService->generateInternalEan13($validated['internal_code']) : null;
            }

            // Auditoría de Stock: Guardar valor previo bajo lock antes de actualizar
            $hasLotSchema = BatchStockService::hasLotTrackingSchema();
            $hasRecipeSchema = BatchStockService::hasProductionRecipeSchema();
            $oldStock = (float) $product->stock;
            $oldCost = (float) $product->cost_price;
            $willBeCombo = array_key_exists('is_combo', $validated) ? (bool) $validated['is_combo'] : (bool) $product->is_combo;
            $wasTrackingBatches = $hasLotSchema && (bool) $product->track_batches;
            $willTrackBatches = $hasLotSchema && (array_key_exists('track_batches', $validated) ? (bool) $validated['track_batches'] : $wasTrackingBatches);

            // Si usamos add_stock, si es combo (stock virtual) o si controla stock por lotes, no pisamos stock absoluto directo
            if (! empty($validated['add_stock']) || $willBeCombo || ($wasTrackingBatches && $willTrackBatches)) {
                unset($validated['stock']);
            }
            if ($wasTrackingBatches && $willTrackBatches && ! $willBeCombo) {
                // La fecha más próxima a vencer del producto se materializa desde sus lotes activos
                unset($validated['expiration_date']);
            }

            if (array_key_exists('calc_config', $validated)) {
                if (! Schema::hasColumn('products', 'calc_config')) {
                    unset($validated['calc_config']);
                } else {
                    $validated['calc_config'] = $this->sanitizeCalcConfig($validated['calc_config']);
                }
            }

            $product->update($validated);

            // Si cambió el costo de un ingrediente simple, recalcular el costo de los combos padres que lo utilicen
            if (! $willBeCombo && abs((float) $product->cost_price - $oldCost) > 0.001) {
                $parentCombos = $product->combos()->with('children')->get();
                foreach ($parentCombos as $parentCombo) {
                    $parentCombo->update([
                        'cost_price' => $parentCombo->calculateComboCostPrice(),
                    ]);
                }
            }

            $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
            $batchService = app(BatchStockService::class);

            if ($hasLotSchema && ! $wasTrackingBatches && $willTrackBatches && ! $willBeCombo && ! $product->is_service) {
                $batchService->ensureInitialBatchOnEnable($product, $actorUser?->id);
                $product->refresh();
            }

            // Modificación aditiva (Sin race condition, excluido para combos)
            if (! $willBeCombo && ! empty($request->add_stock)) {
                if ($hasLotSchema && $willTrackBatches && ! $product->is_service) {
                    $batchService->receiveStockBatch($product, (float) $request->add_stock, [
                        'source_type' => ProductBatch::SOURCE_ADJUSTMENT,
                        'batch_code' => $request->input('batch_code'),
                        'expiration_date' => $request->input('expiration_date'),
                        'unit_cost' => (float) $product->cost_price,
                        'user_id' => $actorUser?->id,
                        'movement_type' => 'in',
                        'movement_notes' => 'Ingreso rápido de mercadería (Mobile)',
                    ]);
                    $product->refresh();
                    $stockChanged = true;
                } else {
                    $product->increment('stock', $request->add_stock);
                    $product->refresh();
                    $stockChanged = true;

                    $product->stockMovements()->create([
                        'user_id' => $actorUser?->id,
                        'type' => 'in',
                        'quantity' => $request->add_stock,
                        'unit_cost' => $product->cost_price,
                        'notes' => 'Ingreso rápido de mercadería (Mobile)',
                    ]);
                }
            }
            // Modificación absoluta protegida bajo lockForUpdate (solo productos sin control de lotes)
            elseif (! $willBeCombo && ! $willTrackBatches && array_key_exists('stock', $validated) && (float) $validated['stock'] !== $oldStock) {
                $newStock = (float) $validated['stock'];
                $diff = $newStock - $oldStock;
                $stockChanged = true;

                $product->stockMovements()->create([
                    'user_id' => $actorUser?->id,
                    'type' => $diff > 0 ? 'in' : 'out',
                    'quantity' => abs($diff),
                    'unit_cost' => $product->cost_price,
                    'notes' => "Modificación manual de ficha de producto (de $oldStock a $newStock)",
                ]);
            }

            if (array_key_exists('is_combo', $validated)) {
                if (! empty($validated['is_combo']) && $request->has('combo_ingredients')) {
                    $syncData = [];
                    foreach ($request->combo_ingredients as $ingredient) {
                        $syncData[$ingredient['id']] = ['quantity' => $ingredient['quantity']];
                    }
                    $product->children()->sync($syncData);
                    $product->load('children');
                    $product->update([
                        'cost_price' => $product->calculateComboCostPrice(),
                        'stock' => $product->calculateComboAvailableStock(),
                    ]);
                } elseif (empty($validated['is_combo'])) {
                    $product->children()->detach();
                }
            }

            // Sincronizar ingredientes de elaboración propia si vienen en el payload
            if ($hasRecipeSchema) {
                if ($request->has('recipe_ingredients')) {
                    $recipeList = $request->input('recipe_ingredients') ?? [];
                    ProductionOrderService::syncProductRecipe($product, $recipeList);
                    $product->update([
                        'has_production_recipe' => ! empty($recipeList),
                    ]);
                } elseif (array_key_exists('has_production_recipe', $validated) && empty($validated['has_production_recipe'])) {
                    $product->recipeItems()->delete();
                }
            }

            // Sincronizar tramos de precio si vienen en el payload
            if ($request->has('price_tiers')) {
                $this->syncPriceTiers($product, $request->price_tiers ?? []);
            }
        });

        if ($stockChanged) {
            SyncProductStockToPlatformsJob::dispatchForProducts([$product->id]);
        }

        ReportCacheService::flush();

        return response()->json($product->load($this->productRelations()));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * Retorna productos con stock por debajo del mínimo configurado.
     */
    public function criticalAlerts()
    {
        $simpleProducts = Product::where('active', true)
            ->where('is_combo', false)
            ->where('is_service', false)
            ->whereNotNull('min_stock')
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('stock', 'asc')
            ->limit(100)
            ->get();

        // BK-MED-01: Evaluar combos según su disponibilidad virtual real (calculateComboAvailableStock)
        $comboAlerts = Product::where('active', true)
            ->where('is_combo', true)
            ->whereNotNull('min_stock')
            ->with('children')
            ->get()
            ->filter(fn (Product $combo) => $combo->calculateComboAvailableStock() <= (float) $combo->min_stock);

        $merged = $simpleProducts->concat($comboAlerts)
            ->sortBy(fn (Product $p) => $p->is_combo ? $p->calculateComboAvailableStock() : (float) $p->stock)
            ->take(100)
            ->values();

        return response()->json($merged);
    }

    /**
     * Retorna el stock actualizado ÚNICAMENTE de los productos indicados.
     * Endpoint ultra-liviano para la actualización post-venta del POS.
     * GET /api/catalog/products/stock?ids=1,5,9
     */
    public function stockBulk(Request $request)
    {
        $ids = array_filter(
            array_map('intval', explode(',', $request->query('ids', ''))),
            fn ($id) => $id > 0
        );

        if (empty($ids)) {
            return response()->json([]);
        }

        // Cap de seguridad: máximo 200 IDs por llamada
        $ids = array_slice($ids, 0, 200);

        // Expandir IDs para incluir ingredientes hijos de combos vendidos
        // y combos padres afectados cuando se vende un ingrediente suelto.
        $childIds = DB::table('product_combos')
            ->whereIn('parent_product_id', $ids)
            ->pluck('child_product_id')
            ->toArray();

        $allAffectedChildIds = array_unique(array_merge($ids, $childIds));

        $parentComboIds = DB::table('product_combos')
            ->whereIn('child_product_id', $allAffectedChildIds)
            ->pluck('parent_product_id')
            ->toArray();

        $expandedIds = array_values(array_unique(array_merge($allAffectedChildIds, $parentComboIds)));

        $selectCols = ['id', 'stock', 'is_combo', 'expiration_date'];
        if (BatchStockService::hasLotTrackingSchema()) {
            $selectCols[] = 'track_batches';
        }

        $stocks = Product::with('children')
            ->whereIn('id', $expandedIds)
            ->select($selectCols)
            ->get()
            ->map(function (Product $p) {
                if ($p->is_combo) {
                    $avail = $p->calculateComboAvailableStock();

                    return [
                        'id' => $p->id,
                        'stock' => number_format($avail, 3, '.', ''),
                        'expiration_date' => null,
                    ];
                }

                return [
                    'id' => $p->id,
                    'stock' => $p->stock,
                    'expiration_date' => $p->expiration_date?->format('Y-m-d'),
                ];
            });

        return response()->json($stocks);
    }

    /**
     * Motor de Predicción de Quiebre de Stock (Velocidad de Venta).
     */
    public function inventoryAlerts(Request $request, InventoryAlertService $alertService)
    {
        $threshold = (int) $request->query('threshold', 3);
        $periodDays = 15;

        $data = $alertService->getPredictiveAlerts($threshold, $periodDays);

        return response()->json($data);
    }

    /**
     * Pospone (Snooze) o limpia una alerta de vencimiento de producto en cascada.
     * POST /api/inventory/alerts/{product}/snooze
     */
    public function snoozeExpirationAlert(Request $request, Product $product)
    {
        $hasLotSchema = BatchStockService::hasLotTrackingSchema();
        $validated = $request->validate([
            'action' => 'nullable|string|in:snooze_days,snooze_next_stage,snooze_stage,clear_expiration,update_expiration',
            'days' => 'nullable|integer|min:1|max:365',
            'expiration_date' => 'nullable|date',
            'batch_id' => $hasLotSchema ? 'nullable|integer|exists:product_batches,id' : 'nullable|integer',
        ]);

        $action = $validated['action'] ?? 'snooze_days';

        if (in_array($action, ['clear_expiration', 'update_expiration'], true)) {
            $permissionMiddleware = app(\App\Http\Middleware\EnsurePermissionOrPin::class);
            $authCheck = $permissionMiddleware->handle(
                $request,
                fn () => response('', 200),
                \App\Constants\Permissions::MANAGE_CATALOG,
                \App\Constants\Permissions::ADJUST_STOCK
            );
            if ($authCheck->getStatusCode() !== 200) {
                return $authCheck;
            }
        }

        return DB::transaction(function () use ($validated, $product, $action, $hasLotSchema) {
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            $targetBatch = null;
            if ($hasLotSchema && ! empty($validated['batch_id'])) {
                $targetBatch = ProductBatch::where('id', (int) $validated['batch_id'])
                    ->where('product_id', $lockedProduct->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } elseif ($hasLotSchema && $lockedProduct->track_batches) {
                $targetBatch = ProductBatch::where('product_id', $lockedProduct->id)
                    ->where('status', ProductBatch::STATUS_ACTIVE)
                    ->where('remaining_quantity', '>', 0)
                    ->whereNotNull('expiration_date')
                    ->orderBy('expiration_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();
            }

            $batchService = app(BatchStockService::class);

            if ($action === 'clear_expiration') {
                if ($targetBatch) {
                    $targetBatch->update([
                        'expiration_date' => null,
                        'alert_snoozed_until' => null,
                        'alert_last_stage' => null,
                    ]);
                    $batchService->syncProductMaterializedFields($lockedProduct);
                } else {
                    $lockedProduct->update([
                        'expiration_date' => null,
                        'expiry_alert_snoozed_until' => null,
                        'expiry_alert_last_stage' => null,
                    ]);
                }

                return response()->json([
                    'message' => 'Fecha de vencimiento quitada del producto.',
                    'product' => $lockedProduct->fresh($this->productRelations()),
                    'batch' => $targetBatch?->fresh(),
                ]);
            }

            if ($action === 'update_expiration' && ! empty($validated['expiration_date'])) {
                if ($targetBatch) {
                    $targetBatch->update([
                        'expiration_date' => $validated['expiration_date'],
                        'alert_snoozed_until' => null,
                        'alert_last_stage' => null,
                    ]);
                    $batchService->syncProductMaterializedFields($lockedProduct);
                } else {
                    $lockedProduct->update([
                        'expiration_date' => $validated['expiration_date'],
                        'expiry_alert_snoozed_until' => null,
                        'expiry_alert_last_stage' => null,
                    ]);
                }

                return response()->json([
                    'message' => 'Nueva fecha de vencimiento registrada.',
                    'product' => $lockedProduct->fresh($this->productRelations()),
                    'batch' => $targetBatch?->fresh(),
                ]);
            }

            $refExp = $targetBatch ? $targetBatch->expiration_date : $lockedProduct->expiration_date;
            $shelfLife = $lockedProduct->vencimiento_dias !== null ? (int) $lockedProduct->vencimiento_dias : null;
            if ($targetBatch && $shelfLife === null && $targetBatch->manufactured_at && $refExp) {
                $span = (int) $targetBatch->manufactured_at->copy()->startOfDay()->diffInDays($refExp->copy()->startOfDay(), false);
                if ($span > 0) {
                    $shelfLife = $span;
                }
            }

            $classified = InventoryAlertService::classifyExpirationStage(
                $refExp,
                (int) ($lockedProduct->expiry_alert_days ?? 15),
                $shelfLife
            );
            $currentStage = $classified['stage'] ?? 15;

            $snoozeDays = (int) ($validated['days'] ?? 1);
            if (in_array($action, ['snooze_next_stage', 'snooze_stage'], true)) {
                $daysUntilExp = $refExp
                    ? max(0, (int) now()->startOfDay()->diffInDays($refExp->copy()->startOfDay(), false))
                    : 30;
                $snoozeDays = min(3650, max(30, $daysUntilExp + 1));
            }
            $snoozedUntil = now()->addDays($snoozeDays);

            if ($targetBatch) {
                $targetBatch->update([
                    'alert_snoozed_until' => $snoozedUntil,
                    'alert_last_stage' => $currentStage,
                ]);
                $batchService->syncProductMaterializedFields($lockedProduct);
            } else {
                $lockedProduct->update([
                    'expiry_alert_snoozed_until' => $snoozedUntil,
                    'expiry_alert_last_stage' => $currentStage,
                ]);
            }

            return response()->json([
                'message' => 'Alerta de vencimiento pospuesta correctamente.',
                'snoozed_until' => $snoozedUntil->toIso8601String(),
                'last_stage' => $currentStage,
                'batch_id' => $targetBatch?->id,
            ]);
        });
    }

    /**
     * Sincroniza los tramos de precio mayorista provistos en el payload.
     * Elimina los antiguos y recrea los nuevos asegurando consistencia.
     */
    private function syncPriceTiers(Product $product, array $tiersData): void
    {
        // Borramos los actuales (estrategia replace-all, más robusta para Tiers)
        $product->priceTiers()->delete();

        // Si no mandan nada, ya quedó limpio
        if (empty($tiersData)) {
            return;
        }

        $recordsToInsert = [];
        foreach ($tiersData as $tier) {
            $recordsToInsert[] = [
                'product_id' => $product->id,
                'min_quantity' => $tier['min_quantity'],
                'unit_price' => $tier['unit_price'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insertamos en bulk
        ProductPriceTier::insert($recordsToInsert);
    }

    /**
     * Sube y asocia una imagen fotográfica al catálogo para el producto dado.
     */
    public function uploadImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        // Si ya tenía imagen, la eliminamos para no dejar basura
        if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
            Storage::disk('public')->delete($product->image_path);
        }

        $file = $request->file('image');
        $hash = substr(md5(uniqid()), 0, 8);
        $filename = "{$product->id}_{$hash}.".$file->getClientOriginalExtension();
        $path = $file->storeAs('products', $filename, 'public');

        $product->update(['image_path' => $path]);

        return response()->json([
            'message' => 'Imagen de producto cargada correctamente.',
            'image_url' => asset('storage/'.$path),
        ]);
    }

    /**
     * Elimina la imagen fotográfica asociada al producto.
     */
    public function deleteImage(Product $product)
    {
        if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->update(['image_path' => null]);

        return response()->json(null, 204);
    }

    /**
     * Actualiza exclusivamente los parámetros técnicos de la Calculadora Ferretera (`calc_config`)
     * para este producto sin tocar precios, costos ni stock.
     * Accesible desde caja (con sesión activa) sin requerir PIN de supervisor.
     * PATCH /api/catalog/products/{product}/calc-config
     */
    public function updateCalcConfig(Request $request, Product $product)
    {
        $product->loadMissing('category.rubro');
        $productRubro = $product->category?->rubro;
        $productRubroVertical = strtolower(trim((string) ($productRubro->vertical_type ?? '')));
        $productRubroName = strtolower(trim((string) ($productRubro->name ?? '')));
        $productInHardwareRubro = $productRubroVertical === 'hardware_store'
            || (bool) preg_match('/ferret|mader|corral/i', $productRubroName);
        $hasExistingCalcConfig = is_array($product->calc_config) && count($product->calc_config) > 0;
        $hasAnyHardwareRubro = Rubro::hasExtendedRubroColumns()
            && Rubro::where('vertical_type', 'hardware_store')->exists();

        if (
            ! BusinessSetting::hasVerticalCapability('hardware_store')
            && ! $hasExistingCalcConfig
            && ! $productInHardwareRubro
            && ! $hasAnyHardwareRubro
        ) {
            return response()->json([
                'message' => 'La calculadora ferretera solo está habilitada para comercios o rubros de Ferretería / Maderera.',
                'error_code' => 'HARDWARE_STORE_ONLY',
            ], 403);
        }

        if (! Schema::hasColumn('products', 'calc_config')) {
            return response()->json([
                'message' => 'Migración pendiente: ejecute las actualizaciones de base de datos.',
            ], 503);
        }

        $validated = $request->validate([
            'calc_config' => 'present|nullable|array',
            'calc_config.type' => 'nullable|string|in:wood,roof,paint',
            'calc_config.inch_preset' => 'nullable|string|max:32',
            'calc_config.nominal_width_m' => 'nullable|numeric|min:0.001|max:50',
            'calc_config.useful_width_m' => 'nullable|numeric|min:0.001|max:50',
            'calc_config.length_m' => 'nullable|numeric|min:0.05|max:50',
            'calc_config.pricing_mode' => 'nullable|string|in:per_board,per_pack,per_linear_meter,per_m2,per_sheet,per_unit',
            'calc_config.m2_billing_basis' => 'nullable|string|in:nominal,useful',
            'calc_config.boards_per_pack' => 'nullable|integer|min:1|max:500',
            'calc_config.round_to_full_pack' => 'nullable|boolean',
            'calc_config.longitudinal_overlap_m' => 'nullable|numeric|min:0|max:5',
            'calc_config.yield_m2_per_liter' => 'nullable|numeric|min:0.05|max:500',
            'calc_config.yield_mode' => 'nullable|string|in:per_coat,final_total',
            'calc_config.container_liters' => 'nullable|numeric|min:0.05|max:1000',
            'calc_config.default_coats' => 'nullable|integer|min:1|max:20',
            'calc_config.default_waste_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $rawConfig = $validated['calc_config'] ?? null;
        if (
            is_array($rawConfig)
            && isset($rawConfig['nominal_width_m'], $rawConfig['useful_width_m'])
            && is_numeric($rawConfig['nominal_width_m'])
            && is_numeric($rawConfig['useful_width_m'])
            && (float) $rawConfig['useful_width_m'] > (float) $rawConfig['nominal_width_m'] + 1e-6
        ) {
            return response()->json([
                'message' => 'El ancho útil no puede superar al ancho nominal.',
                'errors' => [
                    'calc_config.useful_width_m' => ['El ancho útil no puede superar al ancho nominal.'],
                ],
            ], 422);
        }

        $sanitizedConfig = $this->sanitizeCalcConfig($rawConfig);

        $product->update([
            'calc_config' => $sanitizedConfig,
        ]);

        return response()->json([
            'message' => 'Configuración de calculadora actualizada correctamente.',
            'calc_config' => $product->calc_config,
            'product' => $product->load($this->productRelations()),
        ]);
    }

    private function sanitizeCalcConfig(mixed $rawConfig): ?array
    {
        if (! is_array($rawConfig)) {
            return null;
        }

        $allowedKeys = [
            'type',
            'inch_preset',
            'nominal_width_m',
            'useful_width_m',
            'length_m',
            'pricing_mode',
            'm2_billing_basis',
            'boards_per_pack',
            'round_to_full_pack',
            'longitudinal_overlap_m',
            'yield_m2_per_liter',
            'yield_mode',
            'container_liters',
            'default_coats',
            'default_waste_pct',
        ];

        $sanitized = array_intersect_key($rawConfig, array_flip($allowedKeys));

        return empty($sanitized) ? null : $sanitized;
    }
}
