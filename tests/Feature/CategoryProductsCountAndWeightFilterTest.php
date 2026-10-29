<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryProductsCountAndWeightFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_index_returns_active_products_count(): void
    {
        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));

        $catA = Category::create(['name' => 'Cat A']);
        $catB = Category::create(['name' => 'Cat B']);

        // 2 active products in Cat A, 1 inactive
        Product::create([
            'name' => 'Prod A1',
            'internal_code' => 'P-A1',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'category_id' => $catA->id,
            'active' => true,
        ]);
        Product::create([
            'name' => 'Prod A2',
            'internal_code' => 'P-A2',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'category_id' => $catA->id,
            'active' => true,
        ]);
        Product::create([
            'name' => 'Prod A3 Inactive',
            'internal_code' => 'P-A3',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'category_id' => $catA->id,
            'active' => false,
        ]);

        // 0 products in Cat B

        $response = $this->getJson('/api/catalog/categories')->assertOk();
        $data = collect($response->json())->keyBy('id');

        $this->assertSame(2, $data[$catA->id]['products_count']);
        $this->assertSame(0, $data[$catB->id]['products_count']);
    }

    public function test_product_index_filters_by_is_sold_by_weight(): void
    {
        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));

        $weightProd = Product::create([
            'name' => 'Queso por peso',
            'internal_code' => 'PESO-1',
            'selling_price' => 500,
            'cost_price' => 250,
            'stock' => 5,
            'is_sold_by_weight' => true,
            'active' => true,
        ]);

        $unitProd = Product::create([
            'name' => 'Gaseosa unidad',
            'internal_code' => 'UNIT-1',
            'selling_price' => 200,
            'cost_price' => 100,
            'stock' => 20,
            'is_sold_by_weight' => false,
            'active' => true,
        ]);

        $resWeight = $this->getJson('/api/catalog/products?is_sold_by_weight=1')->assertOk();
        $idsWeight = collect($resWeight->json('data'))->pluck('id')->all();

        $this->assertContains($weightProd->id, $idsWeight);
        $this->assertNotContains($unitProd->id, $idsWeight);

        $resUnit = $this->getJson('/api/catalog/products?is_sold_by_weight=0')->assertOk();
        $idsUnit = collect($resUnit->json('data'))->pluck('id')->all();

        $this->assertContains($unitProd->id, $idsUnit);
        $this->assertNotContains($weightProd->id, $idsUnit);
    }
}
