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
            'name', 'selling_price', 'cost_price', 'stock',
            'barcode', 'internal_code', 'category_id', 'is_sold_by_weight', 'active'
        ];
        $sortBy = in_array($request->query('sort_by'), $allowedSorts)
            ? $request->query('sort_by')
            : 'name';
        $sortDir = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        return response()->json($query->orderBy($sortBy, $sortDir)->paginate(50));
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
            'stock' => 'numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
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

        // Si el código de barras está vacío, lo dejamos nulo para que coincida con 
        // el comportamiento de los productos a granel de los seeders y no duplique el PLU.
        if (empty($validated['barcode'])) {
            $validated['barcode'] = null;
        }

        $product = Product::create($validated);
        return response()->json($product->load(['category', 'brand', 'supplier']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'supplier']));
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
            'stock' => 'numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $product->update($validated);
        return response()->json($product->load(['category', 'brand', 'supplier']));
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(null, 204);
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
}
