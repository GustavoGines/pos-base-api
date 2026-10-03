<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ChallengerM2BackfillEagerLoadingAdversarialTest
 *
 * Empirical adversarial stress test harness by Challenger 2 for Milestone M2.
 * Focuses on:
 * 1. Data backfill and relationship integrity.
 * 2. Eager loading and nested payload validation (Category -> Rubro, Product -> Category -> Rubro).
 * 3. Strict N+1 query prevention tests via query count invariance assertions.
 * 4. Boundary cases (orphaned categories, null category products, deletion guardrails).
 */
class ChallengerM2BackfillEagerLoadingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);
        $this->actingAsAdmin($this->adminUser);

        // Configuración por defecto: Premium activo para permitir fixtures variados
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => true])]
        );
    }

    /**
     * EMPIRICAL CHECK 1:
     * Verifica que todas las categorías creadas o existentes posean un rubro_id válido
     * y que el modelo Category asigne automáticamente el rubro del sistema si viene nulo.
     */
    public function test_adversarial_categories_always_have_valid_rubro_id_and_no_orphans(): void
    {
        // El sistema ya cuenta con el rubro del sistema creado por la migración
        $systemRubro = Rubro::where('is_system', true)->first();
        if (! $systemRubro) {
            $systemRubro = Rubro::create([
                'name' => 'Ferretería Principal',
                'is_system' => true,
            ]);
        }

        // Crear categorías sin especificar rubro_id explícito a través de Eloquent
        $cat1 = Category::create(['name' => 'Tornillos y Tuercas']);
        $cat2 = Category::create(['name' => 'Herramientas Eléctricas']);

        $this->assertEquals($systemRubro->id, $cat1->rubro_id);
        $this->assertEquals($systemRubro->id, $cat2->rubro_id);

        // Confirmar en base de datos que ninguna fila tiene rubro_id nulo
        $nullCount = DB::table('categories')->whereNull('rubro_id')->count();
        $this->assertSame(0, $nullCount, 'No debe existir ninguna categoría con rubro_id NULL.');

        // Confirmar que todas las categorías apuntan al rubro del sistema
        $pointingToSystemCount = DB::table('categories')->where('rubro_id', $systemRubro->id)->count();
        $this->assertSame(2, $pointingToSystemCount);
    }

    /**
     * EMPIRICAL CHECK 2:
     * GET /api/catalog/categories retorna el objeto anidado 'rubro' para cada categoría.
     */
    public function test_adversarial_get_categories_returns_nested_rubro_object(): void
    {
        $rubroSys = Rubro::create(['name' => 'Ferretería Sistema', 'is_system' => true]);
        $rubroCustom = Rubro::create(['name' => 'Bazar y Hogar', 'is_system' => false]);

        $catSys = Category::create(['name' => 'Bulones', 'rubro_id' => $rubroSys->id]);
        $catCustom = Category::create(['name' => 'Cristalería', 'rubro_id' => $rubroCustom->id]);

        $response = $this->getJson('/api/catalog/categories');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $catSysData = collect($data)->firstWhere('id', $catSys->id);
        $this->assertNotNull($catSysData);
        $this->assertIsArray($catSysData['rubro']);
        $this->assertEquals($rubroSys->id, $catSysData['rubro']['id']);
        $this->assertEquals('Ferretería Sistema', $catSysData['rubro']['name']);
        $this->assertTrue((bool) $catSysData['rubro']['is_system']);

        $catCustomData = collect($data)->firstWhere('id', $catCustom->id);
        $this->assertNotNull($catCustomData);
        $this->assertIsArray($catCustomData['rubro']);
        $this->assertEquals($rubroCustom->id, $catCustomData['rubro']['id']);
        $this->assertEquals('Bazar y Hogar', $catCustomData['rubro']['name']);
        $this->assertFalse((bool) $catCustomData['rubro']['is_system']);
    }

    /**
     * EMPIRICAL CHECK 3:
     * GET /api/catalog/products retorna el objeto anidado 'category.rubro'.
     */
    public function test_adversarial_get_products_returns_nested_category_rubro(): void
    {
        $rubro = Rubro::create(['name' => 'Ferretería Pesada', 'is_system' => true]);
        $cat = Category::create(['name' => 'Maquinaria', 'rubro_id' => $rubro->id]);

        $product = Product::create([
            'name' => 'Hormigonera 130L 3/4 HP',
            'internal_code' => '55001',
            'cost_price' => 120000.00,
            'selling_price' => 195000.00,
            'stock' => 3,
            'category_id' => $cat->id,
        ]);

        $response = $this->getJson('/api/catalog/products');
        $response->assertStatus(200);

        $data = $response->json('data') ?? $response->json();
        $this->assertNotEmpty($data);

        $prodData = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($prodData, 'El producto debe encontrarse en el catálogo.');

        // Verificar nesting category
        $this->assertNotNull($prodData['category'], 'El producto debe incluir category anidada.');
        $this->assertEquals($cat->id, $prodData['category']['id']);
        $this->assertEquals('Maquinaria', $prodData['category']['name']);

        // Verificar nesting category.rubro
        $this->assertNotNull($prodData['category']['rubro'], 'La categoría debe incluir rubro anidado.');
        $this->assertEquals($rubro->id, $prodData['category']['rubro']['id']);
        $this->assertEquals('Ferretería Pesada', $prodData['category']['rubro']['name']);
        $this->assertTrue((bool) $prodData['category']['rubro']['is_system']);

        // Verificar también show individual: GET /api/catalog/products/{id}
        $showRes = $this->getJson("/api/catalog/products/{$product->id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('category.id', $cat->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Ferretería Pesada');
    }

    /**
     * EMPIRICAL CHECK 4 (N+1 STRESS TEST - CATEGORIES):
     * Demuestra empíricamente que el endpoint de categorías NO sufre de N+1 queries.
     * Al aumentar de 5 a 25 categorías, el número de queries a la base de datos debe ser IDÉNTICO.
     */
    public function test_adversarial_categories_endpoint_query_count_is_invariant_no_n_plus_1(): void
    {
        $rubro1 = Rubro::create(['name' => 'Rubro Alpha', 'is_system' => true]);
        $rubro2 = Rubro::create(['name' => 'Rubro Beta', 'is_system' => false]);
        $rubro3 = Rubro::create(['name' => 'Rubro Gamma', 'is_system' => false]);

        // Crear 5 categorías iniciales
        for ($i = 1; $i <= 5; $i++) {
            Category::create([
                'name' => "Cat Invariant {$i}",
                'rubro_id' => ($i % 3 === 0) ? $rubro3->id : (($i % 2 === 0) ? $rubro2->id : $rubro1->id),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res1 = $this->getJson('/api/catalog/categories');
        $res1->assertStatus(200);

        $queriesInitial = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 query para categorías + 1 query eager load para rubros = 2 queries
        $this->assertLessThanOrEqual(2, $queriesInitial, "Categorías debe resolver en <= 2 queries, ejecutó {$queriesInitial}");

        // Ahora escalamos masivamente agregando 25 categorías más (total 30)
        for ($i = 6; $i <= 30; $i++) {
            Category::create([
                'name' => "Cat Invariant {$i}",
                'rubro_id' => ($i % 3 === 0) ? $rubro3->id : (($i % 2 === 0) ? $rubro2->id : $rubro1->id),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res2 = $this->getJson('/api/catalog/categories');
        $res2->assertStatus(200);
        $this->assertCount(30, $res2->json());

        $queriesScaled = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Si hubiera N+1, queriesScaled sería al menos 25 queries mayor que queriesInitial
        $this->assertSame(
            $queriesInitial,
            $queriesScaled,
            "VIOLACIÓN N+1 DETECTADA: Al quintuplicar categorías de 5 a 30, las queries pasaron de {$queriesInitial} a {$queriesScaled}."
        );
    }

    /**
     * EMPIRICAL CHECK 5 (N+1 STRESS TEST - PRODUCTS):
     * Demuestra empíricamente que el endpoint de productos NO sufre de N+1 queries para category.rubro.
     * Al consultar 5 productos vs 25 productos, el número de queries debe ser estrictamente CONSTANTE.
     */
    public function test_adversarial_products_endpoint_query_count_is_invariant_no_n_plus_1(): void
    {
        $rubroA = Rubro::create(['name' => 'Rubro Ferre', 'is_system' => true]);
        $rubroB = Rubro::create(['name' => 'Rubro Bazar', 'is_system' => false]);

        $catA = Category::create(['name' => 'Cat A', 'rubro_id' => $rubroA->id]);
        $catB = Category::create(['name' => 'Cat B', 'rubro_id' => $rubroB->id]);

        $brand = Brand::create(['name' => 'Marca Test']);
        $supplier = Supplier::create(['name' => 'Proveedor Test']);

        // Crear 5 productos
        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'name' => "Producto Invariant {$i}",
                'internal_code' => str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'cost_price' => 100,
                'selling_price' => 200,
                'stock' => 10,
                'category_id' => ($i % 2 === 0) ? $catB->id : $catA->id,
                'brand_id' => $brand->id,
                'supplier_id' => $supplier->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res1 = $this->getJson('/api/catalog/products?per_page=5');
        $res1->assertStatus(200);

        $queriesCount5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Agregar 20 productos más (total 25 productos)
        for ($i = 6; $i <= 25; $i++) {
            Product::create([
                'name' => "Producto Invariant {$i}",
                'internal_code' => str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'cost_price' => 100,
                'selling_price' => 200,
                'stock' => 10,
                'category_id' => ($i % 2 === 0) ? $catB->id : $catA->id,
                'brand_id' => $brand->id,
                'supplier_id' => $supplier->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res2 = $this->getJson('/api/catalog/products?per_page=25');
        $res2->assertStatus(200);

        $queriesCount25 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Verificar que los 25 productos vienen con category y rubro
        $items = $res2->json('data');
        $this->assertCount(25, $items);
        foreach ($items as $item) {
            $this->assertNotNull($item['category']);
            $this->assertNotNull($item['category']['rubro']);
        }

        // Afirmar invariancia de queries
        $this->assertSame(
            $queriesCount5,
            $queriesCount25,
            "VIOLACIÓN N+1 DETECTADA EN PRODUCTOS: De 5 productos ({$queriesCount5} queries) a 25 productos ({$queriesCount25} queries)."
        );
    }

    /**
     * EMPIRICAL CHECK 6 (EDGE CASE: PRODUCT WITHOUT CATEGORY):
     * Verifica que productos con category_id = null no arrojen errores 500
     * y retornen 'category' como null de forma segura.
     */
    public function test_adversarial_product_without_category_handles_null_gracefully(): void
    {
        $productNullCat = Product::create([
            'name' => 'Producto Sin Categoría',
            'internal_code' => '77001',
            'cost_price' => 50,
            'selling_price' => 90,
            'stock' => 10,
            'category_id' => null,
        ]);

        // 1. GET /api/catalog/products
        $resIndex = $this->getJson('/api/catalog/products');
        $resIndex->assertStatus(200);
        $items = $resIndex->json('data') ?? $resIndex->json();
        $found = collect($items)->firstWhere('id', $productNullCat->id);
        $this->assertNotNull($found);
        $this->assertNull($found['category'], 'category debe ser null cuando category_id es null.');

        // 2. GET /api/catalog/products/{id}
        $resShow = $this->getJson("/api/catalog/products/{$productNullCat->id}");
        $resShow->assertStatus(200)
            ->assertJsonPath('category', null);

        // 3. GET /pos/products/search?query=77001
        $resPos = $this->getJson('/api/pos/products/search?query=77001');
        $resPos->assertStatus(200);
        $posItems = $resPos->json();
        $this->assertNotEmpty($posItems);
        $this->assertNull($posItems[0]['category']);
    }

    /**
     * EMPIRICAL CHECK 7 (STRESS DELETION GUARDRAILS):
     * Reafirma los bloqueos de borrado de rubro cuando tiene categorías y cuando es sistema.
     */
    public function test_adversarial_rubro_deletion_guardrails(): void
    {
        $systemRubro = Rubro::create(['name' => 'Sistema Inmune', 'is_system' => true]);
        $customRubro = Rubro::create(['name' => 'Personalizado Bloqueado', 'is_system' => false]);
        Category::create(['name' => 'Cat Hija', 'rubro_id' => $customRubro->id]);

        // Intento de borrar sistema: 422
        $res1 = $this->deleteJson("/api/catalog/rubros/{$systemRubro->id}");
        $res1->assertStatus(422);

        // Intento de borrar con categoría: 422
        $res2 = $this->deleteJson("/api/catalog/rubros/{$customRubro->id}");
        $res2->assertStatus(422);

        // Crear rubro libre y borrar: 200
        $freeRubro = Rubro::create(['name' => 'Libre', 'is_system' => false]);
        $res3 = $this->deleteJson("/api/catalog/rubros/{$freeRubro->id}");
        $res3->assertStatus(200);
    }

    /**
     * EMPIRICAL CHECK 8 (POS SEARCH EAGER LOADING & QUERY INVARIANCE):
     * Verifica que /pos/products/search cargue category.rubro sin N+1.
     */
    public function test_adversarial_pos_search_eager_loads_category_rubro_without_n_plus_1(): void
    {
        $rubro = Rubro::create(['name' => 'Rubro Búsqueda POS', 'is_system' => true]);
        $cat = Category::create(['name' => 'Cat Búsqueda', 'rubro_id' => $rubro->id]);

        // Crear 3 productos que coinciden con el término de búsqueda "Taladro"
        for ($i = 1; $i <= 3; $i++) {
            Product::create([
                'name' => "Taladro Percutor Modelo {$i}",
                'internal_code' => "8800{$i}",
                'cost_price' => 5000,
                'selling_price' => 9500,
                'stock' => 5,
                'category_id' => $cat->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res1 = $this->getJson('/api/pos/products/search?query=Taladro');
        $res1->assertStatus(200);
        $data1 = $res1->json();
        $this->assertCount(3, $data1);
        $queries3 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Verificar nesting en los resultados
        foreach ($data1 as $item) {
            $this->assertNotNull($item['category']);
            $this->assertEquals($cat->id, $item['category']['id']);
            $this->assertNotNull($item['category']['rubro']);
            $this->assertEquals($rubro->id, $item['category']['rubro']['id']);
            $this->assertEquals('Rubro Búsqueda POS', $item['category']['rubro']['name']);
        }

        // Crear 7 productos más (total 10) que coinciden con "Taladro"
        for ($i = 4; $i <= 10; $i++) {
            Product::create([
                'name' => "Taladro Percutor Modelo {$i}",
                'internal_code' => "880{$i}",
                'cost_price' => 5000,
                'selling_price' => 9500,
                'stock' => 5,
                'category_id' => $cat->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res2 = $this->getJson('/api/pos/products/search?query=Taladro');
        $res2->assertStatus(200);
        $data2 = $res2->json();
        $this->assertCount(10, $data2);
        $queries10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queries3,
            $queries10,
            "VIOLACIÓN N+1 EN POS SEARCH: Consultar 3 productos ({$queries3} queries) vs 10 productos ({$queries10} queries)."
        );
    }

    /**
     * EMPIRICAL CHECK 9 (LIVE MYSQL DATABASE DIRECT BACKFILL AUDIT):
     * Conecta a la base de datos MySQL real 'sistema_pos' y comprueba
     * directamente el estado de las tablas rubros y categories post-migración.
     */
    public function test_adversarial_live_mysql_database_rubros_and_categories_backfill(): void
    {
        try {
            config(['database.connections.mysql.database' => 'sistema_pos']);
            DB::purge('mysql');
            $mysql = DB::connection('mysql');
            $mysql->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Conexión a MySQL no disponible en este entorno: ' . $e->getMessage());
        }

        // 1. Verificar existencia de la tabla rubros en MySQL
        $hasRubros = $mysql->getSchemaBuilder()->hasTable('rubros');
        $this->assertTrue($hasRubros, 'La tabla rubros debe existir en MySQL.');

        // 2. Verificar rubro del sistema
        $systemRubros = $mysql->table('rubros')->where('is_system', 1)->get();
        $this->assertNotEmpty($systemRubros, 'Debe existir al menos un rubro con is_system = 1.');
        $defaultRubro = $systemRubros->first();
        $this->assertEquals(1, $defaultRubro->id);
        // El nombre del rubro principal sigue el tipo de negocio de la licencia
        // (SystemRubroSyncService) o una edición manual del cliente, por lo que
        // no se asume un nombre fijo: solo debe existir y no estar vacío.
        $this->assertNotEmpty(trim((string) $defaultRubro->name));

        // 3. Verificar que ninguna categoría tenga rubro_id NULL
        $nullRubroCount = $mysql->table('categories')->whereNull('rubro_id')->count();
        $this->assertSame(0, $nullRubroCount, 'No debe existir ninguna categoría con rubro_id NULL en MySQL.');

        // 4. Verificar que todas las categorías existentes apunten a un rubro existente
        $totalCategories = $mysql->table('categories')->count();
        $this->assertGreaterThan(0, $totalCategories, 'Deben existir categorías preexistentes en MySQL.');

        $validRubroCatsCount = $mysql->table('categories')
            ->whereIn('rubro_id', $mysql->table('rubros')->pluck('id'))
            ->count();
        $this->assertSame(
            $totalCategories,
            $validRubroCatsCount,
            'Todas las categorías en MySQL deben referenciar un rubro válido existente.'
        );

        // 5. Verificar que todas las categorías preexistentes apunten al rubro del sistema (id = 1)
        $systemRubroCatsCount = $mysql->table('categories')
            ->where('rubro_id', $defaultRubro->id)
            ->count();
        $this->assertSame(
            $totalCategories,
            $systemRubroCatsCount,
            'Todas las categorías preexistentes deben haber sido enlazadas al rubro del sistema por defecto.'
        );
    }

    /**
     * EMPIRICAL CHECK 10 (CATEGORY STORE & UPDATE PLAN GATING & EAGER LOADING):
     * Verifica que tanto store como update devuelvan la relación 'rubro' anidada,
     * y que en Plan Básico se impida alterar rubro_id.
     */
    public function test_adversarial_category_store_update_plan_gating_and_eager_loading(): void
    {
        $systemRubro = Rubro::where('is_system', true)->first() ?? Rubro::create(['name' => 'Ferretería Principal', 'is_system' => true]);
        $customRubro = Rubro::create(['name' => 'Rubro Básico Ignorado', 'is_system' => false]);

        // PLAN BÁSICO:
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => false])]
        );

        // Store en Plan Básico: envía custom rubro_id pero backend debe forzar el rubro del sistema
        $resStoreBasic = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Básica Blindada',
            'rubro_id' => $customRubro->id,
        ]);
        $resStoreBasic->assertStatus(201)
            ->assertJsonPath('rubro_id', $systemRubro->id)
            ->assertJsonPath('rubro.id', $systemRubro->id)
            ->assertJsonPath('rubro.is_system', true);

        $catId = $resStoreBasic->json('id');

        // Update en Plan Básico: intenta mover a customRubro, backend debe descartar rubro_id
        $resUpdateBasic = $this->putJson("/api/catalog/categories/{$catId}", [
            'name' => 'Categoría Básica Renombrada',
            'rubro_id' => $customRubro->id,
        ]);
        $resUpdateBasic->assertStatus(200)
            ->assertJsonPath('rubro_id', $systemRubro->id)
            ->assertJsonPath('rubro.id', $systemRubro->id);

        // PLAN PREMIUM:
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => true])]
        );

        // Update en Plan Premium: reasignación permitida
        $resUpdatePremium = $this->putJson("/api/catalog/categories/{$catId}", [
            'rubro_id' => $customRubro->id,
        ]);
        $resUpdatePremium->assertStatus(200)
            ->assertJsonPath('rubro_id', $customRubro->id)
            ->assertJsonPath('rubro.id', $customRubro->id)
            ->assertJsonPath('rubro.name', 'Rubro Básico Ignorado');
    }

    /**
     * EMPIRICAL CHECK 11 (PRODUCT LIFECYCLE EAGER LOADS CATEGORY.RUBRO):
     * Verifica que store y update en ProductController devuelvan category.rubro anidado.
     */
    public function test_adversarial_product_store_and_update_return_nested_category_rubro(): void
    {
        $rubro = Rubro::create(['name' => 'Rubro Lifecycle', 'is_system' => false]);
        $cat = Category::create(['name' => 'Cat Lifecycle', 'rubro_id' => $rubro->id]);

        // 1. POST /api/catalog/products
        $resStore = $this->postJson('/api/catalog/products', [
            'name' => 'Producto Lifecycle Test',
            'cost_price' => 500,
            'selling_price' => 1000,
            'stock' => 10,
            'category_id' => $cat->id,
        ]);
        $resStore->assertStatus(201)
            ->assertJsonPath('category.id', $cat->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Rubro Lifecycle');

        $prodId = $resStore->json('id');

        // 2. PUT /api/catalog/products/{id}
        $resUpdate = $this->putJson("/api/catalog/products/{$prodId}", [
            'name' => 'Producto Lifecycle Modificado',
            'cost_price' => 500,
            'selling_price' => 1200,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('name', 'Producto Lifecycle Modificado')
            ->assertJsonPath('category.id', $cat->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Rubro Lifecycle');
    }
}
