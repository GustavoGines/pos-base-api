<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RubroCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        // Activar feature multi_rubro para pruebas de mutación CRUD
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => true])]
        );
    }

    public function test_index_returns_all_rubros_ordered_by_name_with_categories_count(): void
    {
        Rubro::query()->delete();

        $rubroZ = Rubro::create(['name' => 'Zapatería', 'is_system' => false]);
        $rubroA = Rubro::create(['name' => 'Almacén Central', 'is_system' => true]);
        $rubroM = Rubro::create(['name' => 'Metalúrgica', 'is_system' => false]);

        Category::create(['name' => 'Botas', 'rubro_id' => $rubroZ->id]);
        Category::create(['name' => 'Zapatillas', 'rubro_id' => $rubroZ->id]);
        Category::create(['name' => 'Arroz', 'rubro_id' => $rubroA->id]);

        $response = $this->getJson('/api/catalog/rubros');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(3, $data);
        $this->assertEquals('Almacén Central', $data[0]['name']);
        $this->assertEquals(1, $data[0]['categories_count']);

        $this->assertEquals('Metalúrgica', $data[1]['name']);
        $this->assertEquals(0, $data[1]['categories_count']);

        $this->assertEquals('Zapatería', $data[2]['name']);
        $this->assertEquals(2, $data[2]['categories_count']);
    }

    public function test_store_creates_non_system_rubro(): void
    {
        $response = $this->postJson('/api/catalog/rubros', [
            'name' => 'Ferretería Industrial',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Ferretería Industrial')
            ->assertJsonPath('is_system', false);

        $this->assertDatabaseHas('rubros', [
            'name' => 'Ferretería Industrial',
            'is_system' => false,
        ]);
    }

    public function test_show_returns_rubro_with_categories(): void
    {
        $rubro = Rubro::create(['name' => 'Automotor', 'is_system' => false]);
        $cat1 = Category::create(['name' => 'Aceites y Lubricantes', 'rubro_id' => $rubro->id]);
        $cat2 = Category::create(['name' => 'Filtros', 'rubro_id' => $rubro->id]);

        $response = $this->getJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $rubro->id)
            ->assertJsonPath('name', 'Automotor');

        $categories = $response->json('categories');
        $this->assertCount(2, $categories);
        $catNames = collect($categories)->pluck('name')->all();
        $this->assertContains('Aceites y Lubricantes', $catNames);
        $this->assertContains('Filtros', $catNames);
    }

    public function test_update_modifies_rubro_name_and_protects_is_system(): void
    {
        $rubro = Rubro::create(['name' => 'Electricidad', 'is_system' => false]);

        $response = $this->putJson("/api/catalog/rubros/{$rubro->id}", [
            'name' => 'Electricidad y Energía Solar',
            'is_system' => true, // intento malicioso de elevar a sistema
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Electricidad y Energía Solar')
            ->assertJsonPath('is_system', false);

        $this->assertDatabaseHas('rubros', [
            'id' => $rubro->id,
            'name' => 'Electricidad y Energía Solar',
            'is_system' => false,
        ]);
    }

    public function test_destroy_blocks_deletion_of_system_rubro(): void
    {
        $systemRubro = Rubro::create(['name' => 'Rubro Raíz del Sistema', 'is_system' => true]);

        $response = $this->deleteJson("/api/catalog/rubros/{$systemRubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        $this->assertDatabaseHas('rubros', ['id' => $systemRubro->id]);
    }

    public function test_destroy_blocks_deletion_when_categories_exist(): void
    {
        $rubro = Rubro::create(['name' => 'Pinturería', 'is_system' => false]);
        Category::create(['name' => 'Esmaltes Sintéticos', 'rubro_id' => $rubro->id]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'tiene 1 categoría(s) asociada(s)'));

        $this->assertDatabaseHas('rubros', ['id' => $rubro->id]);
    }

    public function test_destroy_succeeds_when_rubro_has_no_categories_and_not_system(): void
    {
        $rubro = Rubro::create(['name' => 'Rubro Huérfano', 'is_system' => false]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('rubros', ['id' => $rubro->id]);
    }

    public function test_validation_store_and_update_rubro(): void
    {
        // 1. Requerido
        $this->postJson('/api/catalog/rubros', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 2. Máximo 255 caracteres
        $this->postJson('/api/catalog/rubros', ['name' => str_repeat('X', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 3. Unicidad
        Rubro::create(['name' => 'Construcción', 'is_system' => false]);
        $this->postJson('/api/catalog/rubros', ['name' => 'Construcción'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 4. Update con mismo nombre está permitido
        $rubro = Rubro::create(['name' => 'Jardinería', 'is_system' => false]);
        $this->putJson("/api/catalog/rubros/{$rubro->id}", ['name' => 'Jardinería'])
            ->assertStatus(200);

        // 5. Update con nombre colisionante es rechazado
        $this->putJson("/api/catalog/rubros/{$rubro->id}", ['name' => 'Construcción'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_endpoints_eager_load_rubro(): void
    {
        $rubro = Rubro::create(['name' => 'Bazar', 'is_system' => false]);
        $category = Category::create(['name' => 'Vajilla', 'rubro_id' => $rubro->id]);

        // GET /api/catalog/categories
        $indexRes = $this->getJson('/api/catalog/categories');
        $indexRes->assertStatus(200);
        $items = collect($indexRes->json());
        $found = $items->firstWhere('id', $category->id);
        $this->assertNotNull($found);
        $this->assertEquals($rubro->id, $found['rubro']['id'] ?? null);
        $this->assertEquals('Bazar', $found['rubro']['name'] ?? null);

        // GET /api/catalog/categories/{id}
        $showRes = $this->getJson("/api/catalog/categories/{$category->id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('rubro.id', $rubro->id)
            ->assertJsonPath('rubro.name', 'Bazar');
    }

    public function test_product_endpoints_eager_load_category_and_rubro(): void
    {
        $rubro = Rubro::create(['name' => 'Herramientas Pesadas', 'is_system' => false]);
        $category = Category::create(['name' => 'Amoladoras', 'rubro_id' => $rubro->id]);
        $product = Product::create([
            'name' => 'Amoladora Angular DeWalt 4 1/2',
            'internal_code' => '99001',
            'cost_price' => 15000,
            'selling_price' => 28000,
            'stock' => 5,
            'category_id' => $category->id,
        ]);

        // GET /api/catalog/products
        $indexRes = $this->getJson('/api/catalog/products');
        $indexRes->assertStatus(200);
        $items = $indexRes->json('data') ?? $indexRes->json();
        $found = collect($items)->firstWhere('id', $product->id);
        $this->assertNotNull($found);
        $this->assertEquals($category->id, $found['category']['id'] ?? null);
        $this->assertEquals('Herramientas Pesadas', $found['category']['rubro']['name'] ?? null);

        // GET /api/catalog/products/{id}
        $showRes = $this->getJson("/api/catalog/products/{$product->id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('category.rubro.id', $rubro->id)
            ->assertJsonPath('category.rubro.name', 'Herramientas Pesadas');
    }
}
