<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RubroHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Rubro $systemRubro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        // Asegurar que exista un rubro principal del sistema
        $this->systemRubro = Rubro::where('is_system', true)->first()
            ?? Rubro::create(['name' => 'Ferretería General', 'is_system' => true]);
    }

    /**
     * Helper para configurar el plan (Basic vs Premium con multi_rubro).
     */
    protected function setMultiRubroPlan(bool $enabled): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => $enabled])]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. BASIC PLAN GATING TESTS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basic_plan_cannot_create_new_rubros_returns_403_feature_not_licensed(): void
    {
        $this->setMultiRubroPlan(false);

        $response = $this->postJson('/api/catalog/rubros', [
            'name' => 'Nuevo Rubro Básico',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        $this->assertDatabaseMissing('rubros', [
            'name' => 'Nuevo Rubro Básico',
        ]);
    }

    public function test_basic_plan_cannot_update_rubros_returns_403_feature_not_licensed(): void
    {
        $this->setMultiRubroPlan(false);

        $response = $this->putJson("/api/catalog/rubros/{$this->systemRubro->id}", [
            'name' => 'Nombre Modificado',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        $this->assertDatabaseHas('rubros', [
            'id' => $this->systemRubro->id,
            'name' => $this->systemRubro->name,
        ]);
    }

    public function test_basic_plan_cannot_delete_rubros_returns_403_feature_not_licensed(): void
    {
        $this->setMultiRubroPlan(false);

        $response = $this->deleteJson("/api/catalog/rubros/{$this->systemRubro->id}");

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        $this->assertDatabaseHas('rubros', [
            'id' => $this->systemRubro->id,
        ]);
    }

    public function test_basic_plan_can_list_rubros(): void
    {
        $this->setMultiRubroPlan(false);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200);
        $rubros = $response->json();
        $this->assertIsArray($rubros);
        $this->assertNotEmpty($rubros);

        $foundSystem = collect($rubros)->firstWhere('is_system', true);
        $this->assertNotNull($foundSystem, 'El Plan Básico debe poder ver el rubro por defecto del sistema.');
    }

    public function test_basic_plan_auto_assigns_system_default_rubro_when_creating_category(): void
    {
        $this->setMultiRubroPlan(false);

        // Caso 1: Omitir rubro_id completamente
        $resA = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Estándar',
            'description' => 'Creada sin rubro_id',
        ]);

        $resA->assertStatus(201)
            ->assertJsonPath('name', 'Categoría Estándar')
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Categoría Estándar',
            'rubro_id' => $this->systemRubro->id,
        ]);

        // Caso 2: Enviar rubro_id arbitrario o inexistente (debe ignorarse y auto-asignar el del sistema)
        $resB = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Con Rubro Ignorado',
            'rubro_id' => 999999,
        ]);

        $resB->assertStatus(201)
            ->assertJsonPath('name', 'Categoría Con Rubro Ignorado')
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Categoría Con Rubro Ignorado',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_basic_plan_prevents_reassigning_rubro_when_updating_category(): void
    {
        $this->setMultiRubroPlan(false);

        $category = Category::create([
            'name' => 'Categoría Original',
            'rubro_id' => $this->systemRubro->id,
        ]);

        // Intentar cambiar el rubro_id en plan básico: debe ignorarse y mantener el existente
        $response = $this->putJson("/api/catalog/categories/{$category->id}", [
            'name' => 'Categoría Renombrada',
            'rubro_id' => 999999,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Categoría Renombrada')
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Categoría Renombrada',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. PREMIUM PLAN CRUD & ASSIGNMENT TESTS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_premium_plan_can_create_list_update_and_delete_rubros(): void
    {
        $this->setMultiRubroPlan(true);

        // CREATE
        $createRes = $this->postJson('/api/catalog/rubros', [
            'name' => 'Pinturería Especializada',
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('name', 'Pinturería Especializada')
            ->assertJsonPath('is_system', false);

        $rubroId = $createRes->json('id');
        $this->assertNotNull($rubroId);

        // LIST
        $listRes = $this->getJson('/api/catalog/rubros');
        $listRes->assertStatus(200)
            ->assertJsonFragment(['name' => 'Pinturería Especializada']);

        // UPDATE
        $updateRes = $this->putJson("/api/catalog/rubros/{$rubroId}", [
            'name' => 'Pinturas y Accesorios Pro',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('name', 'Pinturas y Accesorios Pro');

        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'name' => 'Pinturas y Accesorios Pro',
        ]);

        // DELETE
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$rubroId}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('rubros', [
            'id' => $rubroId,
        ]);
    }

    public function test_premium_plan_can_assign_category_to_specific_rubro(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear rubro específico
        $rubroA = Rubro::create(['name' => 'Sanitarios y Grifería', 'is_system' => false]);
        $rubroB = Rubro::create(['name' => 'Iluminación LED', 'is_system' => false]);

        // Crear categoría asociada a rubroA
        $catRes = $this->postJson('/api/catalog/categories', [
            'name' => 'Canillas Monocomando',
            'rubro_id' => $rubroA->id,
        ]);
        $catRes->assertStatus(201)
            ->assertJsonPath('name', 'Canillas Monocomando')
            ->assertJsonPath('rubro_id', $rubroA->id)
            ->assertJsonPath('rubro.name', 'Sanitarios y Grifería');

        $catId = $catRes->json('id');

        // Actualizar categoría reasignando a rubroB
        $updateRes = $this->putJson("/api/catalog/categories/{$catId}", [
            'rubro_id' => $rubroB->id,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('rubro_id', $rubroB->id)
            ->assertJsonPath('rubro.name', 'Iluminación LED');

        $this->assertDatabaseHas('categories', [
            'id' => $catId,
            'rubro_id' => $rubroB->id,
        ]);
    }

    public function test_premium_plan_validates_rubro_id_exists_on_category_creation_and_update(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear con rubro_id inexistente debe fallar con 422
        $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Inválida',
            'rubro_id' => 888888,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);

        $category = Category::create([
            'name' => 'Categoría Existente',
            'rubro_id' => $this->systemRubro->id,
        ]);

        // Actualizar con rubro_id inexistente debe fallar con 422
        $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => 888888,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_plan_falls_back_to_system_default_rubro_if_null_or_omitted(): void
    {
        $this->setMultiRubroPlan(true);

        // Crear omitiendo rubro_id
        $resA = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Sin Rubro Explícito',
        ]);
        $resA->assertStatus(201)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        // Crear enviando rubro_id nulo
        $resB = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Con Rubro Null',
            'rubro_id' => null,
        ]);
        $resB->assertStatus(201)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        $customRubro = Rubro::create(['name' => 'Herramientas', 'is_system' => false]);
        $category = Category::create([
            'name' => 'Taladros',
            'rubro_id' => $customRubro->id,
        ]);

        // Actualizar enviando rubro_id nulo debe volver al rubro del sistema
        $updateRes = $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => null,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. RUBRO DELETION GUARDRAILS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_deleting_the_system_rubro_is_blocked_with_422(): void
    {
        $this->setMultiRubroPlan(true);

        $response = $this->deleteJson("/api/catalog/rubros/{$this->systemRubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        $this->assertDatabaseHas('rubros', [
            'id' => $this->systemRubro->id,
            'is_system' => true,
        ]);
    }

    public function test_deleting_a_rubro_with_categories_is_blocked_with_422(): void
    {
        $this->setMultiRubroPlan(true);

        $customRubro = Rubro::create(['name' => 'Jardinería y Paisajismo', 'is_system' => false]);
        Category::create(['name' => 'Plantas y Semillas', 'rubro_id' => $customRubro->id]);

        $response = $this->deleteJson("/api/catalog/rubros/{$customRubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains(strtolower($msg), 'categoría') || str_contains(strtolower($msg), 'categoria'));

        $this->assertDatabaseHas('rubros', [
            'id' => $customRubro->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. PRODUCT AND HIERARCHY EAGER LOADING TESTS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_product_endpoints_return_category_rubro_correctly(): void
    {
        $this->setMultiRubroPlan(true);

        $rubro = Rubro::create(['name' => 'Seguridad Industrial', 'is_system' => false]);
        $category = Category::create(['name' => 'Cascos de Seguridad', 'rubro_id' => $rubro->id]);

        // 1. Crear producto asociado a la categoría
        $createRes = $this->postJson('/api/catalog/products', [
            'name' => 'Casco Blanco con Arnés 3M',
            'cost_price' => 5000.00,
            'selling_price' => 9500.00,
            'stock' => 15,
            'category_id' => $category->id,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('name', 'Casco Blanco con Arnés 3M')
            ->assertJsonPath('category_id', $category->id)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Seguridad Industrial');

        $productId = $createRes->json('id');

        // 2. GET /api/catalog/products/{id}
        $showRes = $this->getJson("/api/catalog/products/{$productId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('id', $productId)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Seguridad Industrial');

        // 3. GET /api/catalog/products (listado con búsqueda)
        $listRes = $this->getJson('/api/catalog/products?search=Casco+Blanco');
        $listRes->assertStatus(200);

        $items = $listRes->json('data') ?? $listRes->json();
        $this->assertNotEmpty($items);
        $found = collect($items)->firstWhere('id', $productId);
        $this->assertNotNull($found);
        $this->assertEquals($category->id, $found['category']['id'] ?? null);
        $this->assertEquals($rubro->id, $found['category']['rubro']['id'] ?? null);
        $this->assertEquals('Seguridad Industrial', $found['category']['rubro']['name'] ?? null);
    }
}
