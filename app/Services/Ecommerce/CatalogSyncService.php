<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Models\Category;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatalogSyncService
{
    public function __construct(
        protected EcommerceManager $ecommerceManager
    ) {}

    /**
     * Import entire catalog from remote ecommerce platform into POS.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     total_remote: int,
     *     imported: int,
     *     updated: int,
     *     mapped: int,
     *     errors: array<int, string>
     * }
     */
    public function importFromRemote(EcommerceIntegration $integration): array
    {
        $adapter = $this->ecommerceManager->forIntegration($integration);

        if (! method_exists($adapter, 'fetchCatalog')) {
            return [
                'success' => false,
                'message' => "La plataforma {$integration->platform} no soporta importación directa de catálogo.",
                'total_remote' => 0,
                'imported' => 0,
                'updated' => 0,
                'mapped' => 0,
                'errors' => ['ADAPTER_METHOD_NOT_SUPPORTED'],
            ];
        }

        $remoteCatalog = $adapter->fetchCatalog();
        if (! ($remoteCatalog['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $remoteCatalog['message'] ?? 'Error al consultar catálogo remoto',
                'total_remote' => 0,
                'imported' => 0,
                'updated' => 0,
                'mapped' => 0,
                'errors' => [$remoteCatalog['message'] ?? 'REMOTE_FETCH_FAILED'],
            ];
        }

        $products = $remoteCatalog['products'] ?? [];
        $totalRemote = count($products);
        $importedCount = 0;
        $updatedCount = 0;
        $mappedCount = 0;
        $errors = [];

        foreach ($products as $remoteItem) {
            try {
                DB::transaction(function () use (
                    $remoteItem,
                    $integration,
                    &$importedCount,
                    &$updatedCount,
                    &$mappedCount
                ) {
                    $extId = (string) ($remoteItem['id'] ?? '');
                    $sku = isset($remoteItem['sku']) && trim((string) $remoteItem['sku']) !== ''
                        ? trim((string) $remoteItem['sku'])
                        : null;
                    $name = trim((string) ($remoteItem['name'] ?? "Producto Web {$extId}"));
                    $price = (float) ($remoteItem['price'] ?? 0);
                    $costPrice = (float) ($remoteItem['cost_price'] ?? 0);
                    $stock = (float) ($remoteItem['stock'] ?? 0);
                    $barcode = isset($remoteItem['barcode']) && trim((string) $remoteItem['barcode']) !== ''
                        ? trim((string) $remoteItem['barcode'])
                        : null;
                    $categoryName = isset($remoteItem['category']) && trim((string) $remoteItem['category']) !== ''
                        ? trim((string) $remoteItem['category'])
                        : null;

                    // 1. Resolver o crear categoría
                    $categoryId = null;
                    if ($categoryName !== null) {
                        $category = Category::firstOrCreate(['name' => $categoryName]);
                        $categoryId = $category->id;
                    }

                    // 2. Buscar si ya existe un mapping para esta integración
                    $mapping = null;
                    if ($extId !== '') {
                        $mapping = EcommerceProductMapping::where('integration_id', $integration->id)
                            ->where('external_product_id', $extId)
                            ->first();
                    }

                    if (! $mapping && ! empty($sku)) {
                        $mapping = EcommerceProductMapping::where('integration_id', $integration->id)
                            ->where('external_sku', $sku)
                            ->first();
                    }

                    $localProduct = $mapping?->product;

                    // 3. Cascada si no hay producto asignado por mapping
                    if (! $localProduct) {
                        if (! empty($sku)) {
                            $localProduct = Product::where('internal_code', $sku)->first();
                        }

                        if (! $localProduct && ! empty($barcode)) {
                            $localProduct = Product::where('barcode', $barcode)->first();
                        }

                        if (! $localProduct && $extId !== '') {
                            $localProduct = Product::where('barcode', $extId)->first();
                        }

                        if (! $localProduct && ! empty($name)) {
                            $localProduct = Product::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->first();
                        }

                        if (! $localProduct && $extId !== '' && ctype_digit($extId)) {
                            $localProduct = Product::find((int) $extId);
                        }
                    }

                    // 4. Actualizar existente o crear nuevo producto en POS
                    if ($localProduct) {
                        $updateData = [
                            'selling_price' => $price > 0 ? $price : $localProduct->selling_price,
                            'cost_price' => $costPrice > 0 ? $costPrice : $localProduct->cost_price,
                        ];

                        if (empty($localProduct->barcode) && ! empty($barcode)) {
                            $updateData['barcode'] = $barcode;
                        }

                        if (empty($localProduct->category_id) && $categoryId !== null) {
                            $updateData['category_id'] = $categoryId;
                        }

                        $localProduct->update($updateData);
                        $updatedCount++;
                    } else {
                        $localProduct = Product::create([
                            'name' => $name,
                            'internal_code' => $sku ?? ($extId !== '' ? "WEB-{$extId}" : 'PROD-'.uniqid()),
                            'barcode' => $barcode,
                            'selling_price' => $price,
                            'cost_price' => $costPrice,
                            'iva_rate' => 21.00,
                            'stock' => $stock,
                            'min_stock' => 0,
                            'active' => true,
                            'category_id' => $categoryId,
                        ]);
                        $importedCount++;
                    }

                    // 5. Crear o actualizar mapping de sincronización
                    if (! $mapping) {
                        EcommerceProductMapping::create([
                            'integration_id' => $integration->id,
                            'product_id' => $localProduct->id,
                            'platform' => $integration->platform,
                            'external_product_id' => $extId !== '' ? $extId : (string) $localProduct->id,
                            'external_variant_id' => '0',
                            'external_sku' => $sku ?? $localProduct->internal_code,
                            'external_title' => $name,
                            'sync_stock' => true,
                            'last_known_local_stock' => (float) $localProduct->stock,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                        $mappedCount++;
                    } else {
                        $mapping->update([
                            'product_id' => $localProduct->id,
                            'external_sku' => $sku ?? $localProduct->internal_code,
                            'external_title' => $name,
                            'last_known_local_stock' => (float) $localProduct->stock,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                Log::error("Error importando producto remoto {$remoteItem['id']}: ".$e->getMessage());
                $errors[] = "Error en producto #{$remoteItem['id']}: {$e->getMessage()}";
            }
        }

        return [
            'success' => true,
            'message' => "Importación completada: {$importedCount} nuevos, {$updatedCount} actualizados, {$mappedCount} mapeos creados.",
            'total_remote' => $totalRemote,
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'mapped' => $mappedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Export active POS catalog to remote ecommerce platform (seeding a new store).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     exported_count: int,
     *     errors: array<int, string>
     * }
     */
    public function exportToRemote(EcommerceIntegration $integration): array
    {
        $adapter = $this->ecommerceManager->forIntegration($integration);

        if (! method_exists($adapter, 'bulkSyncCatalog')) {
            return [
                'success' => false,
                'message' => "La plataforma {$integration->platform} no soporta siembra masiva de catálogo.",
                'exported_count' => 0,
                'errors' => ['ADAPTER_METHOD_NOT_SUPPORTED'],
            ];
        }

        $localProducts = Product::with(['category', 'brand'])
            ->where('active', true)
            ->get();

        if ($localProducts->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No hay productos activos en el POS para sembrar en la web.',
                'exported_count' => 0,
                'errors' => ['NO_ACTIVE_PRODUCTS'],
            ];
        }

        $payload = [];
        foreach ($localProducts as $product) {
            $payload[] = [
                'pos_product_id' => $product->id,
                'sku' => $product->internal_code ?? "PROD-{$product->id}",
                'name' => $product->name,
                'price' => (float) $product->selling_price,
                'cost_price' => (float) $product->cost_price,
                'stock' => (float) $product->stock,
                'barcode' => $product->barcode,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
            ];
        }

        $result = $adapter->bulkSyncCatalog($payload);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Error al enviar catálogo a la plataforma web',
                'exported_count' => 0,
                'errors' => [$result['message'] ?? 'REMOTE_BULK_SYNC_FAILED'],
            ];
        }

        // Registrar o actualizar mappings para los productos sembrados
        foreach ($localProducts as $product) {
            EcommerceProductMapping::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'product_id' => $product->id,
                ],
                [
                    'platform' => $integration->platform,
                    'external_product_id' => (string) $product->id,
                    'external_variant_id' => '0',
                    'external_sku' => $product->internal_code ?? "PROD-{$product->id}",
                    'external_title' => $product->name,
                    'sync_stock' => true,
                    'last_known_local_stock' => (float) $product->stock,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]
            );
        }

        return [
            'success' => true,
            'message' => "Se sembraron {$result['synced_count']} productos en la plataforma web exitosamente.",
            'exported_count' => (int) $result['synced_count'],
            'errors' => [],
        ];
    }
}
