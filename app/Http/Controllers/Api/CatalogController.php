<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkDeleteProductsRequest;
use App\Http\Requests\Catalog\BulkPriceUpdateRequest;
use App\Http\Requests\Catalog\BulkUpdateProductsRequest;
use App\Http\Requests\Catalog\ImportCatalogRequest;
use App\Jobs\ImportCatalogJob;
use App\Models\BulkPriceHistory;
use App\Models\BulkPriceHistoryItem;
use App\Models\Product;
use App\Services\Catalog\CatalogExportService;
use App\Services\Catalog\CatalogImportService;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatalogController extends Controller
{
    public function bulkDelete(BulkDeleteProductsRequest $request)
    {
        $validated = $request->validated();

        $count = DB::transaction(function () use ($validated) {
            return Product::whereIn('id', $validated['product_ids'])->delete();
        });

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

    private function _isSuppliersFeatureLicensed(): bool
    {
        $appPlan = strtolower(trim((string) (DB::table('business_settings')->where('key', 'app_plan')->value('value') ?? '')));
        if (in_array($appPlan, ['pro', 'premium'], true)) {
            return true;
        }

        $featuresJson = DB::table('business_settings')->where('key', 'license_features_dict')->value('value');
        if (! empty($featuresJson)) {
            $decoded = json_decode($featuresJson, true);
            if (is_array($decoded) && ! empty($decoded['suppliers'])) {
                return true;
            }
        }

        return false;
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

    public function bulkPricePreview(BulkPriceUpdateRequest $request)
    {
        $validated = $request->validated();

        if (isset($validated['supplier_id']) && ! $this->_isSuppliersFeatureLicensed()) {
            return response()->json([
                'message' => "La licencia activa no incluye el módulo requerido: 'suppliers'. Actualice su plan o active el Add-on de Proveedores.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required' => 'suppliers',
            ], 403);
        }

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

    private function _recalculateParentCombosCost(array $childProductIds): void
    {
        $uniqueChildIds = array_values(array_unique(array_filter(array_map('intval', $childProductIds))));
        if (empty($uniqueChildIds)) {
            return;
        }

        $parentComboIds = DB::table('product_combos')
            ->whereIn('child_product_id', $uniqueChildIds)
            ->pluck('parent_product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($parentComboIds)) {
            return;
        }

        $combos = Product::with('children')
            ->whereIn('id', $parentComboIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($combos as $combo) {
            $combo->update([
                'cost_price' => $combo->calculateComboCostPrice(),
            ]);
        }
    }

    public function bulkPriceUpdate(BulkPriceUpdateRequest $request)
    {
        $validated = $request->validated();

        if (isset($validated['supplier_id']) && ! $this->_isSuppliersFeatureLicensed()) {
            return response()->json([
                'message' => "La licencia activa no incluye el módulo requerido: 'suppliers'. Actualice su plan o active el Add-on de Proveedores.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required' => 'suppliers',
            ], 403);
        }

        $roundingRule = $request->input('rounding_rule', 'none');
        $targetField = $request->input('target_field', 'selling_price');
        $updatesSelling = $targetField === 'selling_price' || $targetField === 'cost_and_selling_price';
        $updatesCost = $targetField === 'cost_price' || $targetField === 'cost_and_selling_price';

        $count = DB::transaction(function () use ($validated, $roundingRule, $targetField, $updatesSelling, $updatesCost) {
            $products = $this->_buildBulkPriceQuery($validated)
                ->select('id', 'cost_price', 'selling_price')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($products->isEmpty()) {
                return 0;
            }

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
                $oldCost = round((float) ($p->cost_price ?? 0), 2);
                $oldSelling = round((float) ($p->selling_price ?? 0), 2);
                $newCostPrice = $oldCost;
                $newSellingPrice = $oldSelling;

                if ($updatesCost) {
                    $newCostPrice = round($oldCost * $multiplier, 2);
                }
                if ($updatesSelling) {
                    $raw = $oldSelling * $multiplier;
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
                    'old_cost_price' => $oldCost,
                    'old_selling_price' => $oldSelling,
                    'new_cost_price' => $newCostPrice,
                    'new_selling_price' => $newSellingPrice,
                ];
            }

            foreach (array_chunk($historyItems, 500) as $chunk) {
                BulkPriceHistoryItem::insert($chunk);

                $ids = [];
                $sellingCases = [];
                $sellingBindings = [];
                $costCases = [];
                $costBindings = [];

                foreach ($chunk as $row) {
                    $ids[] = (int) $row['product_id'];
                    if ($updatesSelling) {
                        $sellingCases[] = 'WHEN ? THEN ?';
                        $sellingBindings[] = (int) $row['product_id'];
                        $sellingBindings[] = $row['new_selling_price'];
                    }
                    if ($updatesCost) {
                        $costCases[] = 'WHEN ? THEN ?';
                        $costBindings[] = (int) $row['product_id'];
                        $costBindings[] = $row['new_cost_price'];
                    }
                }

                $setClauses = [];
                $params = [];
                if ($updatesSelling && ! empty($sellingCases)) {
                    $setClauses[] = 'selling_price = CASE id '.implode(' ', $sellingCases).' ELSE selling_price END';
                    $params = array_merge($params, $sellingBindings);
                }
                if ($updatesCost && ! empty($costCases)) {
                    $setClauses[] = 'cost_price = CASE id '.implode(' ', $costCases).' ELSE cost_price END';
                    $params = array_merge($params, $costBindings);
                }

                if (! empty($setClauses) && ! empty($ids)) {
                    $setClauses[] = 'updated_at = ?';
                    $params[] = now()->toDateTimeString();
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $params = array_merge($params, $ids);
                    DB::statement('UPDATE products SET '.implode(', ', $setClauses)." WHERE id IN ({$placeholders})", $params);
                }
            }

            if ($updatesCost) {
                $this->_recalculateParentCombosCost($products->pluck('id')->all());
            }

            return $products->count();
        });

        if ($count === 0) {
            return response()->json(['message' => 'No hay productos que coincidan con estos filtros.', 'updated_count' => 0]);
        }

        ReportCacheService::flush();

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
        $historyCheck = BulkPriceHistory::findOrFail($id);
        if ($historyCheck->reverted) {
            return response()->json(['message' => 'El lote ya fue revertido previamente.'], 400);
        }

        $revertSelling = in_array($historyCheck->target_field, ['selling_price', 'cost_and_selling_price'], true);
        $revertCost = in_array($historyCheck->target_field, ['cost_price', 'cost_and_selling_price'], true);

        if (! $revertSelling && ! $revertCost) {
            return response()->json(['message' => 'Tipo de campo objetivo desconocido.'], 422);
        }

        $result = DB::transaction(function () use ($id, $revertSelling, $revertCost) {
            $history = BulkPriceHistory::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($history->reverted) {
                return ['already_reverted' => true, 'reverted_count' => 0, 'skipped_count' => 0];
            }

            $overlappingFields = ['cost_and_selling_price'];
            if ($revertSelling) {
                $overlappingFields[] = 'selling_price';
            }
            if ($revertCost) {
                $overlappingFields[] = 'cost_price';
            }

            $newerTouchedProductIds = DB::table('bulk_price_history_items')
                ->join('bulk_price_histories', 'bulk_price_histories.id', '=', 'bulk_price_history_items.bulk_price_history_id')
                ->where('bulk_price_histories.id', '>', $history->id)
                ->where('bulk_price_histories.reverted', false)
                ->whereIn('bulk_price_histories.target_field', array_values(array_unique($overlappingFields)))
                ->pluck('bulk_price_history_items.product_id')
                ->map(fn ($pid) => (int) $pid)
                ->flip()
                ->all();

            $revertedCount = 0;
            $skippedCount = 0;
            $revertedProductIds = [];

            $history->items()->chunkById(500, function ($chunk) use (
                $revertSelling,
                $revertCost,
                $newerTouchedProductIds,
                &$revertedCount,
                &$skippedCount,
                &$revertedProductIds
            ) {
                $chunkProductIds = $chunk->pluck('product_id')->map(fn ($pid) => (int) $pid)->unique()->sort()->values()->all();
                if (empty($chunkProductIds)) {
                    return;
                }

                $lockedProducts = Product::whereIn('id', $chunkProductIds)
                    ->select('id', 'cost_price', 'selling_price')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $ids = [];
                $sellingCases = [];
                $sellingBindings = [];
                $costCases = [];
                $costBindings = [];

                foreach ($chunk as $item) {
                    $pid = (int) $item->product_id;
                    /** @var Product|null $currentProduct */
                    $currentProduct = $lockedProducts->get($pid);
                    if (! $currentProduct) {
                        continue;
                    }

                    $touchedByNewerBatch = isset($newerTouchedProductIds[$pid]);
                    $sellingUnchanged = ! $revertSelling
                        || abs((float) $currentProduct->selling_price - (float) $item->new_selling_price) <= 0.01;
                    $costUnchanged = ! $revertCost
                        || abs((float) $currentProduct->cost_price - (float) $item->new_cost_price) <= 0.01;

                    if ($touchedByNewerBatch || ! $sellingUnchanged || ! $costUnchanged) {
                        $skippedCount++;

                        continue;
                    }

                    $ids[] = $pid;
                    $revertedProductIds[] = $pid;
                    $revertedCount++;

                    if ($revertSelling) {
                        $sellingCases[] = 'WHEN ? THEN ?';
                        $sellingBindings[] = $pid;
                        $sellingBindings[] = $item->old_selling_price;
                    }

                    if ($revertCost) {
                        $costCases[] = 'WHEN ? THEN ?';
                        $costBindings[] = $pid;
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

            if ($revertedCount === 0 && $skippedCount > 0) {
                return [
                    'already_reverted' => false,
                    'reverted_count' => 0,
                    'skipped_count' => $skippedCount,
                ];
            }

            $history->update(['reverted' => true, 'reverted_at' => now()]);

            if ($revertCost && ! empty($revertedProductIds)) {
                $this->_recalculateParentCombosCost($revertedProductIds);
            }

            return [
                'already_reverted' => false,
                'reverted_count' => $revertedCount,
                'skipped_count' => $skippedCount,
            ];
        });

        if (! empty($result['already_reverted'])) {
            return response()->json(['message' => 'El lote ya fue revertido previamente.'], 400);
        }

        if ($result['reverted_count'] === 0 && $result['skipped_count'] > 0) {
            return response()->json([
                'message' => "No se revirtió ningún producto porque los {$result['skipped_count']} productos del lote tuvieron cambios de precio posteriores.",
                'reverted_count' => 0,
                'skipped_count' => $result['skipped_count'],
            ], 422);
        }

        ReportCacheService::flush();

        $message = $result['skipped_count'] > 0
            ? "Se revirtieron {$result['reverted_count']} productos y se preservaron {$result['skipped_count']} que tenían cambios de precio posteriores."
            : 'Aumento masivo revertido exitosamente.';

        return response()->json([
            'message' => $message,
            'reverted_count' => $result['reverted_count'],
            'skipped_count' => $result['skipped_count'],
        ]);
    }

    public function export(Request $request, CatalogExportService $exportService)
    {
        $categoryId = $request->query('category_id') ? (int) $request->query('category_id') : null;
        $brandId = $request->query('brand_id') ? (int) $request->query('brand_id') : null;

        return $exportService->exportPriceList($categoryId, $brandId);
    }

    public function import(ImportCatalogRequest $request, CatalogImportService $importService)
    {
        try {
            $options = [
                'default_supplier_id' => $request->input('default_supplier_id'),
                'default_margin_percent' => $request->input('default_margin_percent'),
            ];

            if ($request->boolean('async')) {
                $file = $request->file('file');
                $filename = 'import_'.uniqid().'.'.$file->getClientOriginalExtension();
                $storedPath = $file->storeAs('catalog_imports', $filename);
                $fullPath = storage_path('app/'.$storedPath);
                $jobKey = 'import_'.uniqid();

                ImportCatalogJob::dispatch($fullPath, auth()->id(), $jobKey, $options);

                return response()->json([
                    'message' => 'Importación iniciada en segundo plano.',
                    'job_key' => $jobKey,
                    'status' => 'processing',
                ], 202);
            }

            $path = $request->file('file')->getRealPath();
            $result = $importService->import($path, $options);

            return response()->json([
                'message' => 'Importación completada',
                'created_count' => $result['created_count'],
                'updated_count' => $result['updated_count'],
                'failed_count' => $result['failed_count'],
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error general en importación: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Error al procesar el archivo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadImportTemplate(CatalogExportService $exportService)
    {
        return $exportService->downloadTemplate();
    }
}
