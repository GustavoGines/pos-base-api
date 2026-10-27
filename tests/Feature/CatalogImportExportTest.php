<?php

namespace Tests\Feature;

use App\Jobs\ImportCatalogJob;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $this->actingAsAdmin($this->user);

        Rubro::create([
            'name' => 'General',
            'is_system' => true,
        ]);
    }

    public function test_download_import_template()
    {
        $response = $this->get('/api/catalog/products/import-template');

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=plantilla_importacion_productos.xlsx');
    }

    public function test_import_requires_file()
    {
        $response = $this->postJson('/api/catalog/products/import', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_import_csv_creates_products()
    {
        $csvContent = "codigo_interno,codigo_barras,nombre,costo,precio,stock,categoria,marca,iva,activo\n"
            ."TEST-001,7790001,Producto Importado 1,100,150,10,Bebidas,Coca,21,true\n"
            ."TEST-002,7790002,Producto Importado 2,200,300,5,Almacen,Pepsi,10.5,true\n";

        $file = UploadedFile::fake()->createWithContent('productos.csv', $csvContent);

        $response = $this->postJson('/api/catalog/products/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Importación completada',
                'created_count' => 2,
                'updated_count' => 0,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('products', [
            'internal_code' => 'TEST-001',
            'name' => 'Producto Importado 1',
            'selling_price' => 150,
        ]);

        $this->assertDatabaseHas('products', [
            'internal_code' => 'TEST-002',
            'name' => 'Producto Importado 2',
            'selling_price' => 300,
        ]);
    }

    public function test_import_csv_async_dispatches_job()
    {
        Queue::fake();

        $csvContent = "codigo_interno,codigo_barras,nombre,costo,precio,stock,categoria,marca,iva,activo\n"
            ."TEST-003,7790003,Producto Async,100,150,10,Bebidas,Coca,21,true\n";

        $file = UploadedFile::fake()->createWithContent('productos.csv', $csvContent);

        $response = $this->postJson('/api/catalog/products/import?async=1', [
            'file' => $file,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'message' => 'Importación iniciada en segundo plano.',
                'status' => 'processing',
            ]);

        Queue::assertPushed(ImportCatalogJob::class);
    }

    public function test_export_price_list()
    {
        Product::create([
            'name' => 'Producto Exportar',
            'internal_code' => 'EXP-001',
            'cost_price' => 150,
            'selling_price' => 250,
            'stock' => 50,
        ]);

        $response = $this->get('/api/catalog/products/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('lista_precios.xlsx', (string) $response->headers->get('content-disposition'));
    }
}
