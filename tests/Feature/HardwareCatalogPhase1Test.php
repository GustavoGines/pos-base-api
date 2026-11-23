<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareCatalogPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));
    }

    public function test_can_create_and_update_product_with_hardware_units_conversion_and_calc_config(): void
    {
        $supplier = Supplier::create([
            'name' => 'Bulonera del Sur',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/catalog/products', [
            'name' => 'Cable Unipolar 2.5mm Normalizado',
            'supplier_sku' => 'CAB-250-BLK',
            'supplier_id' => $supplier->id,
            'storage_location' => 'Pasillo 2 - Estante C',
            'technical_spec' => '2.5mm 750V',
            'unit_type' => 'mt',
            'purchase_package_name' => 'Rollo',
            'conversion_factor' => 100,
            'cost_price' => 450,
            'selling_price' => 850,
            'stock' => 300,
            'is_sold_by_weight' => true,
            'active' => true,
            'calc_config' => [
                'type' => 'wood',
                'inch_preset' => '4',
                'nominal_width_m' => 0.1016,
                'useful_width_m' => 0.088,
                'length_m' => 3.0,
                'pricing_mode' => 'per_linear_meter',
                'evil_extra_key' => 'ignored',
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('supplier_sku', 'CAB-250-BLK')
            ->assertJsonPath('storage_location', 'Pasillo 2 - Estante C')
            ->assertJsonPath('technical_spec', '2.5mm 750V')
            ->assertJsonPath('unit_type', 'mt')
            ->assertJsonPath('purchase_package_name', 'Rollo')
            ->assertJsonPath('calc_config.type', 'wood');

        $productId = $res->json('id');
        $product = Product::findOrFail($productId);
        $this->assertEquals(100.0, (float) $product->conversion_factor);
        $this->assertArrayNotHasKey('evil_extra_key', $product->calc_config ?? []);

        $updateRes = $this->putJson("/api/catalog/products/{$productId}", [
            'name' => 'Cable Unipolar 2.5mm Normalizado',
            'unit_type' => 'rollo',
            'is_sold_by_weight' => false,
            'selling_price' => 900,
            'cost_price' => 450,
            'supplier_sku' => 'CAB-250-PRO',
            'storage_location' => 'Galpón 1 - Rack A2',
            'technical_spec' => '2.5mm Rojo',
            'purchase_package_name' => 'Caja',
            'conversion_factor' => 10,
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('unit_type', 'rollo')
            ->assertJsonPath('is_sold_by_weight', false)
            ->assertJsonPath('supplier_sku', 'CAB-250-PRO')
            ->assertJsonPath('storage_location', 'Galpón 1 - Rack A2')
            ->assertJsonPath('technical_spec', '2.5mm Rojo')
            ->assertJsonPath('purchase_package_name', 'Caja');
    }

    public function test_supplier_sku_can_be_repeated_across_different_products_and_supports_all_hardware_units(): void
    {
        $supA = Supplier::create(['name' => 'Distribuidora A', 'is_active' => true]);
        $supB = Supplier::create(['name' => 'Distribuidora B', 'is_active' => true]);

        $this->postJson('/api/catalog/products', [
            'name' => 'Arena Fina x Metro Cúbico',
            'supplier_sku' => 'SKU-COMUN-10',
            'supplier_id' => $supA->id,
            'unit_type' => 'm3',
            'cost_price' => 15000,
            'selling_price' => 24000,
            'stock' => 12,
        ])->assertStatus(201);

        $this->postJson('/api/catalog/products', [
            'name' => 'Cemento Portland 50kg',
            'supplier_sku' => 'SKU-COMUN-10',
            'supplier_id' => $supB->id,
            'unit_type' => 'bolsa',
            'cost_price' => 7000,
            'selling_price' => 10500,
            'stock' => 80,
        ])->assertStatus(201);

        foreach (['m2', 'caja', 'par'] as $unit) {
            $this->postJson('/api/catalog/products', [
                'name' => "Material Unidad {$unit}",
                'unit_type' => $unit,
                'cost_price' => 100,
                'selling_price' => 200,
                'stock' => 5,
            ])->assertStatus(201)->assertJsonPath('unit_type', $unit);
        }
    }

    public function test_pos_and_catalog_search_and_sort_include_supplier_sku_technical_spec_and_storage_location(): void
    {
        $p1 = Product::create([
            'name' => 'Bulón Cabeza Hexagonal',
            'internal_code' => '90001',
            'supplier_sku' => 'TEL-4021',
            'storage_location' => 'Gaveta Z9',
            'technical_spec' => '1/2 x 3 pulgadas',
            'unit_type' => 'un',
            'cost_price' => 120,
            'selling_price' => 250,
            'stock' => 50,
            'active' => true,
        ]);

        $p2 = Product::create([
            'name' => 'Tornillo Autoperforante',
            'internal_code' => '90002',
            'supplier_sku' => 'BRE-1002',
            'storage_location' => 'Gaveta A1',
            'technical_spec' => '10x1 T2',
            'unit_type' => 'caja',
            'cost_price' => 900,
            'selling_price' => 1800,
            'stock' => 15,
            'active' => true,
        ]);

        $p3 = Product::create([
            'name' => 'Producto Sin Ubicación',
            'internal_code' => '90003',
            'supplier_sku' => null,
            'storage_location' => null,
            'technical_spec' => null,
            'unit_type' => 'un',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 5,
            'active' => true,
        ]);

        // Búsqueda en POS por supplier_sku exacto y por combinación nombre + technical_spec
        $bySku = $this->getJson('/api/pos/products/search?query=TEL-4021')->assertOk()->json();
        $this->assertCount(1, $bySku);
        $this->assertSame($p1->id, $bySku[0]['id']);

        $bySpec = $this->getJson('/api/pos/products/search?query='.urlencode('cabeza 1/2'))->assertOk()->json();
        $this->assertCount(1, $bySpec);
        $this->assertSame($p1->id, $bySpec[0]['id']);

        // Búsqueda en Catálogo por supplier_sku y technical_spec
        $catBySpec = $this->getJson('/api/catalog/products?search='.urlencode('10x1 T2'))->assertOk()->json('data');
        $this->assertCount(1, $catBySpec);
        $this->assertSame($p2->id, $catBySpec[0]['id']);

        // Ordenamiento por storage_location deja NULLs al final
        $sortedLoc = collect(
            $this->getJson('/api/catalog/products?sort_by=storage_location&sort_direction=asc')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$p2->id, $p1->id, $p3->id], $sortedLoc);
    }
}
