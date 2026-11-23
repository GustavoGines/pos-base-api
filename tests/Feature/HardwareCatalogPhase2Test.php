<?php

namespace Tests\Feature;

use App\Exports\CatalogImportTemplateExport;
use App\Exports\PriceListExport;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HardwareCatalogPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'app_plan'],
            ['value' => 'premium']
        );

        $user = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $this->actingAsAdmin($user);

        Rubro::create([
            'name' => 'General',
            'is_system' => true,
        ]);
    }

    public function test_import_csv_with_default_supplier_margin_and_hardware_columns(): void
    {
        $supplier = Supplier::create([
            'name' => 'Distribuidora Ferretera Sur',
            'is_active' => true,
        ]);

        $csvContent = "cod_proveedor,nombre,medida,ubicacion,unidad,presentacion_compra,factor_conversion,costo,stock\n"
            ."CAB-400,Cable Subterraneo Violeta,4x4mm,Pasillo 3 - B1,mt,Rollo,100,1000,200\n"
            ."CEM-50,Cemento Portland 50kg,50kg,Patio 1,bolsa,,1,8000,30\n";

        $file = UploadedFile::fake()->createWithContent('lista_proveedor.csv', $csvContent);

        $response = $this->postJson('/api/catalog/products/import', [
            'file' => $file,
            'default_supplier_id' => $supplier->id,
            'default_margin_percent' => 50,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Importación completada',
                'created_count' => 2,
                'updated_count' => 0,
                'failed_count' => 0,
            ]);

        $cable = Product::where('supplier_sku', 'CAB-400')->firstOrFail();
        $this->assertSame($supplier->id, (int) $cable->supplier_id);
        $this->assertSame('4x4mm', $cable->technical_spec);
        $this->assertSame('Pasillo 3 - B1', $cable->storage_location);
        $this->assertSame('mt', $cable->unit_type);
        $this->assertTrue((bool) $cable->is_sold_by_weight);
        $this->assertSame('Rollo', $cable->purchase_package_name);
        $this->assertEquals(100.0, (float) $cable->conversion_factor);
        $this->assertEquals(1000.0, (float) $cable->cost_price);
        $this->assertEquals(1500.0, (float) $cable->selling_price);
        $this->assertEquals(200.0, (float) $cable->stock);

        $cement = Product::where('supplier_sku', 'CEM-50')->firstOrFail();
        $this->assertSame($supplier->id, (int) $cement->supplier_id);
        $this->assertSame('bolsa', $cement->unit_type);
        $this->assertFalse((bool) $cement->is_sold_by_weight);
        $this->assertEquals(8000.0, (float) $cement->cost_price);
        $this->assertEquals(12000.0, (float) $cement->selling_price);
    }

    public function test_reimport_matches_by_supplier_sku_and_default_supplier_without_overwriting_other_supplier(): void
    {
        $supA = Supplier::create(['name' => 'Proveedor Alfa', 'is_active' => true]);
        $supB = Supplier::create(['name' => 'Proveedor Beta', 'is_active' => true]);

        $prodA = Product::create([
            'name' => 'Llave Combinada 12mm Alfa',
            'internal_code' => '70001',
            'supplier_id' => $supA->id,
            'supplier_sku' => 'SKU-COMUN',
            'cost_price' => 1000,
            'selling_price' => 1500,
            'stock' => 10,
            'unit_type' => 'un',
            'active' => true,
        ]);

        $prodB = Product::create([
            'name' => 'Pinza Fuerza Beta',
            'internal_code' => '70002',
            'supplier_id' => $supB->id,
            'supplier_sku' => 'SKU-COMUN',
            'cost_price' => 3000,
            'selling_price' => 4500,
            'stock' => 5,
            'unit_type' => 'un',
            'active' => true,
        ]);

        $csvContent = "cod_proveedor,nombre,costo,stock\n"
            ."SKU-COMUN,Llave Combinada 12mm Alfa,2000,15\n";

        $file = UploadedFile::fake()->createWithContent('actualizacion_alfa.csv', $csvContent);

        $response = $this->postJson('/api/catalog/products/import', [
            'file' => $file,
            'default_supplier_id' => $supA->id,
            'default_margin_percent' => 40,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'created_count' => 0,
                'updated_count' => 1,
                'failed_count' => 0,
            ]);

        $prodA->refresh();
        $prodB->refresh();

        $this->assertEquals(2000.0, (float) $prodA->cost_price);
        $this->assertEquals(2800.0, (float) $prodA->selling_price);
        $this->assertEquals(15.0, (float) $prodA->stock);

        // El producto de otro proveedor con el mismo supplier_sku queda intacto
        $this->assertEquals(3000.0, (float) $prodB->cost_price);
        $this->assertEquals(4500.0, (float) $prodB->selling_price);
        $this->assertEquals(5.0, (float) $prodB->stock);
    }

    public function test_import_template_includes_new_hardware_and_supplier_columns(): void
    {
        $export = new CatalogImportTemplateExport;
        $headings = $export->headings();

        $this->assertContains('cod_proveedor', $headings);
        $this->assertContains('medida', $headings);
        $this->assertContains('ubicacion', $headings);
        $this->assertContains('presentacion_compra', $headings);
        $this->assertContains('factor_conversion', $headings);
    }

    public function test_price_list_export_preserves_hardware_units_and_extended_columns(): void
    {
        $supplier = Supplier::create([
            'name' => 'Distribuidora Industrial',
            'is_active' => true,
        ]);

        $cable = Product::create([
            'name' => 'Cable Subterraneo 4x4',
            'internal_code' => '70020',
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'CAB-900',
            'technical_spec' => '4x4mm',
            'storage_location' => 'Pasillo 2',
            'purchase_package_name' => 'Rollo',
            'conversion_factor' => 100.0,
            'cost_price' => 1000,
            'selling_price' => 1500,
            'stock' => 250,
            'unit_type' => 'mt',
            'is_sold_by_weight' => true,
            'active' => true,
        ]);

        $weightRetail = Product::create([
            'name' => 'Clavos Punta Paris',
            'internal_code' => '70021',
            'cost_price' => 500,
            'selling_price' => 900,
            'stock' => 25,
            'unit_type' => 'un',
            'is_sold_by_weight' => true,
            'active' => true,
        ]);

        $export = new PriceListExport;
        $headings = $export->headings();

        $this->assertSame([
            'codigo_interno',
            'codigo_barras',
            'nombre',
            'categoria',
            'marca',
            'proveedor',
            'costo',
            'precio',
            'stock',
            'stock_minimo',
            'unidad',
            'vencimiento_dias',
            'activo',
            'cod_proveedor',
            'medida',
            'ubicacion',
            'presentacion_compra',
            'factor_conversion',
        ], $headings);

        $cableRow = $export->map($cable);
        $this->assertSame('mt', $cableRow[10]);
        $this->assertSame('CAB-900', $cableRow[13]);
        $this->assertSame('4x4mm', $cableRow[14]);
        $this->assertSame('Pasillo 2', $cableRow[15]);
        $this->assertSame('Rollo', $cableRow[16]);
        $this->assertSame(100.0, $cableRow[17]);

        $weightRow = $export->map($weightRetail);
        $this->assertSame('kg', $weightRow[10]);
    }

    public function test_import_truncates_purchase_package_name_to_40_chars(): void
    {
        $longPkg = str_repeat('X', 55);
        $csvContent = "nombre,unidad,presentacion_compra,factor_conversion,costo,precio\n"
            ."Alambre Galvanizado,mt,{$longPkg},50,100,150\n";

        $file = UploadedFile::fake()->createWithContent('truncamiento.csv', $csvContent);

        $response = $this->postJson('/api/catalog/products/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'created_count' => 1,
                'failed_count' => 0,
            ]);

        $prod = Product::where('name', 'Alambre Galvanizado')->firstOrFail();
        $this->assertSame(40, mb_strlen($prod->purchase_package_name));
    }

    public function test_purchase_invoice_auto_links_supplier_when_product_has_no_supplier_without_overwriting_existing_supplier(): void
    {
        $supA = Supplier::create(['name' => 'Corralón Proveedor A', 'is_active' => true]);
        $supB = Supplier::create(['name' => 'Distribuidora Proveedor B', 'is_active' => true]);

        $sinProveedor = Product::create([
            'name' => 'Cal Hidratada 25kg',
            'internal_code' => '70010',
            'supplier_id' => null,
            'cost_price' => 2000,
            'selling_price' => 3000,
            'stock' => 2,
            'unit_type' => 'bolsa',
            'active' => true,
        ]);

        $conOtroProveedor = Product::create([
            'name' => 'Cemento Blanco 10kg',
            'internal_code' => '70011',
            'supplier_id' => $supB->id,
            'cost_price' => 4000,
            'selling_price' => 6000,
            'stock' => 1,
            'unit_type' => 'bolsa',
            'active' => true,
        ]);

        $response = $this->postJson("/api/suppliers/{$supA->id}/invoices", [
            'type' => 'invoice',
            'invoice_number' => '0001-00001234',
            'amount' => 14000,
            'items' => [
                [
                    'product_id' => $sinProveedor->id,
                    'quantity' => 3,
                    'unit_cost' => 2000,
                    'new_selling_price' => 3200,
                    'subtotal' => 6000,
                ],
                [
                    'product_id' => $conOtroProveedor->id,
                    'quantity' => 2,
                    'unit_cost' => 4000,
                    'new_selling_price' => 6200,
                    'subtotal' => 8000,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $sinProveedor->refresh();
        $conOtroProveedor->refresh();

        $this->assertSame($supA->id, (int) $sinProveedor->supplier_id);
        $this->assertSame($supB->id, (int) $conOtroProveedor->supplier_id);
        $this->assertEquals(5.0, (float) $sinProveedor->stock);
        $this->assertEquals(3.0, (float) $conOtroProveedor->stock);
    }
}
