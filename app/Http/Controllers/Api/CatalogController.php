<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id'
        ]);

        $count = Product::whereIn('id', $validated['product_ids'])->delete();

        return response()->json([
            'message' => "Successfully deleted {$count} products.",
            'deleted_count' => $count
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_id' => 'nullable|exists:categories,id',
            'active' => 'nullable|boolean'
        ]);

        $updates = [];
        if (array_key_exists('category_id', $validated)) {
            $updates['category_id'] = $validated['category_id'];
        }
        if (array_key_exists('active', $validated)) {
            $updates['active'] = $validated['active'];
        }

        if (empty($updates)) {
            return response()->json(['message' => 'No update parameters provided.'], 400);
        }

        $count = Product::whereIn('id', $validated['product_ids'])->update($updates);

        return response()->json([
            'message' => "Successfully updated {$count} products.",
            'updated_count' => $count
        ]);
    }

    public function bulkPriceUpdate(Request $request)
    {
        $validated = $request->validate([
            'percentage' => 'required|numeric',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $query = Product::query();

        if (!empty($validated['product_ids'])) {
            $query->whereIn('id', $validated['product_ids']);
        } else {
            if (isset($validated['category_id'])) {
                $query->where('category_id', $validated['category_id']);
            }
            if (isset($validated['brand_id'])) {
                $query->where('brand_id', $validated['brand_id']);
            }
            if (isset($validated['supplier_id'])) {
                $query->where('supplier_id', $validated['supplier_id']);
            }
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
