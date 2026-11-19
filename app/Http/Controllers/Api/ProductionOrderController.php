<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Services\ProductionOrderService;
use App\Services\ReportCacheService;
use App\Services\StockService;
use Illuminate\Http\Request;

/**
 * Endpoints para órdenes de producción propia y recetas de elaboración (pastelería / cocina).
 */
class ProductionOrderController extends Controller
{
    public function __construct(
        protected ProductionOrderService $productionOrderService
    ) {}

    /**
     * Lista el historial de órdenes de producción.
     * GET /api/production-orders
     */
    public function index(Request $request)
    {
        if (! \App\Services\BatchStockService::hasProductionRecipeSchema()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = ProductionOrder::with([
            'product:id,name,internal_code,unit_type,is_sold_by_weight,vencimiento_dias',
            'producedBatch:id,batch_code,expiration_date,remaining_quantity,status',
            'consumptions.ingredient:id,name,internal_code,unit_type',
            'consumptions.batch:id,batch_code,expiration_date',
            'user:id,name',
        ])->latest('id');

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->query('product_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(min((int) $request->query('per_page', 30), 100)));
    }

    /**
     * Detalle completo de una orden de producción.
     * GET /api/production-orders/{productionOrder}
     */
    public function show(ProductionOrder $productionOrder)
    {
        return response()->json($productionOrder->load([
            'product:id,name,internal_code,unit_type,is_sold_by_weight,vencimiento_dias',
            'producedBatch:id,batch_code,expiration_date,initial_quantity,remaining_quantity,unit_cost,status',
            'consumptions.ingredient:id,name,internal_code,unit_type',
            'consumptions.batch:id,batch_code,expiration_date',
            'user:id,name',
            'authorizedByAdmin:id,name',
        ]));
    }

    /**
     * Registra un horneado / producción propia descontando ingredientes por FEFO y generando el lote terminado.
     * POST /api/production-orders
     */
    public function store(Request $request)
    {
        if (! \App\Services\BatchStockService::hasProductionRecipeSchema() || ! \App\Services\BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'message' => 'El módulo de órdenes de producción requiere ejecutar las migraciones pendientes (php artisan migrate).',
            ], 503);
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity_produced' => 'required|numeric|min:0.001',
            'batch_code' => 'nullable|string|max:64',
            'manufactured_at' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'allow_deficit' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
            'ingredients.*.ingredient_product_id' => 'nullable|integer|exists:products,id',
            'ingredients.*.id' => 'nullable|integer|exists:products,id',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.001',
            'ingredients.*.batch_id' => 'nullable|integer|exists:product_batches,id',
        ]);

        $product = Product::findOrFail((int) $validated['product_id']);
        $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $adminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

        $validated['user_id'] = $actorUser?->id;
        $validated['authorized_by_admin_id'] = $adminId;

        try {
            $order = $this->productionOrderService->createOrder($product, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $affectedIds = collect([$product->id])
            ->merge($order->consumptions->pluck('ingredient_product_id'))
            ->unique()
            ->values()
            ->all();
        $parentCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos($affectedIds);
        SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge($affectedIds, $parentCombos))));
        ReportCacheService::flush();

        return response()->json([
            'message' => 'Orden de producción registrada correctamente.',
            'order' => $order,
        ], 201);
    }

    /**
     * Registra la "Planilla Rápida Matutina": múltiples órdenes de producción en una única transacción atómica.
     * POST /api/production-orders/batch
     */
    public function storeBatch(Request $request)
    {
        if (! \App\Services\BatchStockService::hasProductionRecipeSchema() || ! \App\Services\BatchStockService::hasLotTrackingSchema()) {
            return response()->json([
                'message' => 'El módulo de órdenes de producción requiere ejecutar las migraciones pendientes (php artisan migrate).',
            ], 503);
        }

        $validated = $request->validate([
            'manufactured_at' => 'nullable|date',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'notes' => 'nullable|string|max:500',
            'allow_deficit' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity_produced' => 'required|numeric|min:0.001',
            'items.*.batch_code' => 'nullable|string|max:64',
            'items.*.manufactured_at' => 'nullable|date',
            'items.*.expiration_date' => 'nullable|date',
            'items.*.vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.allow_deficit' => 'nullable|boolean',
            'items.*.ingredients' => 'nullable|array',
            'items.*.ingredients.*.ingredient_product_id' => 'nullable|integer|exists:products,id',
            'items.*.ingredients.*.id' => 'nullable|integer|exists:products,id',
            'items.*.ingredients.*.quantity' => 'required_with:items.*.ingredients|numeric|min:0.001',
            'items.*.ingredients.*.batch_id' => 'nullable|integer|exists:product_batches,id',
        ]);

        $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $adminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

        $sharedContext = [
            'user_id' => $actorUser?->id,
            'authorized_by_admin_id' => $adminId,
            'manufactured_at' => $validated['manufactured_at'] ?? null,
            'vencimiento_dias' => $validated['vencimiento_dias'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'allow_deficit' => $validated['allow_deficit'] ?? true,
        ];

        try {
            $result = $this->productionOrderService->createBatchOrders($validated['items'], $sharedContext);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $affectedIds = $result['affected_product_ids'];
        $parentCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos($affectedIds);
        SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge($affectedIds, $parentCombos))));
        ReportCacheService::flush();

        $orders = $result['orders'];

        return response()->json([
            'message' => count($orders) === 1
                ? '1 orden de producción registrada correctamente.'
                : count($orders).' órdenes de producción registradas correctamente.',
            'total_orders' => count($orders),
            'orders' => $orders,
            'affected_product_ids' => $affectedIds,
        ], 201);
    }

    /**
     * Anula una orden de producción cuyo lote aún no haya sido vendido/consumido.
     * POST /api/production-orders/{productionOrder}/void
     */
    public function void(Request $request, ProductionOrder $productionOrder)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $actorUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $adminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actorUser?->isAdmin() ? $actorUser->id : null);

        try {
            $voided = $this->productionOrderService->voidOrder(
                $productionOrder,
                $actorUser?->id,
                $adminId,
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $affectedIds = collect([$voided->product_id])
            ->merge($voided->consumptions->pluck('ingredient_product_id'))
            ->unique()
            ->values()
            ->all();
        $parentCombos = app(StockService::class)->recalculateAndGetAffectedParentCombos($affectedIds);
        SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique(array_merge($affectedIds, $parentCombos))));
        ReportCacheService::flush();

        return response()->json([
            'message' => 'Orden de producción anulada correctamente.',
            'order' => $voided,
        ]);
    }
}
