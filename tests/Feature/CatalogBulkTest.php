<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BulkPriceHistory;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogBulkTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $this->actingAsAdmin($this->admin);
    }

    protected function actingAsCashier(?User $user = null, array $permissions = []): User
    {
        $token = 'cashier-token-'.uniqid();
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('5678'),
                'permissions' => $permissions,
            ]);
        }
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $user;
    }

    public function test_c01_bulk_delete_products()
    {
        $products = collect();
        for ($i = 1; $i <= 3; $i++) {
            $products->push(Product::create(['name' => "Prod $i", 'internal_code' => "B0$i", 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10]));
        }
        $ids = $products->pluck('id')->toArray();

        $response = $this->postJson('/api/catalog/products/bulk-delete', [
            'product_ids' => $ids,
        ]);

        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertSoftDeleted('products', ['id' => $id]);
        }
    }

    public function test_c02_bulk_update_products()
    {
        $products = collect();
        for ($i = 4; $i <= 5; $i++) {
            $products->push(Product::create(['name' => "Prod $i", 'internal_code' => "B0$i", 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]));
        }
        $ids = $products->pluck('id')->toArray();

        $response = $this->putJson('/api/catalog/products/bulk-update', [
            'product_ids' => $ids,
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'updated_count', 'affected_ids']);
        $this->assertEquals(2, $response->json('updated_count'));
        $this->assertEquals($ids, $response->json('affected_ids'));

        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'active' => false]);
        }
    }

    public function test_c02b_legacy_post_route_bulk_update()
    {
        $products = collect();
        for ($i = 6; $i <= 7; $i++) {
            $products->push(Product::create(['name' => "Prod $i", 'internal_code' => "B0$i", 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]));
        }
        $ids = $products->pluck('id')->toArray();

        $response = $this->postJson('/api/catalog/products/bulk-update', [
            'product_ids' => $ids,
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'updated_count', 'affected_ids']);
        $this->assertEquals(2, $response->json('updated_count'));

        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'active' => false]);
        }
    }

    public function test_c03_bulk_price_preview_and_update()
    {
        $product = Product::create([
            'name' => 'Price Test',
            'internal_code' => 'B08',
            'cost_price' => 100,
            'selling_price' => 150,
            'stock' => 10,
        ]);

        $previewResponse = $this->postJson('/api/catalog/products/bulk-price-preview', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none',
        ]);

        $previewResponse->assertStatus(200);
        $this->assertEquals(165, (float) $previewResponse->json('examples.0.new_price'));

        $updateResponse = $this->putJson('/api/catalog/products/bulk-price-update', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none',
        ]);

        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 165,
        ]);

        $this->assertDatabaseHas('bulk_price_histories', [
            'percentage' => 10,
            'affected_count' => 1,
        ]);
    }

    public function test_c04_bulk_price_revert()
    {
        $product = Product::create([
            'name' => 'Price Test 2',
            'internal_code' => 'B09',
            'cost_price' => 100,
            'selling_price' => 150,
            'stock' => 10,
        ]);

        $this->putJson('/api/catalog/products/bulk-price-update', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none',
        ]);

        $history = BulkPriceHistory::first();

        $revertResponse = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $revertResponse->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 150,
        ]);

        $this->assertDatabaseHas('bulk_price_histories', [
            'id' => $history->id,
            'reverted' => true,
        ]);
    }

    public function test_c05_bulk_update_brand_via_post_endpoint()
    {
        $brand = Brand::create(['name' => 'Test Brand Acme']);
        $p1 = Product::create(['name' => 'Brand Prod 1', 'internal_code' => 'BR01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5]);
        $p2 = Product::create(['name' => 'Brand Prod 2', 'internal_code' => 'BR02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5]);
        $ids = [$p1->id, $p2->id];

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'brand_id' => $brand->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'updated_count', 'affected_ids']);
        $this->assertEquals(2, $response->json('updated_count'));
        $this->assertEquals($ids, $response->json('affected_ids'));

        $this->assertDatabaseHas('products', ['id' => $p1->id, 'brand_id' => $brand->id]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'brand_id' => $brand->id]);

        // Verify unsetting/clearing brand_id
        $clearResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'brand_id' => null,
        ]);

        $clearResponse->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'brand_id' => null]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'brand_id' => null]);
    }

    public function test_c06_bulk_update_category_via_post_endpoint()
    {
        $category = Category::create(['name' => 'Tools Category']);
        $p1 = Product::create(['name' => 'Cat Prod 1', 'internal_code' => 'CT01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5]);
        $p2 = Product::create(['name' => 'Cat Prod 2', 'internal_code' => 'CT02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5]);
        $ids = [$p1->id, $p2->id];

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'category_id' => $category->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('updated_count'));
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'category_id' => $category->id]);

        // Clearing category_id
        $clearResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'category_id' => null,
        ]);
        $clearResponse->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'category_id' => null]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'category_id' => null]);
    }

    public function test_c07_bulk_update_supplier_and_active_via_post_endpoint()
    {
        $supplier = Supplier::create([
            'name' => 'Global Supplier',
            'cuit' => '30-11223344-9',
            'tax_category' => 'responsable_inscripto',
        ]);

        $p1 = Product::create(['name' => 'Sup Prod 1', 'internal_code' => 'SP01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5, 'active' => true]);
        $p2 = Product::create(['name' => 'Sup Prod 2', 'internal_code' => 'SP02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5, 'active' => true]);
        $ids = [$p1->id, $p2->id];

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'supplier_id' => $supplier->id,
            'active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('updated_count'));
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'supplier_id' => $supplier->id, 'active' => false]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'supplier_id' => $supplier->id, 'active' => false]);
    }

    public function test_c08_bulk_update_validation_errors()
    {
        $p = Product::create(['name' => 'Val Prod', 'internal_code' => 'VL01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5]);

        // 1. Missing update parameters (should return 400 or 422)
        $noUpdatesResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
        ]);
        $this->assertContains($noUpdatesResponse->status(), [400, 422]);

        // 2. Empty product_ids array
        $emptyIdsResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [],
            'active' => false,
        ]);
        $emptyIdsResponse->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');

        // 3. Non-existent product ID
        $nonExistentProductResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [999999],
            'active' => false,
        ]);
        $nonExistentProductResponse->assertStatus(422)
            ->assertJsonValidationErrors('product_ids.0');

        // 4. Non-existent category_id
        $invalidCatResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'category_id' => 999999,
        ]);
        $invalidCatResponse->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        // 5. Non-existent brand_id
        $invalidBrandResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'brand_id' => 999999,
        ]);
        $invalidBrandResponse->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');

        // 6. Soft-deleted supplier_id
        $supplier = Supplier::create([
            'name' => 'Soft Deleted Supplier',
            'cuit' => '30-99887766-5',
        ]);
        $supplier->delete();

        $deletedSupResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'supplier_id' => $supplier->id,
        ]);
        $deletedSupResponse->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_c09_bulk_update_database_transaction_integrity_and_rollback()
    {
        $p1 = Product::create(['name' => 'Rollback 1', 'internal_code' => 'RB01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5, 'active' => true]);
        $p2 = Product::create(['name' => 'Rollback 2', 'internal_code' => 'RB02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5, 'active' => true]);

        // Register a listener to simulate a database failure during product update
        DB::listen(function ($query) {
            if (str_contains(strtolower($query->sql), 'update "products"') || str_contains(strtolower($query->sql), 'update `products`')) {
                throw new \RuntimeException('Simulated database write deadlock / failure');
            }
        });

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'active' => false,
        ]);

        $response->assertStatus(500);

        // Transaction must have rolled back: both products retain original active=true state
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'active' => true]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'active' => true]);
    }

    public function test_c10_bulk_update_chunking_large_batches()
    {
        $records = [];
        $now = now();
        for ($i = 1; $i <= 505; $i++) {
            $records[] = [
                'name' => "Batch Prod $i",
                'internal_code' => "CHK{$i}",
                'cost_price' => 10,
                'selling_price' => 20,
                'stock' => 5,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Product::insert($records);
        $ids = Product::where('internal_code', 'like', 'CHK%')->pluck('id')->toArray();
        $this->assertCount(505, $ids);

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(505, $response->json('updated_count'));
        $this->assertCount(505, $response->json('affected_ids'));

        $activeCount = Product::where('internal_code', 'like', 'CHK%')->where('active', 1)->count();
        $this->assertEquals(0, $activeCount);
    }

    public function test_c11_bulk_update_permission_and_pin_enforcement()
    {
        $p = Product::create(['name' => 'Perm Prod', 'internal_code' => 'PM01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5, 'active' => true]);

        // Cashier without manage_catalog permission
        $this->actingAsCashier(permissions: []);

        $deniedResponse = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => false,
        ]);

        $deniedResponse->assertStatus(403);
        $deniedResponse->assertJsonPath('error_code', 'PIN_REQUIRED');

        // Cashier retrying with valid admin PIN header
        $authorizedResponse = $this->withHeader('X-Admin-Pin', '1234')
            ->postJson('/api/catalog/bulk-update', [
                'product_ids' => [$p->id],
                'active' => false,
            ]);

        $authorizedResponse->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'active' => false]);
    }
}
