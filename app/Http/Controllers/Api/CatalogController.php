<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkUpdateProductsRequest;
use App\Models\BulkPriceHistory;
use App\Models\BulkPriceHistoryItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $count = Product::whereIn('id', $validated['product_ids'])->delete();

        return response()->json([
            'message' => "Se eliminaron exitosamente {$count} productos.",
            'deleted_count' => $count,
        ]);
    }

    public function bulkUpdate(BulkUpdateProductsRequest $request)
    {
        $validated = $request->validated();

        $fillableFields = ['category_id', 'brand_id', 'supplier_id', 'active'];
        $updates = [];
        foreach ($fillableFields as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (empty($updates)) {
            return response()->json(['message' => 'No update parameters provided.'], 400);
        }

        $productIds = $validated['product_ids'];
        $count = 0;

        try {
            DB::transaction(function () use ($productIds, $updates, &$count) {
                foreach (array_chunk($productIds, 500) as $chunk) {
                    $count += Product::whereIn('id', $chunk)->update($updates);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Error en bulkUpdate de productos: '.$e->getMessage(), [
                'exception' => $e,
                'payload' => $validated,
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al procesar la actualización masiva de productos.',
            ], 500);
        }

        return response()->json([
            'message' => "Se actualizaron exitosamente {$count} productos.",
            'updated_count' => $count,
            'affected_ids' => $productIds,
        ]);
    }

    // Método auxiliar para construir la consulta base de filtros
    private function _buildBulkPriceQuery($validated)
    {
        $query = Product::query();

        if (! empty($validated['product_ids'])) {
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
            'examples' => $examples,
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
                $newSellingPrice = match ($roundingRule) {
                    'nearest_10' => round($raw / 10) * 10,
                    'nearest_50' => round($raw / 50) * 50,
                    'nearest_100' => round($raw / 100) * 100,
                    'ends_99' => floor($raw) + 0.99,
                    default => round($raw, 2),
                };
            }

            $historyItems[] = [
                'bulk_price_history_id' => $history->id,
                'product_id' => $p->id,
                'old_cost_price' => $p->cost_price,
                'old_selling_price' => $p->selling_price,
                'new_cost_price' => $newCostPrice,
                'new_selling_price' => $newSellingPrice,
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
            'updated_count' => $count,
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

        $revertSelling = in_array($history->target_field, ['selling_price', 'cost_and_selling_price']);
        $revertCost = in_array($history->target_field, ['cost_price', 'cost_and_selling_price']);

        if (! $revertSelling && ! $revertCost) {
            return response()->json(['message' => 'Tipo de campo objetivo desconocido.'], 422);
        }

        DB::transaction(function () use ($history, $revertSelling, $revertCost) {
            $history->items()->chunkById(500, function ($chunk) use ($revertSelling, $revertCost) {
                $ids = [];
                $sellingCases = [];
                $sellingBindings = [];
                $costCases = [];
                $costBindings = [];

                foreach ($chunk as $item) {
                    $ids[] = $item->product_id;

                    if ($revertSelling) {
                        $sellingCases[] = 'WHEN ? THEN ?';
                        $sellingBindings[] = $item->product_id;
                        $sellingBindings[] = $item->old_selling_price;
                    }

                    if ($revertCost) {
                        $costCases[] = 'WHEN ? THEN ?';
                        $costBindings[] = $item->product_id;
                        $costBindings[] = $item->old_cost_price;
                    }
                }

                if (empty($ids)) {
                    return;
                }

                $setClauses = [];
                $params = [];

                if ($revertSelling && ! empty($sellingCases)) {
                    $setClauses[] = 'selling_price = CASE id '.implode(' ', $sellingCases).' ELSE selling_price END';
                    $params = array_merge($params, $sellingBindings);
                }

                if ($revertCost && ! empty($costCases)) {
                    $setClauses[] = 'cost_price = CASE id '.implode(' ', $costCases).' ELSE cost_price END';
                    $params = array_merge($params, $costBindings);
                }

                if (empty($setClauses)) {
                    return;
                }

                $setClauses[] = 'updated_at = ?';
                $params[] = now()->toDateTimeString();

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge($params, $ids);

                $sql = 'UPDATE products SET '.implode(', ', $setClauses)." WHERE id IN ({$placeholders})";

                DB::statement($sql, $params);
            });

            $history->update(['reverted' => true, 'reverted_at' => now()]);
        });

        return response()->json(['message' => 'Aumento masivo revertido exitosamente.']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,xls,txt',
        ]);

        ini_set('memory_limit', '1024M');
        set_time_limit(600);

        try {
            $createdCount = 0;
            $updatedCount = 0;
            $failedCount = 0;
            $errors = [];

            $defaultRubro = \App\Models\Rubro::where('is_system', true)->first();
            $path = $request->file('file')->getRealPath();

            (new \Rap2hpoutre\FastExcel\FastExcel)->import($path, function ($row) use (&$createdCount, &$updatedCount, &$failedCount, &$errors, $defaultRubro) {
                $rowLower = [];
                foreach ($row as $k => $v) {
                    $key = strtolower(trim($k));
                    $key = str_replace(' ', '_', $key);
                    $rowLower[$key] = $v;
                }

                $getVal = function($keys) use ($rowLower) {
                    foreach ($keys as $k) {
                        if (isset($rowLower[$k]) && $rowLower[$k] !== '') {
                            return trim($rowLower[$k]);
                        }
                    }
                    return null;
                };

                $nombre = $getVal(['nombre', 'descripcion', 'descripción', 'articulo', 'artículo', 'producto', 'detalle']);
                $codInterno = $getVal(['codigo_interno', 'código_interno', 'codigo', 'cod', 'id']);
                $codBarras = $getVal(['codigo_barras', 'código_barras', 'barra', 'ean']);
                $costo = $getVal(['costo', 'precios_costos_g3', 'precio_costo', 'costo_unitario']);
                $precio = $getVal(['precio', 'precios_pesos_g3_nomas', 'precio_venta', 'precio_publico', 'venta']);
                $stock = $getVal(['stock', 'cantidad', 'cant', 'existencia']);
                $categoria = $getVal(['categoria', 'categoría', 'rubro', 'familia']);
                $marca = $getVal(['marca', 'laboratorio', 'fabricante']);
                $iva = $getVal(['iva', 'impuesto', 'tasa']);
                $activo = $getVal(['activo', 'estado']);

                if (!$nombre && !$codInterno && !$codBarras) {
                    return null;
                }

                try {
                    $internalCode = $codInterno ?? ($codBarras ?? 'PROD-' . \Illuminate\Support\Str::random(8));

                    $product = null;
                    if ($codInterno) { $product = \App\Models\Product::where('internal_code', $codInterno)->first(); }
                    if (!$product && $codBarras) { $product = \App\Models\Product::where('barcode', $codBarras)->first(); }
                    if (!$product && $nombre) { $product = \App\Models\Product::where('name', $nombre)->first(); }

                    $isNew = false;
                    if (!$product) {
                        if (!$nombre) {
                            $failedCount++;
                            return null;
                        }
                        $product = new \App\Models\Product();
                        $product->internal_code = $internalCode;
                        $isNew = true;
                    }

                    if ($categoria) {
                        $cat = \App\Models\Category::firstOrCreate(
                            ['name' => $categoria],
                            ['rubro_id' => $defaultRubro ? $defaultRubro->id : null]
                        );
                        $product->category_id = $cat->id;
                    }

                    if ($marca) {
                        $bnd = \App\Models\Brand::firstOrCreate(['name' => $marca]);
                        $product->brand_id = $bnd->id;
                    }

                    if ($nombre) { $product->name = $nombre; }
                    if ($codBarras) { $product->barcode = $codBarras; }
                    if ($costo !== null) { $product->cost_price = (float) str_replace(',', '.', $costo); }
                    if ($precio !== null) { $product->selling_price = (float) str_replace(',', '.', $precio); }
                    if ($stock !== null) { $product->stock = (float) str_replace(',', '.', $stock); }
                    if ($iva !== null) { $product->iva_rate = (float) str_replace(',', '.', $iva); }
                    if ($activo !== null) { $product->active = filter_var($activo, FILTER_VALIDATE_BOOLEAN); }

                    $product->save();

                    if ($isNew) {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                }
                return null;
            });

            return response()->json([
                'message' => 'Importación completada',
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'failed_count' => $failedCount,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error general en importación: " . $e->getMessage());
            return response()->json([
                'message' => 'Error al procesar el archivo.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadImportTemplate()
    {
        $headers = ['codigo_interno', 'codigo_barras', 'nombre', 'costo', 'precio', 'stock', 'categoria', 'marca', 'iva', 'activo'];
        $callback = function() use ($headers) {
            $file = fopen('php://output', 'w');
            // Necesario para que Excel abra el CSV UTF-8 correctamente sin romper acentos
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $headers, ';');
            fputcsv($file, ['PROD-001', '7791234567890', 'Ejemplo Arroz', '100.00', '150.00', '10', 'Almacen', 'Marolio', '21.00', 'true'], ';');
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla_productos.csv"',
        ]);
    }
}
