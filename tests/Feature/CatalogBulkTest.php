<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Product;
use App\Models\User;

class CatalogBulkTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'pin' => '1234']);
        $this->actingAsAdmin($this->admin);
    }

    public function test_c01_bulk_delete_products()
    {
        $products = collect();
        for($i=1; $i<=3; $i++){
            $products->push(Product::create(['name' => "Prod $i", 'internal_code' => "B0$i", 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10]));
        }
        $ids = $products->pluck('id')->toArray();

        $response = $this->postJson('/api/catalog/products/bulk-delete', [
            'product_ids' => $ids
        ]);

        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertSoftDeleted('products', ['id' => $id]);
        }
    }

    public function test_c02_bulk_update_products()
    {
        $products = collect();
        for($i=4; $i<=5; $i++){
            $products->push(Product::create(['name' => "Prod $i", 'internal_code' => "B0$i", 'cost_price' => 10, 'selling_price' => 20, 'stock' => 10, 'active' => true]));
        }
        $ids = $products->pluck('id')->toArray();

        $response = $this->putJson('/api/catalog/products/bulk-update', [
            'product_ids' => $ids,
            'active' => false
        ]);

        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertDatabaseHas('products', ['id' => $id, 'active' => false]);
        }
    }

    public function test_c03_bulk_price_preview_and_update()
    {
        $product = Product::create([
            'name' => 'Price Test',
            'internal_code' => 'B06',
            'cost_price' => 100,
            'selling_price' => 150,
            'stock' => 10
        ]);

        $previewResponse = $this->postJson('/api/catalog/products/bulk-price-preview', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none'
        ]);

        $previewResponse->assertStatus(200);
        $this->assertEquals(165, (float) $previewResponse->json('examples.0.new_price'));

        $updateResponse = $this->putJson('/api/catalog/products/bulk-price-update', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none'
        ]);

        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 165
        ]);
        
        $this->assertDatabaseHas('bulk_price_histories', [
            'percentage' => 10,
            'affected_count' => 1
        ]);
    }

    public function test_c04_bulk_price_revert()
    {
        $product = Product::create([
            'name' => 'Price Test 2',
            'internal_code' => 'B07',
            'cost_price' => 100,
            'selling_price' => 150,
            'stock' => 10
        ]);

        $this->putJson('/api/catalog/products/bulk-price-update', [
            'percentage' => 10,
            'product_ids' => [$product->id],
            'target_field' => 'selling_price',
            'rounding_rule' => 'none'
        ]);

        $history = \App\Models\BulkPriceHistory::first();

        $revertResponse = $this->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");
        $revertResponse->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 150
        ]);
        
        $this->assertDatabaseHas('bulk_price_histories', [
            'id' => $history->id,
            'reverted' => true
        ]);
    }
}
