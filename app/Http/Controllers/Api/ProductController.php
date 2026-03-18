<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'barcode' => 'nullable|string|unique:products,barcode',
            'cost_price' => 'numeric|min:0',
            'selling_price' => 'numeric|min:0',
            'stock' => 'numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        // Flujo Dual de Código de Barras
        $validated['internal_code'] = $this->generateUniqueInternalCode();

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
            'barcode' => 'nullable|string|unique:products,barcode,' . $product->id,
            'cost_price' => 'numeric|min:0',
            'selling_price' => 'numeric|min:0',
            'stock' => 'numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
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

    private function generateUniqueInternalCode(): string
    {
        do {
            // Prefijo '100' (evitar colisión con EAN-13 balanza que usa prefijo '20').
            $code = '100' . substr(str_shuffle("01234567890123456789"), 0, 9);

            // Cálculo de dígito verificador EAN-13
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$code[$i] * ($i % 2 === 0 ? 1 : 3);
            }
            $checksum = (10 - ($sum % 10)) % 10;

            $internalCode = $code . $checksum;

        } while (Product::where('internal_code', $internalCode)->exists());

        return $internalCode;
    }
}
