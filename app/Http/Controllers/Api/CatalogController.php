<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BulkPriceHistory;
use App\Models\BulkPriceHistoryItem;
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
            'message' => "Se eliminaron exitosamente {$count} productos.",
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
            'message' => "Se actualizaron exitosamente {$count} productos.",
            'updated_count' => $count
        ]);
    }

    // Método auxiliar para construir la consulta base de filtros
    private function _buildBulkPriceQuery($validated)
    {
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
        return $query;
    }

    // Método auxiliar para obtener la expresión SQL del nuevo precio
    private function _getNewPriceExpression($percentage, $roundingRule, $fieldName = 'selling_price')
    {
        $multiplier = 1 + ($percentage / 100);
        $priceExpression = "{$fieldName} * {$multiplier}";

        if ($roundingRule === 'nearest_10') {
            return "ROUND(($priceExpression) / 10) * 10";
        } elseif ($roundingRule === 'nearest_50') {
            return "ROUND(($priceExpression) / 50) * 50";
        } elseif ($roundingRule === 'nearest_100') {
            return "ROUND(($priceExpression) / 100) * 100";
        } elseif ($roundingRule === 'ends_99') {
            return "FLOOR($priceExpression) + 0.99";
        } else {
            return "ROUND($priceExpression, 2)";
        }
    }

    public function bulkPricePreview(Request $request)
    {
        $validated = $request->validate([
            'percentage' => 'required|numeric|min:-99.99',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'rounding_rule' => 'nullable|string|in:none,nearest_10,nearest_50,nearest_100,ends_99',
            'target_field' => 'nullable|string|in:selling_price,cost_price,cost_and_selling_price',
        ]);

        $query = $this->_buildBulkPriceQuery($validated);
        $totalCount = $query->count();

        $roundingRule = $request->input('rounding_rule', 'none');
        $targetField = $request->input('target_field', 'selling_price');
        
        $priceExpression = $this->_getNewPriceExpression(
            $validated['percentage'], 
            $roundingRule, 
            $targetField === 'cost_price' ? 'cost_price' : 'selling_price'
        );

        $examples = $query->select('id', 'name', 
                $targetField === 'cost_price' ? 'cost_price as old_price' : 'selling_price as old_price'
            )
            ->selectRaw("($priceExpression) as new_price")
            ->take(5)
            ->get();

        return response()->json([
            'affected_count' => $totalCount,
            'examples' => $examples
        ]);
    }

    public function bulkPriceUpdate(Request $request)
    {
        $validated = $request->validate([
            'percentage' => 'required|numeric|min:-99.99',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'rounding_rule' => 'nullable|string|in:none,nearest_10,nearest_50,nearest_100,ends_99',
            'target_field' => 'nullable|string|in:selling_price,cost_price,cost_and_selling_price',
        ]);

        $query = $this->_buildBulkPriceQuery($validated);
        
        $products = $query->select('id', 'cost_price', 'selling_price')->get();
        if ($products->isEmpty()) {
            return response()->json(['message' => 'No hay productos que coincidan con estos filtros.', 'updated_count' => 0]);
        }

        $roundingRule = $request->input('rounding_rule', 'none');
        $targetField = $request->input('target_field', 'selling_price');

        // Create History Header
        $history = BulkPriceHistory::create([
            'user_id' => auth()->id() ?? 1,
            'percentage' => $validated['percentage'],
            'rounding_rule' => $roundingRule,
            'target_field' => $targetField,
            'filters' => collect($validated)->except(['percentage', 'rounding_rule', 'target_field'])->toArray(),
            'affected_count' => $products->count(),
        ]);

        $historyItems = [];
        $multiplier = 1 + ($validated['percentage'] / 100);
        foreach ($products as $p) {
            $newCostPrice = $p->cost_price;
            $newSellingPrice = $p->selling_price;

            if ($targetField === 'cost_price' || $targetField === 'cost_and_selling_price') {
                $newCostPrice = round($p->cost_price * $multiplier, 2);
            }
            if ($targetField === 'selling_price' || $targetField === 'cost_and_selling_price') {
                $raw = $p->selling_price * $multiplier;
                $newSellingPrice = match($roundingRule) {
                    'nearest_10'  => round($raw / 10) * 10,
                    'nearest_50'  => round($raw / 50) * 50,
                    'nearest_100' => round($raw / 100) * 100,
                    'ends_99'     => floor($raw) + 0.99,
                    default       => round($raw, 2),
                };
            }

            $historyItems[] = [
                'bulk_price_history_id' => $history->id,
                'product_id'            => $p->id,
                'old_cost_price'        => $p->cost_price,
                'old_selling_price'     => $p->selling_price,
                'new_cost_price'        => $newCostPrice,
                'new_selling_price'     => $newSellingPrice,
            ];
        }

        foreach (array_chunk($historyItems, 500) as $chunk) {
            BulkPriceHistoryItem::insert($chunk);
        }

        $updateData = [];
        if ($targetField === 'selling_price' || $targetField === 'cost_and_selling_price') {
            $updateData['selling_price'] = DB::raw($this->_getNewPriceExpression($validated['percentage'], $roundingRule, 'selling_price'));
        }
        if ($targetField === 'cost_price' || $targetField === 'cost_and_selling_price') {
            $updateData['cost_price'] = DB::raw($this->_getNewPriceExpression($validated['percentage'], 'none', 'cost_price'));
        }

        $count = 0;
        foreach ($products->pluck('id')->chunk(500) as $chunk) {
            $count += Product::whereIn('id', $chunk)->update($updateData);
        }

        return response()->json([
            'message' => "Se actualizaron exitosamente los precios de {$count} productos.",
            'updated_count' => $count
        ]);
    }

    public function bulkPriceHistory()
    {
        $history = BulkPriceHistory::with('user:id,name')->orderBy('created_at', 'desc')->take(20)->get();
        return response()->json($history);
    }

    public function bulkPriceRevert($id)
    {
        $history = BulkPriceHistory::findOrFail($id);
        if ($history->reverted) {
            return response()->json(['message' => 'El lote ya fue revertido previamente.'], 400);
        }

        DB::transaction(function() use ($history) {
            foreach (array_chunk($history->items()->get()->all(), 500) as $chunk) {
                foreach ($chunk as $item) {
                    $update = [];
                    if ($history->target_field === 'selling_price' || $history->target_field === 'cost_and_selling_price') {
                        $update['selling_price'] = $item->old_selling_price;
                    }
                    if ($history->target_field === 'cost_price' || $history->target_field === 'cost_and_selling_price') {
                        $update['cost_price'] = $item->old_cost_price;
                    }
                    if (!empty($update)) {
                        Product::where('id', $item->product_id)->update($update);
                    }
                }
            }
            $history->update(['reverted' => true, 'reverted_at' => now()]);
        });

        return response()->json(['message' => 'Aumento masivo revertido exitosamente.']);
    }
}
