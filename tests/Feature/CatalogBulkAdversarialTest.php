<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogBulkAdversarialTest extends TestCase
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

    public function test_multi_chunk_atomic_rollback_when_later_chunk_fails()
    {
        // Create 505 products: 500 will be chunk 1, 5 will be chunk 2
        $records = [];
        $now = now();
        for ($i = 1; $i <= 505; $i++) {
            $records[] = [
                'name' => "Adversarial Prod $i",
                'internal_code' => "ADV{$i}",
                'cost_price' => 10,
                'selling_price' => 20,
                'stock' => 5,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Product::insert($records);
        $ids = Product::where('internal_code', 'like', 'ADV%')->pluck('id')->toArray();
        $this->assertCount(505, $ids);

        // Counter to fail specifically on the SECOND update query
        $updateCount = 0;
        DB::listen(function ($query) use (&$updateCount) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'update "products"') || str_contains($sql, 'update `products`')) {
                $updateCount++;
                if ($updateCount === 2) {
                    throw new \RuntimeException('Simulated failure during second chunk processing');
                }
            }
        });

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'active' => false,
        ]);

        $response->assertStatus(500);

        // Verify transaction atomicity: Chunk 1 MUST have been rolled back
        // None of the 505 products should have active = false
        $activeProductsCount = Product::where('internal_code', 'like', 'ADV%')->where('active', 1)->count();
        $this->assertEquals(505, $activeProductsCount, 'Chunk 1 modifications were not rolled back!');
    }

    public function test_duplicate_product_ids_handled_gracefully()
    {
        $p1 = Product::create(['name' => 'Dup 1', 'internal_code' => 'DP01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5, 'active' => true]);
        $p2 = Product::create(['name' => 'Dup 2', 'internal_code' => 'DP02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5, 'active' => true]);

        // Duplicate IDs in payload
        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p1->id, $p1->id, $p2->id, $p2->id],
            'active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'active' => false]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'active' => false]);
    }

    public function test_mixed_attributes_bulk_update_simultaneously()
    {
        $brand = Brand::create(['name' => 'Adversarial Brand']);
        $cat = Category::create(['name' => 'Adversarial Category']);

        $p1 = Product::create(['name' => 'Mix 1', 'internal_code' => 'MX01', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5, 'active' => true]);
        $p2 = Product::create(['name' => 'Mix 2', 'internal_code' => 'MX02', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5, 'active' => true]);

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $p1->id,
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'active' => false,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $p2->id,
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'active' => false,
        ]);
    }
}
