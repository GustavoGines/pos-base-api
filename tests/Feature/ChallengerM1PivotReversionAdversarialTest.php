<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChallengerM1PivotReversionAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->actingAsAdmin($this->adminUser);
    }

    protected function createProduct(array $overrides = []): Product
    {
        static $counter = 10000;
        $counter++;

        return Product::create(array_merge([
            'name' => 'Product ' . $counter,
            'internal_code' => (string) $counter,
            'barcode' => (string) (7790000000000 + $counter),
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'stock' => 10,
            'active' => true,
        ], $overrides));
    }

    // =========================================================================
    // SECTION 1: SCHEMA & MODEL INVARIANTS
    // =========================================================================

    public function test_schema_has_no_pivot_table_and_preserves_direct_category_foreign_key(): void
    {
        // 1. Pivot table must be completely absent from DB
        $this->assertFalse(
            Schema::hasTable('category_product'),
            'FAIL: Table category_product still exists in the database!'
        );

        // 2. Direct foreign key column must exist in products table
        $this->assertTrue(
            Schema::hasColumn('products', 'category_id'),
            'FAIL: products.category_id column is missing from products table!'
        );

        // 3. Eloquent Model Reflection: Product has category(), not categories()
        $product = new Product();
        $this->assertTrue(method_exists($product, 'category'), 'FAIL: Product::category() relation method missing!');
        $this->assertFalse(method_exists($product, 'categories'), 'FAIL: Product::categories() BelongsToMany still exists!');
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $product->category());

        // 4. Eloquent Model Reflection: Category has products(), not pivotProducts()
        $category = new Category();
        $this->assertTrue(method_exists($category, 'products'), 'FAIL: Category::products() relation method missing!');
        $this->assertFalse(method_exists($category, 'pivotProducts'), 'FAIL: Category::pivotProducts() BelongsToMany still exists!');
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $category->products());
    }

    // =========================================================================
    // SECTION 2: 1:N RELATIONSHIP LIFECYCLE & INTEGRITY
    // =========================================================================

    public function test_restored_1_to_n_product_category_lifecycle(): void
    {
        $cat1 = Category::create(['name' => 'Adversarial Cat 1', 'description' => 'Test Cat 1']);
        $cat2 = Category::create(['name' => 'Adversarial Cat 2', 'description' => 'Test Cat 2']);

        // A. Create product with category_id
        $createRes = $this->postJson('/api/catalog/products', [
            'name' => '1N Test Product Alpha',
            'cost_price' => 100.00,
            'selling_price' => 150.00,
            'stock' => 10,
            'category_id' => $cat1->id,
        ]);
        $createRes->assertStatus(201);
        $productId = $createRes->json('id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'category_id' => $cat1->id,
        ]);

        // Verify inverse relationship
        $this->assertTrue($cat1->products()->where('id', $productId)->exists());
        $this->assertFalse($cat2->products()->where('id', $productId)->exists());

        // Verify show endpoint
        $showRes = $this->getJson("/api/catalog/products/{$productId}");
        $showRes->assertStatus(200);
        $this->assertEquals($cat1->id, $showRes->json('category.id'));
        $this->assertArrayNotHasKey('categories', $showRes->json());

        // B. Reassign product to Category 2
        $updateRes = $this->putJson("/api/catalog/products/{$productId}", [
            'category_id' => $cat2->id,
        ]);
        $updateRes->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'category_id' => $cat2->id,
        ]);
        $this->assertFalse($cat1->products()->where('id', $productId)->exists());
        $this->assertTrue($cat2->products()->where('id', $productId)->exists());

        // C. Nullify category
        $nullifyRes = $this->putJson("/api/catalog/products/{$productId}", [
            'category_id' => null,
        ]);
        $nullifyRes->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'category_id' => null,
        ]);
        $this->assertFalse($cat2->products()->where('id', $productId)->exists());
    }

    public function test_category_deletion_guardrail_blocks_when_products_exist(): void
    {
        $category = Category::create(['name' => 'Protected Category']);
        $product = $this->createProduct([
            'name' => 'Attached Product',
            'category_id' => $category->id,
        ]);

        // Attempt deletion of category with product
        $deleteRes = $this->deleteJson("/api/catalog/categories/{$category->id}");
        $deleteRes->assertStatus(422);
        $this->assertStringContainsString('No se puede eliminar la categoría', $deleteRes->json('message'));
        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        // Nullify product category
        $product->update(['category_id' => null]);

        // Deletion must now succeed
        $deleteRes2 = $this->deleteJson("/api/catalog/categories/{$category->id}");
        $deleteRes2->assertStatus(204);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_validation_rejects_non_existent_category_id(): void
    {
        $invalidCatId = 999999;

        // POST rejects non-existent category
        $resStore = $this->postJson('/api/catalog/products', [
            'name' => 'Invalid Cat Product',
            'cost_price' => 50,
            'selling_price' => 75,
            'stock' => 1,
            'category_id' => $invalidCatId,
        ]);
        $resStore->assertStatus(422);
        $resStore->assertJsonValidationErrors(['category_id']);

        // POST rejects string category_id
        $resStoreString = $this->postJson('/api/catalog/products', [
            'name' => 'String Cat Product',
            'cost_price' => 50,
            'selling_price' => 75,
            'stock' => 1,
            'category_id' => 'not_a_valid_id',
        ]);
        $resStoreString->assertStatus(422);
        $resStoreString->assertJsonValidationErrors(['category_id']);

        // PUT rejects non-existent category
        $prod = $this->createProduct(['name' => 'Valid Product Base']);

        $resUpdate = $this->putJson("/api/catalog/products/{$prod->id}", [
            'category_id' => $invalidCatId,
        ]);
        $resUpdate->assertStatus(422);
        $resUpdate->assertJsonValidationErrors(['category_id']);
    }

    // =========================================================================
    // SECTION 3: ADVERSARIAL PAYLOAD TESTING: MULTIPLE CATEGORIES (category_ids)
    // =========================================================================

    public function test_adversarial_passing_category_ids_array_does_not_crash_or_create_phantoms(): void
    {
        $cat1 = Category::create(['name' => 'Adv Cat 1']);
        $cat2 = Category::create(['name' => 'Adv Cat 2']);
        $cat3 = Category::create(['name' => 'Adv Cat 3']);

        // Attack 1: Client sends category_ids array without category_id
        $res = $this->postJson('/api/catalog/products', [
            'name' => 'Payload Injection Test 1',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 5,
            'category_ids' => [$cat1->id, $cat2->id, $cat3->id],
        ]);

        $res->assertStatus(201);
        $productId = $res->json('id');

        // Verify product was created with null category_id (not crashing, not storing invalid state)
        $product = Product::findOrFail($productId);
        $this->assertNull($product->category_id);
        $this->assertNull($product->category);

        // Verify no phantom pivot entries
        $this->assertFalse(Schema::hasTable('category_product'));
        $this->assertFalse($cat1->products()->where('id', $productId)->exists());
        $this->assertFalse($cat2->products()->where('id', $productId)->exists());
        $this->assertFalse($cat3->products()->where('id', $productId)->exists());

        // Verify response structure does NOT contain 'categories' array
        $res->assertJsonMissing(['categories']);
    }

    public function test_adversarial_passing_both_category_id_and_category_ids(): void
    {
        $cat1 = Category::create(['name' => 'Target Cat 1']);
        $cat2 = Category::create(['name' => 'Ignored Cat 2']);
        $cat3 = Category::create(['name' => 'Ignored Cat 3']);

        // Attack 2: Client sends legitimate category_id along with confusing category_ids array
        $res = $this->postJson('/api/catalog/products', [
            'name' => 'Payload Ambiguity Test',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 5,
            'category_id' => $cat1->id,
            'category_ids' => [$cat2->id, $cat3->id],
            'primary_category_id' => $cat2->id, // Obsolete field from prior pivot attempt
        ]);

        $res->assertStatus(201);
        $productId = $res->json('id');

        $product = Product::findOrFail($productId);
        // category_id must strictly be cat1->id
        $this->assertEquals($cat1->id, $product->category_id);
        $this->assertEquals($cat1->id, $product->category->id);

        // cat2 and cat3 must NOT be linked
        $this->assertFalse($cat2->products()->where('id', $productId)->exists());
        $this->assertFalse($cat3->products()->where('id', $productId)->exists());
    }

    public function test_adversarial_malformed_category_ids_types(): void
    {
        // Malformed inputs for category_ids (strings, integers, nested arrays, null)
        $malformedPayloads = [
            'category_ids' => 'string_instead_of_array',
            'category_ids' => 99999,
            'category_ids' => ['bad', 'types', false],
            'category_ids' => null,
            'primary_category_id' => 'malformed',
        ];

        foreach ($malformedPayloads as $key => $val) {
            $res = $this->postJson('/api/catalog/products', [
                'name' => 'Malformed ' . uniqid(),
                'cost_price' => 10,
                'selling_price' => 20,
                'stock' => 1,
                $key => $val,
            ]);

            // Must NOT trigger 500 Internal Server Error
            $this->assertNotEquals(500, $res->getStatusCode(), "Server crashed with 500 on payload: {$key}");
            $this->assertEquals(201, $res->getStatusCode(), "Expected 201 ignoring unvalidated {$key}");
        }
    }

    public function test_adversarial_update_with_category_ids_array_does_not_mutate_relations(): void
    {
        $cat1 = Category::create(['name' => 'Base Cat 1']);
        $cat2 = Category::create(['name' => 'Target Cat 2']);

        $product = $this->createProduct([
            'name' => 'Update Stress Product',
            'category_id' => $cat1->id,
        ]);

        // Attempt PUT with category_ids without category_id
        $res = $this->putJson("/api/catalog/products/{$product->id}", [
            'category_ids' => [$cat2->id],
        ]);
        $res->assertStatus(200);

        // Product category_id must remain unchanged (cat1->id)
        $product->refresh();
        $this->assertEquals($cat1->id, $product->category_id);

        // Attempt PUT with explicit category_id = cat2 and confusing category_ids = [cat1]
        $res2 = $this->putJson("/api/catalog/products/{$product->id}", [
            'category_id' => $cat2->id,
            'category_ids' => [$cat1->id],
        ]);
        $res2->assertStatus(200);

        $product->refresh();
        $this->assertEquals($cat2->id, $product->category_id);
    }

    public function test_index_products_endpoint_does_not_return_categories_relation(): void
    {
        $cat = Category::create(['name' => 'Catalog Cat']);
        $this->createProduct([
            'name' => 'Catalog Product Item',
            'category_id' => $cat->id,
        ]);

        $res = $this->getJson('/api/catalog/products');
        $res->assertStatus(200);

        $items = $res->json('data');
        $this->assertNotEmpty($items);

        $first = $items[0];
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayNotHasKey('categories', $first);
    }

    // =========================================================================
    // SECTION 4: PHASE 0 SECURITY INVARIANTS PASS
    // =========================================================================

    public function test_phase_0_security_checks_remain_fully_functional(): void
    {
        // 1. Secret persistence
        BusinessSetting::setSecret('mp_access_token', 'APP_USR-test-adversarial-secret-token');
        BusinessSetting::setSecret('mp_webhook_secret', 'whsec-adversarial-test-hash');

        // Check DB raw value is encrypted
        $rawRow = DB::table('business_settings')->where('key', 'mp_access_token')->first();
        $this->assertNotNull($rawRow);
        $this->assertNotEquals('APP_USR-test-adversarial-secret-token', $rawRow->value);
        $this->assertEquals('APP_USR-test-adversarial-secret-token', BusinessSetting::getSecret('mp_access_token'));

        // 2. GET /api/settings does not leak secret keys
        $settingsRes = $this->getJson('/api/settings');
        $settingsRes->assertStatus(200);
        $settingsData = $settingsRes->json();
        $this->assertArrayNotHasKey('mp_access_token', $settingsData);
        $this->assertArrayNotHasKey('mp_webhook_secret', $settingsData);

        // 3. Unauthenticated access to /api/settings/integrations is rejected
        $unauthRes = $this->flushHeaders()->getJson('/api/settings/integrations');
        $unauthRes->assertStatus(401);
    }
}
