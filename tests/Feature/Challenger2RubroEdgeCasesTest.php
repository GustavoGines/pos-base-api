<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Challenger2RubroEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $cashier;
    protected Rubro $systemRubro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'permissions' => [Permissions::MANAGE_CATALOG],
        ]);

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => [],
        ]);

        $this->systemRubro = Rubro::where('is_system', true)->first()
            ?? Rubro::create(['name' => 'Ferretería General', 'is_system' => true]);
    }

    protected function setPlan(bool $isPremium): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => $isPremium])]
        );
    }

    protected function actingAsUser(User $user): static
    {
        $token = 'test-token-'.uniqid();
        DB::table('users')
            ->where('id', $user->id)
            ->update(['session_token' => $token]);

        $this->withHeader('X-Session-Token', $token);

        return $this;
    }

    protected function createProduct(array $attributes = []): Product
    {
        static $counter = 1;
        $counter++;

        return Product::create(array_merge([
            'name' => "Producto Test {$counter}",
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'stock' => 10,
            'internal_code' => str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1: INVALID / NON-EXISTENT rubro_id IN PREMIUM PLAN (MUST RETURN 422)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_premium_category_store_with_non_existent_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Ferretería Pesada',
            'rubro_id' => 999999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_store_with_zero_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Zero Rubro Cat',
            'rubro_id' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_store_with_negative_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Negative Rubro Cat',
            'rubro_id' => -10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_store_with_non_numeric_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'String Rubro Cat',
            'rubro_id' => 'not-a-number',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_store_with_float_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Float Rubro Cat',
            'rubro_id' => 1.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_store_with_array_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Array Rubro Cat',
            'rubro_id' => [1, 2],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_update_with_non_existent_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $category = Category::create([
            'name' => 'Categoría Inicial',
            'rubro_id' => $this->systemRubro->id,
        ]);

        $response = $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => 888888,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_category_update_with_zero_rubro_id_returns_422(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $category = Category::create([
            'name' => 'Categoría Inicial Zero Test',
            'rubro_id' => $this->systemRubro->id,
        ]);

        $response = $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 2: NULL / OMITTED rubro_id IN PREMIUM PLAN (SAFELY FALLBACK)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_premium_category_store_omitted_rubro_id_falls_back_to_system_default(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Premium Sin Rubro',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Categoría Premium Sin Rubro')
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Categoría Premium Sin Rubro',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_premium_category_store_null_rubro_id_falls_back_to_system_default(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Categoría Premium Null Rubro',
            'rubro_id' => null,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Categoría Premium Null Rubro')
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Categoría Premium Null Rubro',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_premium_category_update_null_rubro_id_falls_back_to_system_default(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $customRubro = Rubro::create(['name' => 'Bazar y Regalos', 'is_system' => false]);
        $category = Category::create([
            'name' => 'Artículos de Cocina',
            'rubro_id' => $customRubro->id,
        ]);

        $response = $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => null,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_premium_category_update_omitted_rubro_id_retains_existing_rubro(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        $customRubro = Rubro::create(['name' => 'Electricidad Pro', 'is_system' => false]);
        $category = Category::create([
            'name' => 'Cables Subterráneos',
            'rubro_id' => $customRubro->id,
        ]);

        // Updating only name, omitting rubro_id
        $response = $this->putJson("/api/catalog/categories/{$category->id}", [
            'name' => 'Cables Subterráneos Sintenax',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Cables Subterráneos Sintenax')
            ->assertJsonPath('rubro_id', $customRubro->id)
            ->assertJsonPath('rubro.id', $customRubro->id);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Cables Subterráneos Sintenax',
            'rubro_id' => $customRubro->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 3: GET /api/catalog/rubros WORKS FOR BOTH BASIC & PREMIUM (NO 403)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_rubros_works_for_basic_plan_guest(): void
    {
        $this->setPlan(false);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200)
            ->assertJsonIsArray();

        $this->assertTrue(collect($response->json())->contains('id', $this->systemRubro->id));
    }

    public function test_get_rubros_works_for_basic_plan_authenticated_admin(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(false);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200)
            ->assertJsonIsArray();

        $this->assertTrue(collect($response->json())->contains('id', $this->systemRubro->id));
    }

    public function test_get_rubros_works_for_basic_plan_authenticated_cashier(): void
    {
        $this->actingAsUser($this->cashier);
        $this->setPlan(false);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200)
            ->assertJsonIsArray();

        $this->assertTrue(collect($response->json())->contains('id', $this->systemRubro->id));
    }

    public function test_get_rubros_works_for_premium_plan_guest(): void
    {
        $this->setPlan(true);

        $custom = Rubro::create(['name' => 'Rubro Extra Premium', 'is_system' => false]);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200)
            ->assertJsonIsArray();

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($this->systemRubro->id));
        $this->assertTrue($ids->contains($custom->id));
    }

    public function test_get_rubros_works_for_premium_plan_authenticated_cashier(): void
    {
        $this->actingAsUser($this->cashier);
        $this->setPlan(true);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200)
            ->assertJsonIsArray();

        $this->assertTrue(collect($response->json())->contains('id', $this->systemRubro->id));
    }

    public function test_get_single_rubro_works_for_both_basic_and_premium(): void
    {
        // Basic plan
        $this->setPlan(false);
        $resBasic = $this->getJson("/api/catalog/rubros/{$this->systemRubro->id}");
        $resBasic->assertStatus(200)
            ->assertJsonPath('id', $this->systemRubro->id)
            ->assertJsonPath('name', $this->systemRubro->name);

        // Premium plan
        $this->setPlan(true);
        $resPrem = $this->getJson("/api/catalog/rubros/{$this->systemRubro->id}");
        $resPrem->assertStatus(200)
            ->assertJsonPath('id', $this->systemRubro->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 4: PRODUCTS RETURNED CORRECTLY CONTAIN 'category.rubro'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_products_index_contains_category_rubro_relationship(): void
    {
        $rubro = Rubro::create(['name' => 'Pinturas Pro', 'is_system' => false]);
        $category = Category::create(['name' => 'Esmaltes Sintéticos', 'rubro_id' => $rubro->id]);

        $product = $this->createProduct([
            'name' => 'Esmalte Brillante Blanco 1L',
            'category_id' => $category->id,
        ]);

        $response = $this->getJson('/api/catalog/products');

        $response->assertStatus(200);

        $items = $response->json('data') ?? $response->json();
        $found = collect($items)->firstWhere('id', $product->id);

        $this->assertNotNull($found, 'Product must be in catalog index.');
        $this->assertNotNull($found['category'], 'Product category must not be null.');
        $this->assertEquals($category->id, $found['category']['id']);
        $this->assertNotNull($found['category']['rubro'], 'Product category rubro must be eager loaded.');
        $this->assertEquals($rubro->id, $found['category']['rubro']['id']);
        $this->assertEquals('Pinturas Pro', $found['category']['rubro']['name']);
        $this->assertFalse((bool) $found['category']['rubro']['is_system']);
    }

    public function test_products_index_handles_product_with_null_category_without_crash(): void
    {
        // Uncategorized product
        $product = $this->createProduct([
            'name' => 'Producto Sin Categoría',
            'category_id' => null,
        ]);

        $response = $this->getJson('/api/catalog/products');

        $response->assertStatus(200);

        $items = $response->json('data') ?? $response->json();
        $found = collect($items)->firstWhere('id', $product->id);

        $this->assertNotNull($found);
        $this->assertNull($found['category']);
    }

    public function test_products_show_contains_category_rubro_relationship(): void
    {
        $rubro = Rubro::create(['name' => 'Maderas y Tableros', 'is_system' => false]);
        $category = Category::create(['name' => 'Placas MDF', 'rubro_id' => $rubro->id]);

        $product = $this->createProduct([
            'name' => 'MDF 18mm 1.83x2.60',
            'category_id' => $category->id,
        ]);

        $response = $this->getJson("/api/catalog/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Maderas y Tableros');
    }

    public function test_products_store_and_update_return_category_rubro(): void
    {
        $this->actingAsAdmin($this->admin);

        $rubro = Rubro::create(['name' => 'Cerrajería', 'is_system' => false]);
        $category = Category::create(['name' => 'Cerrojos de Seguridad', 'rubro_id' => $rubro->id]);

        // STORE
        $storeRes = $this->postJson('/api/catalog/products', [
            'name' => 'Cerrojo Doble Perno Acero',
            'cost_price' => 2500,
            'selling_price' => 4500,
            'stock' => 10,
            'category_id' => $category->id,
        ]);

        $storeRes->assertStatus(201)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Cerrajería');

        $productId = $storeRes->json('id');

        // UPDATE
        $rubro2 = Rubro::create(['name' => 'Bulonería', 'is_system' => false]);
        $category2 = Category::create(['name' => 'Tornillos Autoperforantes', 'rubro_id' => $rubro2->id]);

        $updateRes = $this->putJson("/api/catalog/products/{$productId}", [
            'category_id' => $category2->id,
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('category.id', $category2->id)
            ->assertJsonPath('category.rubro.id', $rubro2->id)
            ->assertJsonPath('category.rubro.name', 'Bulonería');
    }

    public function test_categories_index_contains_rubro_relationship(): void
    {
        $rubro = Rubro::create(['name' => 'Electricidad', 'is_system' => false]);
        $category = Category::create(['name' => 'Térmicas y Disyuntores', 'rubro_id' => $rubro->id]);

        $response = $this->getJson('/api/catalog/categories');

        $response->assertStatus(200);

        $items = $response->json();
        $found = collect($items)->firstWhere('id', $category->id);

        $this->assertNotNull($found);
        $this->assertNotNull($found['rubro']);
        $this->assertEquals($rubro->id, $found['rubro']['id']);
        $this->assertEquals('Electricidad', $found['rubro']['name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 5: BASIC PLAN EDGE CASES & IMMUTABILITY
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basic_plan_arbitrary_or_invalid_rubro_id_safely_ignored_and_assigned_to_system_default(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(false);

        // Client attempts to pass invalid rubro_id in Basic plan:
        // System must NOT fail with 422, but gracefully coerce to default system rubro
        $response = $this->postJson('/api/catalog/categories', [
            'name' => 'Cat con Rubro Invalido en Basic',
            'rubro_id' => 999999,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Cat con Rubro Invalido en Basic')
            ->assertJsonPath('rubro_id', $this->systemRubro->id)
            ->assertJsonPath('rubro.id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'name' => 'Cat con Rubro Invalido en Basic',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_basic_plan_cannot_alter_rubros_via_mutations(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(false);

        // POST /rubros -> 403
        $this->postJson('/api/catalog/rubros', ['name' => 'Hack Rubro'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // PUT /rubros/{id} -> 403
        $this->putJson("/api/catalog/rubros/{$this->systemRubro->id}", ['name' => 'Hack Rubro'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // DELETE /rubros/{id} -> 403
        $this->deleteJson("/api/catalog/rubros/{$this->systemRubro->id}")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 6: RUBRO DELETION GUARDRAILS & PERMISSIONS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rubro_deletion_guardrails(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->setPlan(true);

        // 1. System rubro deletion is blocked (422)
        $this->deleteJson("/api/catalog/rubros/{$this->systemRubro->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        // 2. Custom rubro with attached categories is blocked (422)
        $customRubro = Rubro::create(['name' => 'Bazar', 'is_system' => false]);
        Category::create(['name' => 'Vajilla', 'rubro_id' => $customRubro->id]);

        $this->deleteJson("/api/catalog/rubros/{$customRubro->id}")
            ->assertStatus(422);

        // 3. Custom rubro with 0 categories can be deleted (200)
        $emptyRubro = Rubro::create(['name' => 'Rubro Vacío', 'is_system' => false]);
        $this->deleteJson("/api/catalog/rubros/{$emptyRubro->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('rubros', ['id' => $emptyRubro->id]);
    }

    public function test_unauthenticated_user_cannot_mutate_rubros(): void
    {
        $this->setPlan(true);

        // POST unauthenticated -> 401
        $this->postJson('/api/catalog/rubros', ['name' => 'Unauth Rubro'])
            ->assertStatus(401);
    }

    public function test_user_without_catalog_permission_cannot_mutate_rubros(): void
    {
        $this->actingAsUser($this->cashier);
        $this->setPlan(true);

        // POST without MANAGE_CATALOG permission -> 403
        $this->postJson('/api/catalog/rubros', ['name' => 'No Perm Rubro'])
            ->assertStatus(403);
    }
}
