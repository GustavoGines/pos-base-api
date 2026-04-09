<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'supplier']);

        if ($search = $request->query('search')) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('name', 'like', $like)
                  ->orWhere('barcode', 'like', $like)
                  ->orWhere('internal_code', 'like', $like);
            });
        }

        $allowedSorts = [
            'id', 'name', 'selling_price', 'cost_price', 'stock',
            'barcode', 'internal_code', 'category_id', 'is_sold_by_weight', 'active', 'sales_count', 'vencimiento_dias'
        ];
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            // Default sorting when no specific sort is requested
            $query->orderBy('sales_count', 'desc')
                  ->orderBy('is_sold_by_weight', 'desc')
                  ->orderBy('name', 'asc');
        }

        return response()->json($query->paginate(100));
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
        return response()->json($product->load(['category', 'brand', 'supplier']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'supplier']));
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
                'user_id' => auth()->id(), // Asumiendo que hay un usuario autenticado
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

        $product->update($validated);
        return response()->json($product->load(['category', 'brand', 'supplier']));
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
}
