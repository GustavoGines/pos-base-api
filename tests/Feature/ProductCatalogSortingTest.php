<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que el ordenamiento del catálogo por fecha de vencimiento (`expiration_date`)
 * y columnas anulables (`brand_id`) ubique siempre los registros con dato primero (NULLS LAST).
 */
class ProductCatalogSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));
    }

    public function test_sort_by_expiration_date_orders_dates_before_days_and_keeps_nulls_last_in_both_directions(): void
    {
        $sinVtoA = Product::create([
            'name' => 'Producto Sin Vencimiento A',
            'internal_code' => 'SRT-001',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => null,
            'vencimiento_dias' => null,
        ]);

        $soloDias30 = Product::create([
            'name' => 'Fiambre Regla 30d',
            'internal_code' => 'SRT-002',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => null,
            'vencimiento_dias' => 30,
        ]);

        $fechaCercana = Product::create([
            'name' => 'Leche Noviembre',
            'internal_code' => 'SRT-003',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => '2026-11-15',
            'vencimiento_dias' => null,
        ]);

        $fechaIntermedia = Product::create([
            'name' => 'Yogur Diciembre',
            'internal_code' => 'SRT-004',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => '2026-12-20',
            'vencimiento_dias' => null,
        ]);

        $fechaLejana = Product::create([
            'name' => 'Conserva 2027',
            'internal_code' => 'SRT-005',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => '2027-08-10',
            'vencimiento_dias' => 10,
        ]);

        $soloDias90 = Product::create([
            'name' => 'Queso Regla 90d',
            'internal_code' => 'SRT-006',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'expiration_date' => null,
            'vencimiento_dias' => 90,
        ]);

        // 1. DESC (primer clic en VTO.): fecha más nueva/lejana -> más antigua, luego días DESC, luego sin vencimiento
        $descIds = collect(
            $this->getJson('/api/catalog/products?sort_by=expiration_date&sort_direction=desc')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([
            $fechaLejana->id,
            $fechaIntermedia->id,
            $fechaCercana->id,
            $soloDias90->id,
            $soloDias30->id,
            $sinVtoA->id,
        ], $descIds);

        // 2. ASC (segundo clic en VTO.): más próximo/antiguo -> más lejano, luego días ASC, sin vencimiento SIEMPRE al final
        $ascIds = collect(
            $this->getJson('/api/catalog/products?sort_by=expiration_date&sort_direction=asc')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([
            $fechaCercana->id,
            $fechaIntermedia->id,
            $fechaLejana->id,
            $soloDias30->id,
            $soloDias90->id,
            $sinVtoA->id,
        ], $ascIds);
    }

    public function test_sort_by_brand_and_legacy_vencimiento_dias_keep_nulls_last(): void
    {
        $brandZ = Brand::create(['name' => 'Zeta']);
        $brandA = Brand::create(['name' => 'Alfa']);

        $sinMarca = Product::create([
            'name' => 'Producto Suelto',
            'internal_code' => 'BRD-001',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 1,
            'brand_id' => null,
            'vencimiento_dias' => null,
        ]);

        $conZeta = Product::create([
            'name' => 'Producto Zeta',
            'internal_code' => 'BRD-002',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 1,
            'brand_id' => $brandZ->id,
            'vencimiento_dias' => 60,
        ]);

        $conAlfa = Product::create([
            'name' => 'Producto Alfa',
            'internal_code' => 'BRD-003',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 1,
            'brand_id' => $brandA->id,
            'vencimiento_dias' => 15,
        ]);

        $brandAscIds = collect(
            $this->getJson('/api/catalog/products?sort_by=brand_id&sort_direction=asc')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$conAlfa->id, $conZeta->id, $sinMarca->id], $brandAscIds);

        // Compatibilidad v1.8.5 con sort_by=vencimiento_dias
        $legacyAscIds = collect(
            $this->getJson('/api/catalog/products?sort_by=vencimiento_dias&sort_direction=asc')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$conAlfa->id, $conZeta->id, $sinMarca->id], $legacyAscIds);
    }

    public function test_catalog_categories_and_brands_endpoints_return_alphabetical_order(): void
    {
        Category::create(['name' => 'Perfumería']);
        Category::create(['name' => 'Almacén Seco']);
        Category::create(['name' => 'Bebidas']);

        Brand::create(['name' => 'Xiaomi']);
        Brand::create(['name' => 'Bambu Lab']);
        Brand::create(['name' => 'Stanley']);

        $categoryNames = collect(
            $this->getJson('/api/catalog/categories')
                ->assertOk()
                ->json()
        )->pluck('name')->all();

        $this->assertSame([
            'Almacén Seco',
            'Bebidas',
            'Perfumería',
        ], $categoryNames);

        $brandNames = collect(
            $this->getJson('/api/catalog/brands')
                ->assertOk()
                ->json()
        )->pluck('name')->all();

        $this->assertSame([
            'Bambu Lab',
            'Stanley',
            'Xiaomi',
        ], $brandNames);
    }
}
