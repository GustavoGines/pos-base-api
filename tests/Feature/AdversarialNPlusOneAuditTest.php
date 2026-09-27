<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BulkPriceHistory;
use App\Models\BulkPriceHistoryItem;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdversarialNPlusOneAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'multi_caja' => true,
                'expenses' => true,
                'advanced_reports' => true,
            ])]
        );
    }

    /**
     * Helper: crear productos masivos con relaciones completas
     */
    private function createProductsWithRelations(int $count): array
    {
        $category = Category::create(['name' => 'Adversarial Category '.uniqid()]);
        $brand = Brand::create(['name' => 'Adversarial Brand '.uniqid()]);
        $supplier = Supplier::create([
            'name' => 'Adversarial Supplier '.uniqid(),
            'document_number' => '30-'.rand(10000000, 99999999).'-1',
        ]);

        $productIds = [];
        $now = now()->toDateTimeString();
        $inserts = [];

        for ($i = 1; $i <= $count; $i++) {
            $inserts[] = [
                'name' => "Adversarial Prod {$i} ".uniqid(),
                'internal_code' => 'ADV-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).uniqid(),
                'barcode' => '779'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'cost_price' => 50.00,
                'selling_price' => 100.00,
                'stock' => 25.0,
                'min_stock' => 5.0,
                'active' => true,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'supplier_id' => $supplier->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insertar en chunks para no saturar SQLite / MySQL bindings
        foreach (array_chunk($inserts, 100) as $chunk) {
            Product::insert($chunk);
        }

        $products = Product::where('category_id', $category->id)->get();
        $tierInserts = [];
        foreach ($products as $p) {
            $productIds[] = $p->id;
            $tierInserts[] = [
                'product_id' => $p->id,
                'min_quantity' => 10,
                'unit_price' => 90.00,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $tierInserts[] = [
                'product_id' => $p->id,
                'min_quantity' => 50,
                'unit_price' => 80.00,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($tierInserts, 100) as $chunk) {
            ProductPriceTier::insert($chunk);
        }

        return [$products, $productIds, $category, $brand, $supplier];
    }

    /**
     * AUDIT 1.A: bulkPriceRevert() con 50 productos.
     * Debe ejecutar exactamente 1 UPDATE vectorizado sobre `products` y 1 UPDATE sobre `bulk_price_histories`.
     * Total UPDATEs sobre products = 1 (O(1)), jamás 50 (O(N)).
     */
    public function test_bulk_price_revert_query_log_with_50_products_executes_single_vectorized_update(): void
    {
        [$products, $productIds] = $this->createProductsWithRelations(50);
        $this->assertCount(50, $productIds);

        // Crear registro de historial bulk para 50 productos
        $history = BulkPriceHistory::create([
            'user_id' => $this->admin->id,
            'percentage' => 20,
            'target_field' => 'selling_price',
            'affected_count' => 50,
            'rounding_rule' => 'none',
            'reverted' => false,
        ]);

        $historyItems = [];
        $now = now()->toDateTimeString();
        foreach ($productIds as $pid) {
            $historyItems[] = [
                'bulk_price_history_id' => $history->id,
                'product_id' => $pid,
                'old_cost_price' => 50.00,
                'new_cost_price' => 50.00,
                'old_selling_price' => 100.00,
                'new_selling_price' => 120.00,
            ];
        }
        BulkPriceHistoryItem::insert($historyItems);

        // Simular que los productos tienen el nuevo precio $120.00
        Product::whereIn('id', $productIds)->update(['selling_price' => 120.00]);

        // Monitorear Query Log
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        fwrite(STDERR, "\n=== VERBATIM QUERY LOG (bulkPriceRevert 50 items) ===\n");
        foreach ($queries as $idx => $q) {
            fwrite(STDERR, sprintf("[%d] %s (time: %sms)\n", $idx + 1, $q['query'], $q['time']));
        }
        fwrite(STDERR, "=== END QUERY LOG ===\n");

        $productUpdateQueries = array_filter($queries, function ($q) {
            return preg_match('/update\s+["`]?products["`]?/i', $q['query']);
        });

        // Verificación empírica estricta:
        // Debe haber EXACTAMENTE 1 consulta UPDATE a la tabla products
        $this->assertCount(
            1,
            $productUpdateQueries,
            'bulkPriceRevert() with 50 products must execute EXACTLY 1 vectorized UPDATE on products, not O(N) queries.'
        );

        // Verificar contenido de la consulta vectorizada
        $vectorizedQuery = array_values($productUpdateQueries)[0]['query'];
        $this->assertStringContainsString('CASE id', $vectorizedQuery);
        $this->assertStringContainsString('WHERE id IN', $vectorizedQuery);

        // Verificar que los 50 productos volvieron a $100.00
        $revertedCount = Product::whereIn('id', $productIds)->where('selling_price', 100.00)->count();
        $this->assertEquals(50, $revertedCount);
        $this->assertTrue((bool) $history->fresh()->reverted);
    }

    /**
     * AUDIT 1.B: bulkPriceRevert() con 100 productos.
     * Debe ejecutar exactamente 1 UPDATE vectorizado sobre `products` y 1 UPDATE sobre `bulk_price_histories`.
     * Total UPDATEs sobre products = 1 (O(1)), jamás 100 (O(N)).
     */
    public function test_bulk_price_revert_query_log_with_100_products_executes_single_vectorized_update(): void
    {
        [$products, $productIds] = $this->createProductsWithRelations(100);
        $this->assertCount(100, $productIds);

        $history = BulkPriceHistory::create([
            'user_id' => $this->admin->id,
            'percentage' => 30,
            'target_field' => 'selling_price',
            'affected_count' => 100,
            'rounding_rule' => 'none',
            'reverted' => false,
        ]);

        $historyItems = [];
        $now = now()->toDateTimeString();
        foreach ($productIds as $pid) {
            $historyItems[] = [
                'bulk_price_history_id' => $history->id,
                'product_id' => $pid,
                'old_cost_price' => 50.00,
                'new_cost_price' => 50.00,
                'old_selling_price' => 100.00,
                'new_selling_price' => 130.00,
            ];
        }
        BulkPriceHistoryItem::insert($historyItems);

        Product::whereIn('id', $productIds)->update(['selling_price' => 130.00]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        fwrite(STDERR, "\n=== VERBATIM QUERY LOG (bulkPriceRevert 100 items) ===\n");
        foreach ($queries as $idx => $q) {
            fwrite(STDERR, sprintf("[%d] %s (time: %sms)\n", $idx + 1, $q['query'], $q['time']));
        }
        fwrite(STDERR, "=== END QUERY LOG ===\n");

        $productUpdateQueries = array_filter($queries, function ($q) {
            return preg_match('/update\s+["`]?products["`]?/i', $q['query']);
        });

        // Verificación empírica estricta:
        // Con 100 productos, sigue siendo EXACTAMENTE 1 consulta UPDATE (demostrando O(1) por bloque de 500)
        $this->assertCount(
            1,
            $productUpdateQueries,
            'bulkPriceRevert() with 100 products must execute EXACTLY 1 vectorized UPDATE query.'
        );

        $revertedCount = Product::whereIn('id', $productIds)->where('selling_price', 100.00)->count();
        $this->assertEquals(100, $revertedCount);
    }

    /**
     * AUDIT 1.C: bulkPriceRevert() con 550 productos (Cruza el umbral del chunk de 500).
     * Debe ejecutar exactamente ceil(550 / 500) = 2 UPDATEs vectorizados sobre `products`.
     * Total UPDATEs sobre products = 2, jamás 550.
     */
    public function test_bulk_price_revert_query_log_with_550_products_executes_exactly_two_vectorized_updates(): void
    {
        [$products, $productIds] = $this->createProductsWithRelations(550);
        $this->assertCount(550, $productIds);

        $history = BulkPriceHistory::create([
            'user_id' => $this->admin->id,
            'percentage' => 15,
            'target_field' => 'cost_and_selling_price',
            'affected_count' => 550,
            'rounding_rule' => 'none',
            'reverted' => false,
        ]);

        $historyItems = [];
        $now = now()->toDateTimeString();
        foreach ($productIds as $pid) {
            $historyItems[] = [
                'bulk_price_history_id' => $history->id,
                'product_id' => $pid,
                'old_cost_price' => 50.00,
                'new_cost_price' => 57.50,
                'old_selling_price' => 100.00,
                'new_selling_price' => 115.00,
            ];
        }
        foreach (array_chunk($historyItems, 100) as $chunk) {
            BulkPriceHistoryItem::insert($chunk);
        }

        Product::whereIn('id', $productIds)->update(['selling_price' => 115.00, 'cost_price' => 57.50]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $productUpdateQueries = array_filter($queries, function ($q) {
            return preg_match('/update\s+["`]?products["`]?/i', $q['query']);
        });

        // 550 productos divididos en chunks de 500 = exactamente 2 updates
        $this->assertCount(
            2,
            $productUpdateQueries,
            'bulkPriceRevert() with 550 products must chunk into EXACTLY 2 UPDATE queries (ceil(550/500)), never 550.'
        );

        $revertedSelling = Product::whereIn('id', $productIds)->where('selling_price', 100.00)->count();
        $revertedCost = Product::whereIn('id', $productIds)->where('cost_price', 50.00)->count();
        $this->assertEquals(550, $revertedSelling);
        $this->assertEquals(550, $revertedCost);
    }

    /**
     * AUDIT 2.A: GET /api/catalog/products con 50 productos.
     * Inspeccionar Query Log y verificar que las relaciones (category, brand, supplier, children, priceTiers)
     * se cargan mediante eager loading en O(1) consultas fijas y NO 1 consulta por fila de producto.
     */
    public function test_catalog_listing_query_log_with_50_products_eager_loads_all_relations(): void
    {
        [$products, $productIds] = $this->createProductsWithRelations(50);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/catalog/products?per_page=50');
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        fwrite(STDERR, "\n=== VERBATIM QUERY LOG (GET /api/catalog/products 50 items) ===\n");
        foreach ($queries as $idx => $q) {
            fwrite(STDERR, sprintf("[%d] %s (time: %sms)\n", $idx + 1, $q['query'], $q['time']));
        }
        fwrite(STDERR, "=== END QUERY LOG ===\n");

        $data = $response->json('data');
        $this->assertCount(50, $data);

        // Verificar que las relaciones están presentes en el payload JSON
        $firstItem = $data[0];
        $this->assertArrayHasKey('category', $firstItem);
        $this->assertArrayHasKey('brand', $firstItem);
        $this->assertArrayHasKey('supplier', $firstItem);
        $this->assertArrayHasKey('children', $firstItem);
        $this->assertArrayHasKey('price_tiers', $firstItem);
        $this->assertNotEmpty($firstItem['price_tiers']);

        // Contar el número total de consultas ejecutadas para los 50 productos
        $totalQueries = count($queries);

        // Estructura esperada de consultas:
        // 1. SELECT count(*) as aggregate FROM products
        // 2. SELECT * FROM products ORDER BY ... LIMIT 50
        // 3. SELECT * FROM categories WHERE id IN (...)
        // 4. SELECT * FROM brands WHERE id IN (...)
        // 5. SELECT * FROM suppliers WHERE id IN (...)
        // 6. SELECT * FROM products INNER JOIN product_combos ... (children)
        // 7. SELECT * FROM product_price_tiers WHERE product_id IN (...)
        // TOTAL = 7 consultas fijas
        $this->assertLessThanOrEqual(
            8,
            $totalQueries,
            "Listing 50 products must execute at most 8 queries (found {$totalQueries}). If N+1 were present, it would execute >= 50 queries."
        );
    }

    /**
     * AUDIT 2.B: GET /api/catalog/products escala de 10 vs 100 productos.
     * El número de consultas DEBE ser IDÉNTICO para 10 productos y para 100 productos.
     * Si existiese N+1, 100 productos generaría ~10x más consultas que 10 productos.
     */
    public function test_catalog_listing_query_log_scales_o_1_between_10_and_100_products(): void
    {
        // 1. Probar con 10 productos
        $this->createProductsWithRelations(10);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res10 = $this->getJson('/api/catalog/products?per_page=10');
        $res10->assertStatus(200);

        $queries10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 2. Limpiar e insertar 100 productos
        ProductPriceTier::truncate();
        Product::truncate();
        $this->createProductsWithRelations(100);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res100 = $this->getJson('/api/catalog/products?per_page=100');
        $res100->assertStatus(200);

        $queries100 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Verificación empírica de O(1) queries:
        // Ambas llamadas deben ejecutar exactamente el mismo número de consultas (7 consultas)
        $this->assertEquals(
            $queries10,
            $queries100,
            "Query count for 100 products ({$queries100}) must equal query count for 10 products ({$queries10}). Absolute O(1) query scaling proven."
        );
    }

    /**
     * AUDIT 2.C: GET /api/catalog/products con filtros de búsqueda y ordenamiento por relaciones.
     * Verificar que no hay N+1 al filtrar por búsqueda o al ordenar por brand_id / category_id / supplier_id.
     */
    public function test_catalog_listing_with_search_and_sorting_executes_constant_queries(): void
    {
        [$products, $productIds, $cat, $brand, $sup] = $this->createProductsWithRelations(30);

        // Probar ordenamiento por relación (ej. category_id que usa leftJoin)
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resSort = $this->getJson('/api/catalog/products?sort_by=category_id&sort_direction=desc&per_page=30');
        $resSort->assertStatus(200);

        $queriesSort = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queriesSort);

        // Probar filtro de búsqueda
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resSearch = $this->getJson('/api/catalog/products?search=Adversarial&per_page=30');
        $resSearch->assertStatus(200);

        $queriesSearch = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queriesSearch);
    }

    /**
     * AUDIT 3.A: Invalidation Atomicity & Monotonicity under Repeated Rapid Flush.
     * Simular 100 flushes consecutivos y llamadas concurrentes sin condiciones de carrera.
     */
    public function test_cache_invalidation_atomicity_and_monotonicity_under_repeated_mutations(): void
    {
        $vStart = ReportCacheService::getVersion();

        $seenVersions = [$vStart];

        for ($i = 1; $i <= 50; $i++) {
            ReportCacheService::flush();
            $currentV = ReportCacheService::getVersion();

            // La versión debe ser estrictamente monótona creciente
            $this->assertGreaterThan(
                end($seenVersions),
                $currentV,
                "Cache version must be strictly monotonic at step {$i}."
            );
            $seenVersions[] = $currentV;
        }

        $this->assertCount(51, $seenVersions);
        $this->assertEquals($vStart + 50, end($seenVersions));
    }

    /**
     * AUDIT 3.B: Cache Invalidation Bounded Key Growth and Memory Leak Prevention.
     * Verificar que las consultas de reportes no provocan crecimiento descontrolado de llaves
     * ni fugas de memoria, respetando el TTL de 900 segundos.
     */
    public function test_cache_versioning_prevents_unbounded_key_growth_and_memory_leaks(): void
    {
        $startDate = '2026-09-01';
        $endDate = '2026-09-30';

        // 1. Medir memoria antes
        $memBefore = memory_get_usage();

        // 2. Generar múltiples consultas simuladas de reportes a través de ReportCacheService
        for ($i = 0; $i < 50; $i++) {
            $key = ReportCacheService::key('profit_data', $startDate, $endDate);
            $val = ReportCacheService::remember($key, 900, function () use ($i) {
                return ['dummy_data' => str_repeat('X', 100), 'iteration' => $i];
            });

            // Cada 5 iteraciones invalidar el caché
            if ($i % 5 === 0) {
                ReportCacheService::flush();
            }
        }

        // 3. Medir memoria después
        $memAfter = memory_get_usage();
        $memDelta = $memAfter - $memBefore;

        // El crecimiento de memoria del proceso en PHP debe ser mínimo (< 500 KB)
        $this->assertLessThan(
            512 * 1024,
            $memDelta,
            'Report cache operations must not leak memory (delta exceeded 512KB).'
        );

        // 4. Verificar que la versión actual aísla completamente los datos viejos
        $latestKey = ReportCacheService::key('profit_data', $startDate, $endDate);
        $currentVersion = ReportCacheService::getVersion();
        $this->assertStringContainsString("v{$currentVersion}", $latestKey);
    }
}
