<?php

namespace Tests\Feature\E2E;

use App\Constants\Permissions;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RubroHierarchyE2ETest
 *
 * Opaque-box End-to-End Test Suite for Hierarchical Rubros and Categories Architecture.
 *
 * Structure:
 * - Tier 1: Category-Partition (Feature Coverage: CRUD, backfill, basic plan defaults, premium custom rubros, single category product)
 * - Tier 2: Boundary Value Analysis (BVA & Guardrails: empty strings, length limits, duplicates, system rubro protection, category integrity, FK validation)
 * - Tier 3: Combinatorial & Cross-Feature Interactions (Basic vs Premium feature gating, cashier RBAC, category re-parenting across rubros, multi-rubro product queries)
 * - Tier 4: Real-World Workload & Business Scenarios (Complete store lifecycle: license bootstrap -> rubro expansion -> category taxonomy -> product setup -> POS search -> cleanup guardrails)
 */
class RubroHierarchyE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario administrador con token de sesión activo
        $this->adminUser = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->actingAsAdmin($this->adminUser);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS DE CONFIGURACIÓN DE LICENCIA Y MODELOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Configura el plan de la licencia (Basic vs Premium multi_rubro).
     */
    protected function setMultiRubroPlan(bool $enabled): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => $enabled])]
        );
    }

    /**
     * Configura el tipo de negocio en la licencia.
     */
    protected function setLicenseBusinessType(string $businessType): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_business_type'],
            ['value' => $businessType]
        );
    }

    /**
     * Obtiene o crea el rubro del sistema por defecto.
     */
    protected function ensureSystemRubro(string $name = 'Ferretería'): int
    {
        if (!Schema::hasTable('rubros')) {
            $this->markTestSkipped('Tabla rubros aún no existe en la base de datos (pendiente migración M2).');
        }

        $systemRubro = DB::table('rubros')->where('is_system', true)->first();

        if ($systemRubro) {
            return $systemRubro->id;
        }

        return (int) DB::table('rubros')->insertGetId([
            'name' => $name,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea un usuario cajero sin permisos de administración de catálogo.
     */
    protected function actingAsCashierWithoutCatalogPermission(): User
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => json_encode([]),
            'pin' => Hash::make('5678'),
        ]);

        $token = 'test-cashier-token-' . uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $cashier;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TIER 1: FEATURE COVERAGE (CATEGORY-PARTITION)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Tier 1.1: Ciclo de vida CRUD completo de Rubro bajo Plan Premium.
     */
    public function test_tier1_rubro_crud_lifecycle_premium(): void
    {
        $this->setMultiRubroPlan(true);

        // 1. CREATE: Crear un nuevo rubro
        $createRes = $this->postJson('/api/catalog/rubros', [
            'name' => 'Pinturas y Barnices',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('name', 'Pinturas y Barnices')
            ->assertJsonPath('is_system', false);

        $rubroId = $createRes->json('id');
        $this->assertNotNull($rubroId, 'El ID del rubro creado no debe ser nulo.');
        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'name' => 'Pinturas y Barnices',
            'is_system' => 0,
        ]);

        // 2. READ ALL: Listar rubros
        $listRes = $this->getJson('/api/catalog/rubros');
        $listRes->assertStatus(200)
            ->assertJsonFragment(['name' => 'Pinturas y Barnices']);

        // 3. READ SINGLE: Ver rubro específico
        $showRes = $this->getJson("/api/catalog/rubros/{$rubroId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('id', $rubroId)
            ->assertJsonPath('name', 'Pinturas y Barnices');

        // 4. UPDATE: Modificar nombre del rubro
        $updateRes = $this->putJson("/api/catalog/rubros/{$rubroId}", [
            'name' => 'Pinturería y Revestimientos',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('name', 'Pinturería y Revestimientos');

        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'name' => 'Pinturería y Revestimientos',
        ]);

        // 5. DELETE: Eliminar rubro vacío (sin categorías)
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$rubroId}");
        $this->assertTrue(
            in_array($deleteRes->status(), [200, 204], true),
            "DELETE retornó código inesperado: {$deleteRes->status()}"
        );

        $this->assertDatabaseMissing('rubros', ['id' => $rubroId]);
    }

    /**
     * Tier 1.2: Verificación del Backfill de Rubro Principal por tipo de negocio.
     */
    public function test_tier1_business_type_backfill_verification(): void
    {
        $this->setLicenseBusinessType('hardware_store');
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // Crear una categoría previa sin rubro_id asignado directamente en BD si la columna lo permite
        if (Schema::hasColumn('categories', 'rubro_id')) {
            $catId = DB::table('categories')->insertGetId([
                'name' => 'Herramientas Preexistentes',
                'description' => 'Categoría previa al sistema de rubros',
                'rubro_id' => $systemRubroId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $cat = Category::create([
                'name' => 'Herramientas Preexistentes',
                'description' => 'Categoría previa',
            ]);
            $catId = $cat->id;
        }

        // Consultar listado de categorías vía API
        $categoriesRes = $this->getJson('/api/catalog/categories');
        $categoriesRes->assertStatus(200);

        // Verificar que la respuesta contiene el rubro por defecto
        $categories = $categoriesRes->json();
        $this->assertIsArray($categories);
        $found = false;
        foreach ($categories as $c) {
            if ($c['id'] === $catId) {
                $found = true;
                $this->assertEquals($systemRubroId, $c['rubro_id'] ?? null);
                $this->assertNotNull($c['rubro'] ?? null, 'La categoría debe incluir el objeto rubro padre.');
                $this->assertEquals(true, (bool) ($c['rubro']['is_system'] ?? false));
            }
        }
        $this->assertTrue($found, 'La categoría preexistente debe aparecer en el listado.');
    }

    /**
     * Tier 1.3: Plan Básico auto-asigna el Rubro Principal del sistema a nuevas categorías.
     */
    public function test_tier1_basic_plan_auto_assignment_on_category_creation(): void
    {
        $this->setMultiRubroPlan(false);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // Caso A: Creación de categoría sin especificar rubro_id
        $createResA = $this->postJson('/api/catalog/categories', [
            'name' => 'Tornillería Básica',
            'description' => 'Tornillos y arandelas',
        ]);

        $createResA->assertStatus(201)
            ->assertJsonPath('name', 'Tornillería Básica')
            ->assertJsonPath('rubro_id', $systemRubroId);

        $this->assertDatabaseHas('categories', [
            'name' => 'Tornillería Básica',
            'rubro_id' => $systemRubroId,
        ]);

        // Caso B: Intento malicioso de enviar un rubro_id alternativo bajo Plan Básico
        // El backend debe forzar la asignación al rubro principal del sistema
        $createResB = $this->postJson('/api/catalog/categories', [
            'name' => 'Bulones Especiales',
            'rubro_id' => 9999, // ID arbitrario
        ]);

        $createResB->assertStatus(201)
            ->assertJsonPath('name', 'Bulones Especiales')
            ->assertJsonPath('rubro_id', $systemRubroId);

        $this->assertDatabaseHas('categories', [
            'name' => 'Bulones Especiales',
            'rubro_id' => $systemRubroId,
        ]);
    }

    /**
     * Tier 1.4: Plan Premium permite asignar categorías a rubros personalizados.
     */
    public function test_tier1_premium_plan_rubro_assignment_on_category_creation(): void
    {
        $this->setMultiRubroPlan(true);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // Crear rubro personalizado "Electricidad e Iluminación"
        $rubroRes = $this->postJson('/api/catalog/rubros', [
            'name' => 'Electricidad e Iluminación',
        ]);
        $rubroRes->assertStatus(201);
        $customRubroId = $rubroRes->json('id');

        // Crear categoría vinculada al rubro personalizado
        $categoryRes = $this->postJson('/api/catalog/categories', [
            'name' => 'Cables y Conductores',
            'description' => 'Cables unipolares y subterráneos',
            'rubro_id' => $customRubroId,
        ]);

        $categoryRes->assertStatus(201)
            ->assertJsonPath('name', 'Cables y Conductores')
            ->assertJsonPath('rubro_id', $customRubroId);

        $this->assertDatabaseHas('categories', [
            'name' => 'Cables y Conductores',
            'rubro_id' => $customRubroId,
        ]);

        // Crear otra categoría omitiendo rubro_id: debe asociarse al rubro por defecto del sistema
        $categoryDefaultRes = $this->postJson('/api/catalog/categories', [
            'name' => 'Varios Sin Rubro Específico',
        ]);

        $categoryDefaultRes->assertStatus(201)
            ->assertJsonPath('rubro_id', $systemRubroId);
    }

    /**
     * Tier 1.5: Creación de Producto con categoría única (1:N restablecido).
     */
    public function test_tier1_product_creation_with_single_category(): void
    {
        $this->setMultiRubroPlan(true);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        $categoryRes = $this->postJson('/api/catalog/categories', [
            'name' => 'Herramientas Manuales',
            'rubro_id' => $systemRubroId,
        ]);
        $categoryRes->assertStatus(201);
        $categoryId = $categoryRes->json('id');

        // Crear producto usando el contrato de categoría única (F3)
        $productRes = $this->postJson('/api/catalog/products', [
            'name' => 'Martillo Galponero 500g',
            'cost_price' => 2500.00,
            'selling_price' => 4500.00,
            'stock' => 20,
            'category_id' => $categoryId,
        ]);

        $productRes->assertStatus(201)
            ->assertJsonPath('name', 'Martillo Galponero 500g')
            ->assertJsonPath('category_id', $categoryId);

        $productId = $productRes->json('id');
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Martillo Galponero 500g',
            'category_id' => $categoryId,
        ]);

        // Consultar producto vía GET para verificar eager-load de category y rubro
        $getRes = $this->getJson("/api/catalog/products/{$productId}");
        $getRes->assertStatus(200)
            ->assertJsonPath('category.id', $categoryId)
            ->assertJsonPath('category.rubro.id', $systemRubroId);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TIER 2: BOUNDARY VALUE ANALYSIS (BVA) & CORNER CASES
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Tier 2.1: Validación de nombres vacíos y solo espacios en blanco.
     */
    public function test_tier2_empty_and_whitespace_names_rejected(): void
    {
        $this->setMultiRubroPlan(true);

        // Rubro con nombre vacío
        $this->postJson('/api/catalog/rubros', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Rubro con espacios en blanco únicamente
        $this->postJson('/api/catalog/rubros', ['name' => '     '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Categoría con nombre vacío
        $this->postJson('/api/catalog/categories', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Categoría con espacios en blanco únicamente
        $this->postJson('/api/catalog/categories', ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Tier 2.2: Fronteras de longitud máxima (255 caracteres vs 256 caracteres).
     */
    public function test_tier2_string_length_boundaries(): void
    {
        $this->setMultiRubroPlan(true);

        $name255 = str_repeat('R', 255);
        $name256 = str_repeat('R', 256);

        // 255 caracteres debe ser aceptado
        $res255 = $this->postJson('/api/catalog/rubros', ['name' => $name255]);
        $res255->assertStatus(201);
        $this->assertDatabaseHas('rubros', ['name' => $name255]);

        // 256 caracteres debe ser rechazado con 422
        $res256 = $this->postJson('/api/catalog/rubros', ['name' => $name256]);
        $res256->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Tier 2.3: Rechazo de nombres de rubros duplicados (Unicidad en creación y actualización).
     */
    public function test_tier2_duplicate_rubro_name_rejected(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear primer rubro "Bulonería"
        $this->postJson('/api/catalog/rubros', ['name' => 'Bulonería'])
            ->assertStatus(201);

        // Intento de crear rubro con el mismo nombre exacto
        $this->postJson('/api/catalog/rubros', ['name' => 'Bulonería'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Crear un segundo rubro "Sanitarios"
        $sanitariosRes = $this->postJson('/api/catalog/rubros', ['name' => 'Sanitarios']);
        $sanitariosRes->assertStatus(201);
        $sanitariosId = $sanitariosRes->json('id');

        // Actualizar "Sanitarios" con el nombre existente "Bulonería": debe fallar
        $this->putJson("/api/catalog/rubros/{$sanitariosId}", ['name' => 'Bulonería'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Actualizar "Sanitarios" conservando su propio nombre: debe ser exitoso
        $this->putJson("/api/catalog/rubros/{$sanitariosId}", ['name' => 'Sanitarios'])
            ->assertStatus(200);
    }

    /**
     * Tier 2.4: Bloqueo de eliminación del Rubro Principal del sistema (Guardrail is_system).
     */
    public function test_tier2_cannot_delete_default_system_rubro(): void
    {
        $this->setMultiRubroPlan(true);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // Intento de eliminar el rubro del sistema
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$systemRubroId}");

        $deleteRes->assertStatus(422)
            ->assertJsonPath('message', fn($msg) => str_contains(strtolower($msg), 'sistema') || str_contains(strtolower($msg), 'defecto'));

        // El rubro del sistema debe seguir existiendo en la base de datos
        $this->assertDatabaseHas('rubros', [
            'id' => $systemRubroId,
            'is_system' => 1,
        ]);
    }

    /**
     * Tier 2.5: Bloqueo de eliminación de Rubro con categorías asociadas (Guardrail de integridad referencial).
     */
    public function test_tier2_cannot_delete_rubro_with_attached_categories(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear rubro no-sistema
        $rubroRes = $this->postJson('/api/catalog/rubros', ['name' => 'Plomería y Gas']);
        $rubroRes->assertStatus(201);
        $rubroId = $rubroRes->json('id');

        // Asociar una categoría al rubro
        $this->postJson('/api/catalog/categories', [
            'name' => 'Termofusión',
            'rubro_id' => $rubroId,
        ])->assertStatus(201);

        // Intentar eliminar el rubro teniendo categoría asignada
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$rubroId}");

        $deleteRes->assertStatus(422)
            ->assertJsonPath('message', fn($msg) => str_contains(strtolower($msg), 'categoría') || str_contains(strtolower($msg), 'categoria'));

        // El rubro debe permanecer intacto en la base de datos
        $this->assertDatabaseHas('rubros', ['id' => $rubroId]);
    }

    /**
     * Tier 2.6: Validación de foreign key inválida en rubro_id.
     */
    public function test_tier2_rejects_invalid_foreign_key_rubro_id(): void
    {
        $this->setMultiRubroPlan(true);

        // rubro_id inexistente
        $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Huérfana',
            'rubro_id' => 999999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);

        // rubro_id negativo
        $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Negativa',
            'rubro_id' => -1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TIER 3: CROSS-FEATURE COMBINATIONS & PAIRWISE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Tier 3.1: Plan Básico deniega mutaciones de rubros (403 Forbidden).
     */
    public function test_tier3_basic_plan_forbidden_from_rubro_mutations(): void
    {
        $this->setMultiRubroPlan(false);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // POST /api/catalog/rubros -> 403
        $createRes = $this->postJson('/api/catalog/rubros', ['name' => 'Rubro Básico Ilegal']);
        $createRes->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // PUT /api/catalog/rubros/{id} -> 403
        $updateRes = $this->putJson("/api/catalog/rubros/{$systemRubroId}", ['name' => 'Intento Modificar']);
        $updateRes->assertStatus(403);

        // DELETE /api/catalog/rubros/{id} -> 403
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$systemRubroId}");
        $deleteRes->assertStatus(403);

        // Sin embargo, GET /api/catalog/rubros debe responder 200 para permitir visualizar el rubro del sistema
        $listRes = $this->getJson('/api/catalog/rubros');
        $listRes->assertStatus(200);
    }

    /**
     * Tier 3.2: Usuario Cajero sin permiso MANAGE_CATALOG es bloqueado incluso en Plan Premium.
     */
    public function test_tier3_cashier_role_blocked_from_rubro_mutations_without_permission(): void
    {
        $this->setMultiRubroPlan(true);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // Autenticar como cajero sin permisos de catálogo
        $this->actingAsCashierWithoutCatalogPermission();

        // POST debe fallar con 403 por falta de permiso
        $this->postJson('/api/catalog/rubros', ['name' => 'Rubro Cajero'])
            ->assertStatus(403);

        // PUT debe fallar con 403
        $this->putJson("/api/catalog/rubros/{$systemRubroId}", ['name' => 'Rubro Cajero'])
            ->assertStatus(403);

        // DELETE debe fallar con 403
        $this->deleteJson("/api/catalog/rubros/{$systemRubroId}")
            ->assertStatus(403);
    }

    /**
     * Tier 3.3: Reasignación de categorías entre rubros y desbloqueo de eliminación.
     */
    public function test_tier3_switching_categories_between_rubros(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear Rubro Origen (Construcción) y Rubro Destino (Acabados)
        $rubroARes = $this->postJson('/api/catalog/rubros', ['name' => 'Construcción Seca']);
        $rubroARes->assertStatus(201);
        $rubroAId = $rubroARes->json('id');

        $rubroBRes = $this->postJson('/api/catalog/rubros', ['name' => 'Acabados y Pinturas']);
        $rubroBRes->assertStatus(201);
        $rubroBId = $rubroBRes->json('id');

        // Crear categoría bajo Rubro Origen
        $catRes = $this->postJson('/api/catalog/categories', [
            'name' => 'Placas de Yeso',
            'rubro_id' => $rubroAId,
        ]);
        $catRes->assertStatus(201);
        $catId = $catRes->json('id');

        // Rubro A no se puede eliminar porque tiene una categoría
        $this->deleteJson("/api/catalog/rubros/{$rubroAId}")->assertStatus(422);

        // Reasignar categoría a Rubro B
        $updateCatRes = $this->putJson("/api/catalog/categories/{$catId}", [
            'name' => 'Placas de Yeso Decorativas',
            'rubro_id' => $rubroBId,
        ]);
        $updateCatRes->assertStatus(200)
            ->assertJsonPath('rubro_id', $rubroBId);

        $this->assertDatabaseHas('categories', [
            'id' => $catId,
            'rubro_id' => $rubroBId,
        ]);

        // Ahora Rubro A quedó sin categorías: su eliminación DEBE ser exitosa
        $deleteRubroARes = $this->deleteJson("/api/catalog/rubros/{$rubroAId}");
        $this->assertTrue(in_array($deleteRubroARes->status(), [200, 204], true));
        $this->assertDatabaseMissing('rubros', ['id' => $rubroAId]);

        // Mientras tanto, Rubro B ahora tiene la categoría y no puede eliminarse
        $this->deleteJson("/api/catalog/rubros/{$rubroBId}")->assertStatus(422);
    }

    /**
     * Tier 3.4: Productos bajo categorías de diferentes rubros con relaciones eager loaded.
     */
    public function test_tier3_products_under_categories_of_different_rubros(): void
    {
        $this->setMultiRubroPlan(true);

        // Rubro 1: Cerrajería -> Categoría: Llaves y Candados -> Producto: Candado Bronce 50mm
        $rubro1Res = $this->postJson('/api/catalog/rubros', ['name' => 'Cerrajería Integral']);
        $rubro1Id = $rubro1Res->json('id');

        $cat1Res = $this->postJson('/api/catalog/categories', [
            'name' => 'Candados y Cerrojos',
            'rubro_id' => $rubro1Id,
        ]);
        $cat1Id = $cat1Res->json('id');

        $prod1Res = $this->postJson('/api/catalog/products', [
            'name' => 'Candado Bronce 50mm Reforzado',
            'cost_price' => 3000.00,
            'selling_price' => 5800.00,
            'stock' => 12,
            'category_id' => $cat1Id,
        ]);
        $prod1Id = $prod1Res->json('id');

        // Rubro 2: Jardinería -> Categoría: Riego -> Producto: Aspersor Giratorio 3 Brazos
        $rubro2Res = $this->postJson('/api/catalog/rubros', ['name' => 'Jardín y Exteriores']);
        $rubro2Id = $rubro2Res->json('id');

        $cat2Res = $this->postJson('/api/catalog/categories', [
            'name' => 'Sistemas de Riego',
            'rubro_id' => $rubro2Id,
        ]);
        $cat2Id = $cat2Res->json('id');

        $prod2Res = $this->postJson('/api/catalog/products', [
            'name' => 'Aspersor Giratorio 3 Brazos',
            'cost_price' => 1500.00,
            'selling_price' => 3200.00,
            'stock' => 8,
            'category_id' => $cat2Id,
        ]);
        $prod2Id = $prod2Res->json('id');

        // Consultar productos del catálogo y comprobar anidamiento de category y rubro
        $catalogRes = $this->getJson('/api/catalog/products');
        $catalogRes->assertStatus(200);

        $items = $catalogRes->json('data') ?? $catalogRes->json();
        $this->assertIsArray($items);

        $foundProd1 = false;
        $foundProd2 = false;

        foreach ($items as $item) {
            if ($item['id'] === $prod1Id) {
                $foundProd1 = true;
                $this->assertEquals($cat1Id, $item['category']['id'] ?? null);
                $this->assertEquals('Cerrajería Integral', $item['category']['rubro']['name'] ?? null);
            }
            if ($item['id'] === $prod2Id) {
                $foundProd2 = true;
                $this->assertEquals($cat2Id, $item['category']['id'] ?? null);
                $this->assertEquals('Jardín y Exteriores', $item['category']['rubro']['name'] ?? null);
            }
        }

        $this->assertTrue($foundProd1, 'Producto 1 con rubro Cerrajería debe estar presente con relaciones.');
        $this->assertTrue($foundProd2, 'Producto 2 con rubro Jardinería debe estar presente con relaciones.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TIER 4: REAL-WORLD WORKLOAD & BUSINESS SCENARIOS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Tier 4.1: Flujo completo de negocio de inicio a fin (Bootstrap -> Rubros -> Categorías -> Productos -> POS Search).
     */
    public function test_tier4_complete_business_flow_from_seed_to_pos_search(): void
    {
        // ── PASO 1: Bootstrap de Licencia y Configuración Comercial ──
        $this->setLicenseBusinessType('hardware_store');
        $this->setMultiRubroPlan(true);
        $systemRubroId = $this->ensureSystemRubro('Ferretería');

        // ── PASO 2: Descubrimiento de Rubros del Sistema ──
        $rubrosList = $this->getJson('/api/catalog/rubros');
        $rubrosList->assertStatus(200);
        $this->assertTrue(
            collect($rubrosList->json())->contains('is_system', true),
            'El listado de rubros debe incluir al menos el rubro del sistema.'
        );

        // ── PASO 3: Expansión de Nuevos Rubros Comerciales ──
        $rubroSeguridad = $this->postJson('/api/catalog/rubros', [
            'name' => 'Seguridad e Higiene Industrial',
        ])->assertStatus(201)->json('id');

        $rubroIluminacion = $this->postJson('/api/catalog/rubros', [
            'name' => 'Iluminación y Electricidad',
        ])->assertStatus(201)->json('id');

        // ── PASO 4: Definición de Taxonomía de Categorías ──
        // Categoría A bajo Seguridad Industrial
        $catProteccionOcular = $this->postJson('/api/catalog/categories', [
            'name' => 'Protección Ocular y Facial',
            'rubro_id' => $rubroSeguridad,
        ])->assertStatus(201)->json('id');

        // Categoría B bajo Iluminación
        $catLamparasLed = $this->postJson('/api/catalog/categories', [
            'name' => 'Lámparas y Paneles LED',
            'rubro_id' => $rubroIluminacion,
        ])->assertStatus(201)->json('id');

        // Categoría C bajo Rubro Ferretería (Sistema)
        $catFijaciones = $this->postJson('/api/catalog/categories', [
            'name' => 'Fijaciones y Tarugos',
            'rubro_id' => $systemRubroId,
        ])->assertStatus(201)->json('id');

        // ── PASO 5: Alta de Productos en Catálogo ──
        $prodAntiparra = $this->postJson('/api/catalog/products', [
            'name' => 'Antiparra de Seguridad Anti-empaño Libus',
            'cost_price' => 1200.00,
            'selling_price' => 2400.00,
            'stock' => 50,
            'category_id' => $catProteccionOcular,
        ])->assertStatus(201)->json('id');

        $prodPanelLed = $this->postJson('/api/catalog/products', [
            'name' => 'Panel LED Embutir 18W Luz Neutra',
            'cost_price' => 3500.00,
            'selling_price' => 6800.00,
            'stock' => 25,
            'category_id' => $catLamparasLed,
        ])->assertStatus(201)->json('id');

        $prodTarugo = $this->postJson('/api/catalog/products', [
            'name' => 'Tarugo Nylon 8mm con Tope x100u',
            'cost_price' => 800.00,
            'selling_price' => 1600.00,
            'stock' => 100,
            'category_id' => $catFijaciones,
        ])->assertStatus(201)->json('id');

        // ── PASO 6: Búsqueda y Filtrado en Terminal POS ──
        // Búsqueda por término parcial "Antiparra"
        $posSearchRes1 = $this->getJson('/api/pos/products/search?query=Antiparra');
        $posSearchRes1->assertStatus(200);
        $searchData1 = $posSearchRes1->json();
        $this->assertNotEmpty($searchData1);
        $this->assertEquals('Antiparra de Seguridad Anti-empaño Libus', $searchData1[0]['name'] ?? null);
        $this->assertEquals($catProteccionOcular, $searchData1[0]['category_id'] ?? null);

        // Búsqueda por término parcial "Panel LED"
        $posSearchRes2 = $this->getJson('/api/pos/products/search?query=Panel+LED');
        $posSearchRes2->assertStatus(200);
        $searchData2 = $posSearchRes2->json();
        $this->assertNotEmpty($searchData2);
        $this->assertEquals('Panel LED Embutir 18W Luz Neutra', $searchData2[0]['name'] ?? null);

        // ── PASO 7: Verificación de Invariantes de Seguridad y Guardrails ──
        // Intento de borrar rubro del sistema: rechazado
        $this->deleteJson("/api/catalog/rubros/{$systemRubroId}")->assertStatus(422);

        // Intento de borrar rubro Seguridad teniendo categorías: rechazado
        $this->deleteJson("/api/catalog/rubros/{$rubroSeguridad}")->assertStatus(422);

        // Intento de borrar rubro Iluminación teniendo categorías: rechazado
        $this->deleteJson("/api/catalog/rubros/{$rubroIluminacion}")->assertStatus(422);
    }
}
