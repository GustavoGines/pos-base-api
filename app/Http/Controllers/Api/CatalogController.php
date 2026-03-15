<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function bulkPriceUpdate(Request $request)
    {
        $validated = $request->validate([
            'percentage' => 'required|numeric',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $query = Product::query();

        if (isset($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }
        if (isset($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }
        if (isset($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        }

        $multiplier = 1 + ($validated['percentage'] / 100);

        $count = $query->update([
            'selling_price' => DB::raw("selling_price * {$multiplier}")
        ]);

        return response()->json([
            'message' => "Prices updated successfully for {$count} products.",
            'updated_count' => $count
        ]);
    }
}
