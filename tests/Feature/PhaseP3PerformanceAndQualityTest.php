<?php

namespace Tests\Feature;

use App\Models\BulkPriceHistory;
use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\LicenseSyncService;
use App\Services\ReportCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseP3PerformanceAndQualityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        // Habilitar características requeridas para pruebas de administración
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
     * P3.1: Verificar que los índices de alto impacto existen en la base de datos.
     */
    public function test_p3_1_database_high_impact_indexes_exist(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sales', ['created_at', 'status']),
            'Missing composite index [created_at, status] on sales table'
        );

        $this->assertTrue(
            Schema::hasIndex('customer_transactions', ['created_at', 'type']),
            'Missing composite index [created_at, type] on customer_transactions table'
        );

        $this->assertTrue(
            Schema::hasIndex('cash_movements', ['created_at', 'type']),
            'Missing composite index [created_at, type] on cash_movements table'
        );

        $this->assertTrue(
            Schema::hasIndex('stock_movements', ['created_at']),
            'Missing single index [created_at] on stock_movements table'
        );

        // Adicionalmente verificar que la restricción unique a nivel de tabla en expense_categories fue removida
        $this->assertFalse(
            Schema::hasIndex('expense_categories', ['name'], 'unique'),
            'Table-level unique constraint on expense_categories(name) must be dropped to allow soft-delete reuse'
        );
    }

    /**
     * P3.2: Verificar invalidación del caché de reportes tras la creación y anulación de ventas.
     */
    public function test_p3_2_report_cache_invalidated_on_sale_creation_and_void(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $cashMethod = $this->crearMetodoEfectivo();

        $category = Category::create(['name' => 'Herramientas P3']);
        $product = Product::create([
            'name' => 'Destornillador P3',
            'internal_code' => 'DES-P3',
            'selling_price' => 150.00,
            'cost_price' => 50.00,
            'category_id' => $category->id,
            'stock' => 50,
            'active' => true,
        ]);

        $startDate = now()->startOfMonth()->toDateString();
        $endDate = now()->endOfMonth()->toDateString();

        // 1. Primera consulta: Cache inicial sin ventas
        $initialRes = $this->getJson("/api/reports/sales-by-category?start_date={$startDate}&end_date={$endDate}");
        $initialRes->assertStatus(200);

        $initialCategoryData = collect($initialRes->json('data'))->firstWhere('category_name', $category->name);
        $this->assertNull($initialCategoryData, 'La categoría no debe tener ventas antes de procesar una venta.');

        $v1 = ReportCacheService::getVersion();

        // 2. Procesar venta por $150
        $salePayload = [
            'total' => 150.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $cashMethod->id,
                'base_amount' => 150.00,
                'surcharge_amount' => 0,
                'total_amount' => 150.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 150.00,
                'subtotal' => 150.00,
            ]],
        ];

        $saleResponse = $this->postJson('/api/pos/sales', $salePayload);
        $saleResponse->assertStatus(201);
        $saleId = $saleResponse->json('data.id') ?? Sale::latest('id')->first()->id;

        // Versión del caché debió incrementarse tras la venta
        $v2 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v1, $v2, 'Report cache version must increment after sale creation.');

        // 3. Segunda consulta: Debe reflejar inmediatamente la nueva venta (sin esperar 15 minutos de TTL)
        $secondRes = $this->getJson("/api/reports/sales-by-category?start_date={$startDate}&end_date={$endDate}");
        $secondRes->assertStatus(200);

        $updatedCategoryData = collect($secondRes->json('data'))->firstWhere('category_name', $category->name);
        $this->assertNotNull($updatedCategoryData, 'La categoría debe figurar inmediatamente en el reporte.');
        $this->assertEquals(150.00, (float) $updatedCategoryData['total_revenue']);
        $this->assertEquals(100.00, (float) $updatedCategoryData['total_profit']); // 150 - 50 = 100

        // 4. Anular la venta
        $voidRes = $this->postJson("/api/sales/{$saleId}/void", [
            'cash_shift_id' => $shift->id,
        ]);
        $voidRes->assertStatus(200);

        // Versión del caché debió incrementarse nuevamente tras la anulación
        $v3 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v2, $v3, 'Report cache version must increment after sale voiding.');

        // 5. Tercera consulta: El reporte debe reflejar la reversión inmediatamente
        $thirdRes = $this->getJson("/api/reports/sales-by-category?start_date={$startDate}&end_date={$endDate}");
        $thirdRes->assertStatus(200);

        $revertedCategoryData = collect($thirdRes->json('data'))->firstWhere('category_name', $category->name);
        $this->assertTrue(
            $revertedCategoryData === null || (float) ($revertedCategoryData['total_revenue'] ?? 0) === 0.0,
            'El reporte debe reflejar la anulación de la venta sin stale cache.'
        );
    }

    /**
     * P3.2: Verificar invalidación del caché de balance mensual al registrar y eliminar egresos de caja.
     */
    public function test_p3_2_report_cache_invalidated_on_cash_movement(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        $currentMonth = now()->format('Y-m');

        // Crear cliente normal y venta para tener balance base en el mes
        $customer = Customer::create([
            'name' => 'Cliente Balance P3',
            'document_number' => '30303030',
            'is_internal_account' => false,
        ]);

        $product = Product::create([
            'name' => 'Producto Balance P3',
            'internal_code' => 'BAL-P3',
            'selling_price' => 200.00,
            'cost_price' => 80.00,
            'stock' => 50,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 200.00,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 200.00,
            'unit_cost_price' => 80.00,
            'subtotal' => 200.00,
        ]);

        // 1. Consulta inicial: Balance mensual con ganancia bruta de $120 ($200 - $80) y 0 egresos
        $res1 = $this->getJson("/api/reports/monthly-balance?start_month={$currentMonth}&end_month={$currentMonth}");
        $res1->assertStatus(200);

        $monthData1 = collect($res1->json('months'))->firstWhere('period', $currentMonth);
        $this->assertNotNull($monthData1);
        $this->assertEquals(120.00, (float) $monthData1['total_profit']);

        $v1 = ReportCacheService::getVersion();

        // 2. Registrar egreso de caja por $30.00
        $movementRes = $this->postJson('/api/cash-movements', [
            'type' => 'expense',
            'description' => 'Gasto de papelería P3',
            'category' => 'libreria',
            'payments' => [
                [
                    'amount' => 30.00,
                    'payment_method' => 'cash',
                ],
            ],
        ]);
        $movementRes->assertStatus(201);
        $movementId = $movementRes->json('movements.0.id');
        $this->assertNotNull($movementId);

        // Verificar que la versión de caché se actualizó
        $v2 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v1, $v2, 'Cache version must advance when expense movement is created.');

        // 3. Segunda consulta: Ganancia debe ser $90 ($120 - $30) inmediatamente
        $res2 = $this->getJson("/api/reports/monthly-balance?start_month={$currentMonth}&end_month={$currentMonth}");
        $res2->assertStatus(200);

        $monthData2 = collect($res2->json('months'))->firstWhere('period', $currentMonth);
        $this->assertEquals(90.00, (float) $monthData2['total_profit'], 'Monthly profit must immediately reflect expense movement.');

        // 4. Anular/eliminar el egreso de caja
        $delRes = $this->deleteJson("/api/cash-movements/{$movementId}");
        $delRes->assertStatus(200);

        // Versión debe avanzar nuevamente
        $v3 = ReportCacheService::getVersion();
        $this->assertGreaterThan($v2, $v3, 'Cache version must advance when expense movement is deleted.');

        // 5. Tercera consulta: Ganancia vuelve a $120 inmediatamente
        $res3 = $this->getJson("/api/reports/monthly-balance?start_month={$currentMonth}&end_month={$currentMonth}");
        $res3->assertStatus(200);

        $monthData3 = collect($res3->json('months'))->firstWhere('period', $currentMonth);
        $this->assertEquals(120.00, (float) $monthData3['total_profit'], 'Monthly profit must immediately revert upon expense deletion.');
    }

    /**
     * P3.2: Verificar resiliencia del ReportCacheService en drivers sin tags y avance de versión.
     */
    public function test_p3_2_report_cache_driver_resilience(): void
    {
        $initialVersion = ReportCacheService::getVersion();
        $this->assertGreaterThanOrEqual(1, $initialVersion);

        // Flushes sucesivos
        ReportCacheService::flush();
        $v2 = ReportCacheService::getVersion();
        $this->assertEquals($initialVersion + 1, $v2);

        // Generación de llaves versionadas
        $keyA = ReportCacheService::key('profit_data', '2026-01-01', '2026-01-31');
        $this->assertEquals("profit_data_v{$v2}_2026-01-01_2026-01-31", $keyA);

        $keyB = ReportCacheService::key('global');
        $this->assertEquals("global_v{$v2}", $keyB);

        // Uso seguro de remember() sin BadMethodCallException
        $executed = false;
        $cachedValue = ReportCacheService::remember($keyA, 300, function () use (&$executed) {
            $executed = true;

            return ['status' => 'fresh', 'items' => [1, 2, 3]];
        });

        $this->assertTrue($executed);
        $this->assertEquals(['status' => 'fresh', 'items' => [1, 2, 3]], $cachedValue);

        // Segundo remember sobre la misma clave debe ser instantáneo
        $executedAgain = false;
        $cachedValue2 = ReportCacheService::remember($keyA, 300, function () use (&$executedAgain) {
            $executedAgain = true;

            return ['status' => 'stale'];
        });

        $this->assertFalse($executedAgain);
        $this->assertEquals($cachedValue, $cachedValue2);

        // Flush invalida la clave por cambio de versión
        ReportCacheService::flush();
        $v3 = ReportCacheService::getVersion();
        $this->assertEquals($v2 + 1, $v3);

        $keyAAfterFlush = ReportCacheService::key('profit_data', '2026-01-01', '2026-01-31');
        $this->assertNotEquals($keyA, $keyAAfterFlush);
    }

    /**
     * P3.3: Verificar que el revert de aumento masivo de catálogo elimina las consultas N+1.
     */
    public function test_p3_3_bulk_price_revert_eliminates_n_plus_one_queries(): void
    {
        // Crear 20 productos con precio inicial $100 y costo $50
        $productIds = [];
        for ($i = 1; $i <= 20; $i++) {
            $prod = Product::create([
                'name' => "Batch Product {$i}",
                'internal_code' => "BPROD-{$i}",
                'cost_price' => 50.00,
                'selling_price' => 100.00,
                'stock' => 10,
                'active' => true,
            ]);
            $productIds[] = $prod->id;
        }

        // Aplicar aumento masivo del 25% sobre selling_price
        $updateRes = $this->putJson('/api/catalog/products/bulk-price-update', [
            'percentage' => 25,
            'product_ids' => $productIds,
            'target_field' => 'selling_price',
            'rounding_rule' => 'none',
        ]);
        $updateRes->assertStatus(200);

        // Confirmar que los productos subieron a $125
        $this->assertEquals(125.00, (float) Product::find($productIds[0])->selling_price);

        $history = BulkPriceHistory::latest('id')->first();
        $this->assertNotNull($history);

        // Monitorear consultas durante el revert
        DB::flushQueryLog();
        DB::enableQueryLog();

        $revertRes = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $revertRes->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Filtrar consultas UPDATE sobre products
        $productUpdateQueries = array_filter($queries, function ($q) {
            return preg_match('/update\s+["`]?products["`]?/i', $q['query']);
        });

        // Debe ejecutarse en a lo sumo 2 consultas UPDATE vectorizadas (1 chunk de 500), NUNCA 20 individuales
        $this->assertLessThanOrEqual(
            2,
            count($productUpdateQueries),
            'Bulk price revert must execute in <= 2 batched UPDATE queries instead of N individual queries.'
        );

        // Verificar que todos los 20 productos regresaron al precio original de $100.00
        $updatedProducts = Product::whereIn('id', $productIds)->get();
        foreach ($updatedProducts as $p) {
            $this->assertEquals(100.00, (float) $p->selling_price, "Product {$p->id} was not reverted correctly.");
        }

        // Historial marcado como revertido
        $this->assertTrue((bool) $history->fresh()->reverted);
    }

    /**
     * P3.4: Verificar que una caja registradora soft-deleted permite reutilizar su nombre, mientras que activa devuelve 422.
     */
    public function test_p3_4_cash_register_allows_reusing_name_of_soft_deleted_register(): void
    {
        // Asegurar que la Caja Principal (id=1, inmutable) exista para que la caja de prueba sea id>=2
        CashRegister::firstOrCreate(['id' => 1], ['name' => 'Caja Principal', 'is_active' => true]);

        $registerName = 'Caja Mostrador P3 Test';

        // 1. Crear caja
        $res1 = $this->postJson('/api/registers', ['name' => $registerName]);
        $res1->assertStatus(201);
        $registerId = $res1->json('id');

        // 2. Intentar duplicar nombre con caja activa -> 422
        $resDup = $this->postJson('/api/registers', ['name' => $registerName]);
        $resDup->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // 3. Vincular un turno para forzar soft-delete
        CashShift::create([
            'cash_register_id' => $registerId,
            'user_id' => $this->admin->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        // 4. Eliminar caja -> Soft delete aplicado
        $delRes = $this->deleteJson("/api/registers/{$registerId}");
        $delRes->assertStatus(200);
        $this->assertSoftDeleted('cash_registers', ['id' => $registerId]);

        // 5. Re-crear caja con el mismo nombre -> 201 Created
        $resRecreate = $this->postJson('/api/registers', ['name' => $registerName]);
        $resRecreate->assertStatus(201);
        $newRegisterId = $resRecreate->json('id');
        $this->assertNotEquals($registerId, $newRegisterId);

        // 6. Nuevo intento de duplicado activo -> 422
        $resDupActive = $this->postJson('/api/registers', ['name' => $registerName]);
        $resDupActive->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * P3.4: Verificar que una categoría de gasto soft-deleted permite reutilizar su nombre, mientras que activa devuelve 422.
     */
    public function test_p3_4_expense_category_allows_reusing_name_of_soft_deleted_category(): void
    {
        $categoryName = 'Servicios Cloud P3';

        // 1. Crear categoría
        $res1 = $this->postJson('/api/expense-categories', ['name' => $categoryName]);
        $res1->assertStatus(201);
        $categoryId = $res1->json('id');

        // 2. Intentar duplicar nombre activo -> 422
        $resDup = $this->postJson('/api/expense-categories', ['name' => $categoryName]);
        $resDup->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // 3. Crear movimiento vinculado para forzar soft-delete
        $shift = $this->crearTurnoAbierto(user: $this->admin);
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 10.00,
            'expense_category_id' => $categoryId,
            'description' => 'Movimiento para evitar force-delete',
        ]);

        // 4. Eliminar categoría -> Soft delete
        $delRes = $this->deleteJson("/api/expense-categories/{$categoryId}");
        $delRes->assertStatus(200);
        $this->assertSoftDeleted('expense_categories', ['id' => $categoryId]);

        // 5. Re-crear categoría con el mismo nombre -> 201 Created (no viola unique en DB ni validador)
        $resRecreate = $this->postJson('/api/expense-categories', ['name' => $categoryName]);
        $resRecreate->assertStatus(201);
        $newCategoryId = $resRecreate->json('id');
        $this->assertNotEquals($categoryId, $newCategoryId);

        // 6. Intentar crear nuevamente categoría activa -> 422
        $resDupActive = $this->postJson('/api/expense-categories', ['name' => $categoryName]);
        $resDupActive->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * P3.5: Verificar que LicenseSyncService no tiene rutas absolutas a Laragon y usa storage_path().
     */
    public function test_p3_5_license_sync_service_error_handling_is_portable(): void
    {
        // 1. Verificación estática del archivo fuente
        $serviceFilePath = app_path('Services/LicenseSyncService.php');
        $this->assertFileExists($serviceFilePath);

        $sourceCode = file_get_contents($serviceFilePath);
        $this->assertStringNotContainsString(
            'C:\laragon',
            $sourceCode,
            'Hardcoded path C:\laragon must not exist in LicenseSyncService.php'
        );
        $this->assertStringNotContainsString(
            'c:\laragon',
            strtolower($sourceCode),
            'Case-insensitive hardcoded laragon path must not exist in LicenseSyncService.php'
        );

        // 2. Verificación de ejecución dinámica ante HTTP 500
        $laragonArtifactPath = 'C:\\laragon\\www\\error_body.html';
        $portableStoragePath = storage_path('logs/license_sync_error.html');

        if (file_exists($laragonArtifactPath)) {
            @unlink($laragonArtifactPath);
        }
        if (file_exists($portableStoragePath)) {
            @unlink($portableStoragePath);
        }

        Http::fake([
            '*' => Http::response('<html><body>Fatal 500 Server Error Body</body></html>', 500),
        ]);

        $licenseService = app(LicenseSyncService::class);

        try {
            $licenseService->activateManual('TEST-PORTABLE-KEY-XYZ');
            $this->fail('activateManual should throw an exception on HTTP 500 error response.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        // El archivo nunca debe escribirse en C:\laragon\www\error_body.html
        $this->assertFileDoesNotExist(
            $laragonArtifactPath,
            'No file should ever be created at C:\laragon\www\error_body.html'
        );

        // El archivo debe escribirse en la ruta portable storage_path('logs/license_sync_error.html')
        $this->assertFileExists(
            $portableStoragePath,
            'Error payload should be written to storage_path logs directory.'
        );
        $this->assertStringContainsString(
            'Fatal 500 Server Error Body',
            file_get_contents($portableStoragePath)
        );

        // Limpiar archivo temporal generado
        @unlink($portableStoragePath);
    }
}
