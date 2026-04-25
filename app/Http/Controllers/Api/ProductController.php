<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'barcode', 'internal_code', 'category_id', 'brand_id', 'is_sold_by_weight', 'active', 'sales_count', 'vencimiento_dias'
        ];
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $allowedSorts)) {
            // Ordenar por nombre de marca (no por ID numérico) para que sea intuitivo
            if ($sortBy === 'brand_id') {
                $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                      ->orderBy('brands.name', $sortDir)
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'barcode' => [
                'nullable',
                'string',
                Rule::unique('products')->whereNull('deleted_at')
            ],
            'cost_price' => 'numeric|min:0',
            'selling_price' => 'numeric|min:0|gte:cost_price',
            // [hardware_store] Listas de precio estáticas — opcionales para retail
            'price_wholesale' => 'nullable|numeric|min:0',
            'price_card'      => 'nullable|numeric|min:0',
            'stock' => 'numeric',
            'min_stock' => 'nullable|numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'unit_type' => 'sometimes|in:un,kg,lt,g',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'is_combo' => 'boolean',
            'combo_ingredients' => 'nullable|array|required_if:is_combo,true',
            'combo_ingredients.*.id' => 'required_with:combo_ingredients|exists:products,id',
            'combo_ingredients.*.quantity' => 'required_with:combo_ingredients|numeric|min:0.001',
            // Tramos de precio mayorista
            'price_tiers'                => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|numeric|min:1',
            'price_tiers.*.unit_price'   => 'required_with:price_tiers|numeric|min:0',
        ]);

        // Flujo de Código Interno (PLU)
        if (empty($request->internal_code)) {
            $validated['internal_code'] = $this->generateUniqueInternalCode();
        } else {
            $validated['internal_code'] = str_pad($request->internal_code, 5, '0', STR_PAD_LEFT);
        }

        // Si el código de barras está vacío:
        // - Si es producto de balanza (granel), lo dejamos NULO para no interferir con códigos EAN13 de balanza.
        // - Si es por unidad, le generamos un código de barras EAN-13 Interno basado en su PLU.
        if (empty($validated['barcode'])) {
            $validated['barcode'] = empty($request->is_sold_by_weight) 
                ? $this->generateInternalEan13($validated['internal_code']) 
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

    public function adjustStock(Request $request, Product $product)
    {
        $validated = $request->validate([
            'type' => 'required|in:increment,decrement',
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:255',
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        if ($validated['type'] === 'increment') {
            $product->increment('stock', $validated['quantity']);
        } else {
            $product->decrement('stock', $validated['quantity']);
        }

        // Si se envió un nuevo stock mínimo, lo actualizamos también (UX Quick Win)
        if (array_key_exists('min_stock', $validated)) {
            $product->update(['min_stock' => $validated['min_stock']]);
        }

        // Registrar movimiento si existe una tabla de movimientos (opcional según arquitectura)
        if (method_exists($product, 'stockMovements')) {
            $product->stockMovements()->create([
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'notes' => $validated['notes'] ?? 'Ajuste manual desde catálogo',
                'user_id' => $request->attributes->get('authenticated_user')?->id,
            ]);
        }

        return response()->json([
            'message' => 'Stock actualizado con éxito',
            'new_stock' => $product->fresh()->stock,
            'product' => $product->fresh()->load(['category', 'brand', 'supplier']),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'barcode' => [
                'nullable',
                'string',
                Rule::unique('products')->ignore($product->id)->whereNull('deleted_at')
            ],
            'cost_price' => 'numeric|min:0',
            'selling_price' => 'numeric|min:0|gte:cost_price',
            // [hardware_store] Listas de precio estáticas — opcionales para retail
            'price_wholesale' => 'nullable|numeric|min:0',
            'price_card'      => 'nullable|numeric|min:0',
            'stock' => 'numeric',
            'min_stock' => 'nullable|numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'unit_type' => 'sometimes|in:un,kg,lt,g',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'is_combo' => 'boolean',
            'combo_ingredients' => 'nullable|array|required_if:is_combo,true',
            'combo_ingredients.*.id' => 'required_with:combo_ingredients|exists:products,id',
            'combo_ingredients.*.quantity' => 'required_with:combo_ingredients|numeric|min:0.001',
            // Tramos de precio mayorista
            'price_tiers'                => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|numeric|min:1',
            'price_tiers.*.unit_price'   => 'required_with:price_tiers|numeric|min:0',
        ]);
        // Flujo de Código Interno (PLU) en actualización
        if (empty($request->internal_code)) {
            $validated['internal_code'] = $product->internal_code;
        } else {
            $validated['internal_code'] = str_pad($request->internal_code, 5, '0', STR_PAD_LEFT);
        }

        if (array_key_exists('barcode', $validated) && empty($validated['barcode'])) {
            $isWeight = $request->has('is_sold_by_weight') ? $request->is_sold_by_weight : $product->is_sold_by_weight;
            $validated['barcode'] = empty($isWeight) ? $this->generateInternalEan13($validated['internal_code']) : null;
        }

        // Auditoría de Stock: Guardar valor previo antes de actualizar
        $oldStock = (float) $product->stock;
        
        $product->update($validated);

        // Si el stock cambió en la edición de la ficha, registrar el movimiento
        if (array_key_exists('stock', $validated) && (float) $validated['stock'] !== $oldStock) {
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
    public function inventoryAlerts(\Illuminate\Http\Request $request)
    {
        $periodDays = 15;
        $threshold  = (int) $request->query('threshold', 3);   // Solo alertamos <= 3 días de cobertura
        $since      = now()->subDays($periodDays)->startOfDay();

        // Subquery: unidades vendidas por producto en los últimos N días
        $salesVelocity = \Illuminate\Support\Facades\DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', $since)
            ->selectRaw('product_id, SUM(quantity) as total_sold')
            ->groupBy('product_id');

        // Query unificada: Alertas Reactivas (Quiebre/Stock Min) + Predictivas (Velocidad de venta)
        $products = \Illuminate\Support\Facades\DB::table('products')
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
            $row->alert_level = match(true) {
                $row->current_stock <= 0 => 'critical',                                // Quiebre total (Reactivo)
                !is_null($row->min_stock) && $row->current_stock <= $row->min_stock => 'critical', // Debajo del mínimo (Reactivo)
                $row->days_of_coverage <= 3  => 'critical',                             // Quiebre inminente (Predictivo)
                default                      => 'info',
            };

            $row->alert_type = match(true) {
                $row->current_stock <= 0 => 'out_of_stock',
                !is_null($row->min_stock) && $row->current_stock <= $row->min_stock => 'low_stock',
                default => 'predictive',
            };
            
            return $row;
        });

        return response()->json([
            'period_analyzed_days' => $periodDays,
            'threshold_days'       => $threshold,
            'generated_at'         => now()->toIso8601String(),
            'alerts'               => $alerts,
        ]);
    }

    /**
     * Genera un PLU numérico de 5 dígitos secuencial.
     */

    private function generateUniqueInternalCode(): string
    {
        // Obtener el último código interno numérico (incluso si fue borrado)
        $lastCode = Product::withTrashed()
            ->whereRaw('internal_code REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(internal_code AS UNSIGNED) DESC')
            ->first();

        $nextNumber = $lastCode ? (int)$lastCode->internal_code + 1 : 1;
        
        // Si por alguna razón el número ya existe, buscamos el siguiente disponible
        while (Product::withTrashed()->where('internal_code', str_pad($nextNumber, 5, '0', STR_PAD_LEFT))->exists()) {
            $nextNumber++;
        }

        return str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Genera un EAN-13 de uso interno estandarizado.
     * Formato: Prefijo (20) + Relleno (00000) + PLU (5 dígitos) + Checksum (1 dígito)
     */
    private function generateInternalEan13(string $plu): string
    {
        $base = '2000000' . str_pad($plu, 5, '0', STR_PAD_LEFT);
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            // Peso 1 para posiciones impares (idx par), Peso 3 para posiciones pares (idx impar)
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;
        
        return $base . $checksum;
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
