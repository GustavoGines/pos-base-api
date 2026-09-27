<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Services\BarcodeService;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'supplier', 'children', 'priceTiers']);

        if ($search = $request->query('search')) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('products.name', 'like', $like)
                  ->orWhere('products.barcode', 'like', $like)
                  ->orWhere('products.internal_code', 'like', $like);
            });
        }

        $allowedSorts = [
            'id', 'name', 'selling_price', 'cost_price', 'stock',
            'barcode', 'internal_code', 'category_id', 'brand_id', 'supplier_id', 'is_sold_by_weight', 'active', 'sales_count', 'vencimiento_dias'
        ];
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $allowedSorts)) {
            // Ordenar por nombre de la relación (no por ID numérico) para que sea intuitivo
            if ($sortBy === 'brand_id') {
                $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                      ->orderBy('brands.name', $sortDir)
                      ->select('products.*');
            } elseif ($sortBy === 'category_id') {
                $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                      ->orderBy('categories.name', $sortDir)
                      ->select('products.*');
            } elseif ($sortBy === 'supplier_id') {
                $query->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
                      ->orderBy('suppliers.name', $sortDir)
                      ->select('products.*');
            } else {
                $column = $sortBy === 'name' ? 'products.name' : $sortBy;
                $query->orderBy($column, $sortDir);
            }
        } else {
            // Default sorting when no specific sort is requested
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

        $product = Product::create($validated);

        // Auditoría: Registrar stock inicial si es mayor a 0
        if ($product->stock > 0) {
            $product->stockMovements()->create([
                'user_id'  => $request->attributes->get('authenticated_user')?->id,
                'type'     => 'in',
                'quantity' => $product->stock,
                'notes'    => 'Stock inicial (Creación de producto)',
            ]);
        }

        if (!empty($validated['is_combo']) && $request->has('combo_ingredients')) {
            $syncData = [];
            foreach ($request->combo_ingredients as $ingredient) {
                $syncData[$ingredient['id']] = ['quantity' => $ingredient['quantity']];
            }
            $product->children()->sync($syncData);
        }

        // Sincronizar tramos de precio si vienen en el payload
        if ($request->has('price_tiers')) {
            $this->syncPriceTiers($product, $request->price_tiers ?? []);
        }

        return response()->json($product->load(['category', 'brand', 'supplier', 'children', 'priceTiers']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'supplier', 'children', 'priceTiers']));
    }

    public function update(UpdateProductRequest $request, Product $product, BarcodeService $barcodeService)
    {
        $validated = $request->validated();

        // Flujo de Código Interno (PLU) en actualización
        if (empty($request->internal_code)) {
            $validated['internal_code'] = $product->internal_code;
        } else {
            $validated['internal_code'] = str_pad($request->internal_code, 5, '0', STR_PAD_LEFT);
        }

        if (array_key_exists('barcode', $validated) && empty($validated['barcode'])) {
            $isWeight = $request->has('is_sold_by_weight') ? $request->is_sold_by_weight : $product->is_sold_by_weight;
            $validated['barcode'] = empty($isWeight) ? $barcodeService->generateInternalEan13($validated['internal_code']) : null;
        }

        // Auditoría de Stock: Guardar valor previo antes de actualizar
        $oldStock = (float) $product->stock;
        
        // Si usamos add_stock, no pisamos el stock absoluto
        if (!empty($validated['add_stock'])) {
            unset($validated['stock']);
        }
        
        $product->update($validated);

        // Modificación aditiva (Sin race condition)
        if (!empty($request->add_stock)) {
            $product->increment('stock', $request->add_stock);
            
            $product->stockMovements()->create([
                'user_id'  => $request->attributes->get('authenticated_user')?->id,
                'type'     => 'in',
                'quantity' => $request->add_stock,
                'notes'    => "Ingreso rápido de mercadería (Mobile)",
            ]);
        }
        // Modificación absoluta (Puede tener race conditions si no se usa con cuidado)
        else if (array_key_exists('stock', $validated) && (float) $validated['stock'] !== $oldStock) {
            $newStock = (float) $validated['stock'];
            $diff = $newStock - $oldStock;
            
            $product->stockMovements()->create([
                'user_id'  => $request->attributes->get('authenticated_user')?->id,
                'type'     => $diff > 0 ? 'in' : 'out',
                'quantity' => abs($diff),
                'notes'    => "Modificación manual de ficha de producto (de $oldStock a $newStock)",
            ]);
        }

        if (array_key_exists('is_combo', $validated)) {
            if (!empty($validated['is_combo']) && $request->has('combo_ingredients')) {
                $syncData = [];
                foreach ($request->combo_ingredients as $ingredient) {
                    $syncData[$ingredient['id']] = ['quantity' => $ingredient['quantity']];
                }
                $product->children()->sync($syncData);
            } else if (empty($validated['is_combo'])) {
                $product->children()->detach();
            }
        }

        // Sincronizar tramos de precio si vienen en el payload
        if ($request->has('price_tiers')) {
            $this->syncPriceTiers($product, $request->price_tiers ?? []);
        }

        return response()->json($product->load(['category', 'brand', 'supplier', 'children', 'priceTiers']));
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
        $products = Product::where('active', true)
            ->whereNotNull('min_stock')
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('stock', 'asc')
            ->limit(100)
            ->get();

        return response()->json($products);
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
            fn($id) => $id > 0
        );

        if (empty($ids)) {
            return response()->json([]);
        }

        // Cap de seguridad: máximo 200 IDs por llamada
        $ids = array_slice($ids, 0, 200);

        $stocks = Product::whereIn('id', $ids)
            ->select('id', 'stock')
            ->get();

        return response()->json($stocks);
    }

    /**
     * Motor de Predicción de Quiebre de Stock (Velocidad de Venta).
     */
    public function inventoryAlerts(\Illuminate\Http\Request $request, \App\Services\InventoryAlertService $alertService)
    {
        $threshold  = (int) $request->query('threshold', 3);
        $periodDays = 15;

        $data = $alertService->getPredictiveAlerts($threshold, $periodDays);

        return response()->json($data);
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
                'product_id'   => $product->id,
                'min_quantity' => $tier['min_quantity'],
                'unit_price'   => $tier['unit_price'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Insertamos en bulk
        ProductPriceTier::insert($recordsToInsert);
    }
}
