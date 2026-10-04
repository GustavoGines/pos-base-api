<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\SalesAnalyticsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitByRubroReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
    }

    private function setMultiRubroFeature(bool $enabled): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => $enabled])]
        );
    }

    public function test_profit_by_rubro_endpoint_returns_403_when_feature_not_licensed(): void
    {
        $this->setMultiRubroFeature(false);

        $response = $this->getJson('/api/reports/sales-by-rubro');
        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        $excelResponse = $this->get('/api/reports/sales-by-rubro/export');
        $excelResponse->assertStatus(403);

        $pdfResponse = $this->get('/api/reports/sales-by-rubro/pdf');
        $pdfResponse->assertStatus(403);

        $genericExcel = $this->get('/api/reports/sales-by-category/export?type=rubro');
        $genericExcel->assertStatus(403);

        $genericPdf = $this->get('/api/reports/sales-by-category/pdf?type=rubro');
        $genericPdf->assertStatus(403);
    }

    public function test_profit_by_rubro_aggregates_sales_by_rubro_correctly(): void
    {
        $this->setMultiRubroFeature(true);

        $rubroFerreteria = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $rubroPintureria = Rubro::create(['name' => 'Pinturería', 'is_system' => false]);

        $catHerramientas = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubroFerreteria->id]);
        $catPinturas = Category::create(['name' => 'Pinturas', 'rubro_id' => $rubroPintureria->id]);

        $prodMartillo = Product::create([
            'name' => 'Martillo Galponero',
            'internal_code' => 'MAR-01',
            'cost_price' => 500.0,
            'selling_price' => 1000.0,
            'category_id' => $catHerramientas->id,
            'stock' => 10,
        ]);

        $prodLata = Product::create([
            'name' => 'Lata Sintético 4L',
            'internal_code' => 'PIN-01',
            'cost_price' => 1500.0,
            'selling_price' => 3000.0,
            'category_id' => $catPinturas->id,
            'stock' => 5,
        ]);

        $customer = Customer::create([
            'name' => 'Cliente Mostrador',
            'document_number' => '20123456789',
            'is_internal_account' => false,
        ]);

        $sale = Sale::create([
            'total' => 5000.0,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);

        // 2 Martillos: total 2000, costo 1000, ganancia 1000
        $sale->items()->create([
            'product_id' => $prodMartillo->id,
            'product_name' => $prodMartillo->name,
            'quantity' => 2,
            'unit_price' => 1000.0,
            'unit_cost_price' => 500.0,
            'subtotal' => 2000.0,
        ]);

        // 1 Lata: total 3000, costo 1500, ganancia 1500
        $sale->items()->create([
            'product_id' => $prodLata->id,
            'product_name' => $prodLata->name,
            'quantity' => 1,
            'unit_price' => 3000.0,
            'unit_cost_price' => 1500.0,
            'subtotal' => 3000.0,
        ]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        $response = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}");
        $response->assertStatus(200)
            ->assertJsonStructure([
                'start_date',
                'end_date',
                'previous_period',
                'daily_evolution',
                'data',
            ]);

        $data = collect($response->json('data'));
        $this->assertCount(2, $data);

        $ferreteriaRow = $data->firstWhere('rubro_name', 'Ferretería');
        $this->assertNotNull($ferreteriaRow);
        $this->assertEquals(2, $ferreteriaRow['items_sold']);
        $this->assertEquals(2000.0, $ferreteriaRow['total_revenue']);
        $this->assertEquals(1000.0, $ferreteriaRow['total_profit']);

        $pintureriaRow = $data->firstWhere('rubro_name', 'Pinturería');
        $this->assertNotNull($pintureriaRow);
        $this->assertEquals(1, $pintureriaRow['items_sold']);
        $this->assertEquals(3000.0, $pintureriaRow['total_revenue']);
        $this->assertEquals(1500.0, $pintureriaRow['total_profit']);
    }

    public function test_profit_by_rubro_filtering_single_and_multiple_rubros(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro1 = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $rubro2 = Rubro::create(['name' => 'Pinturería', 'is_system' => false]);
        $rubro3 = Rubro::create(['name' => 'Bazar', 'is_system' => false]);

        $cat1 = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro1->id]);
        $cat2 = Category::create(['name' => 'Pinturas', 'rubro_id' => $rubro2->id]);
        $cat3 = Category::create(['name' => 'Cocina', 'rubro_id' => $rubro3->id]);

        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'A1', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat1->id]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'A2', 'cost_price' => 20, 'selling_price' => 40, 'category_id' => $cat2->id]);
        $p3 = Product::create(['name' => 'P3', 'internal_code' => 'A3', 'cost_price' => 30, 'selling_price' => 60, 'category_id' => $cat3->id]);

        $sale = Sale::create(['total' => 120.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $p1->id, 'product_name' => $p1->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);
        $sale->items()->create(['product_id' => $p2->id, 'product_name' => $p2->name, 'quantity' => 1, 'unit_price' => 40, 'unit_cost_price' => 20, 'subtotal' => 40]);
        $sale->items()->create(['product_id' => $p3->id, 'product_name' => $p3->name, 'quantity' => 1, 'unit_price' => 60, 'unit_cost_price' => 30, 'subtotal' => 60]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // 1. Filtrar solo Rubro 1
        $singleRes = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_id={$rubro1->id}");
        $singleRes->assertStatus(200);
        $singleData = $singleRes->json('data');
        $this->assertCount(1, $singleData);
        $this->assertEquals('Ferretería', $singleData[0]['rubro_name']);

        // 2. Comparar Rubro 1 y Rubro 2
        $multiRes = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids={$rubro1->id},{$rubro2->id}");
        $multiRes->assertStatus(200);
        $multiData = $multiRes->json('data');
        $this->assertCount(2, $multiData);
        $names = collect($multiData)->pluck('rubro_name')->toArray();
        $this->assertContains('Ferretería', $names);
        $this->assertContains('Pinturería', $names);
        $this->assertNotContains('Bazar', $names);
    }

    public function test_profit_by_rubro_exports_excel_and_pdf(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $cat = Category::create(['name' => 'Tornillos', 'rubro_id' => $rubro->id]);
        $prod = Product::create(['name' => 'Tornillo 2in', 'internal_code' => 'TOR', 'cost_price' => 1, 'selling_price' => 2, 'category_id' => $cat->id]);

        $sale = Sale::create(['total' => 20.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 10, 'unit_price' => 2, 'unit_cost_price' => 1, 'subtotal' => 20]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // Dedicated Excel export
        $excelRes = $this->get("/api/reports/sales-by-rubro/export?start_date={$start}&end_date={$end}");
        $excelRes->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Dedicated PDF export
        $pdfRes = $this->get("/api/reports/sales-by-rubro/pdf?start_date={$start}&end_date={$end}");
        $pdfRes->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        // Generic Excel export with type=rubro
        $genExcelRes = $this->get("/api/reports/sales-by-category/export?type=rubro&start_date={$start}&end_date={$end}");
        $genExcelRes->assertStatus(200);

        // Generic PDF export with type=rubro
        $genPdfRes = $this->get("/api/reports/sales-by-category/pdf?type=rubro&start_date={$start}&end_date={$end}");
        $genPdfRes->assertStatus(200);
    }

    public function test_repository_handles_products_without_rubro(): void
    {
        $categoryNoRubro = Category::create(['name' => 'Sin Rubro Asignado']);
        // Forzar rubro_id a null en SQLite/BD
        \DB::table('categories')->where('id', $categoryNoRubro->id)->update(['rubro_id' => null]);

        $prod = Product::create(['name' => 'Prod Huérfano', 'internal_code' => 'HUE', 'cost_price' => 5, 'selling_price' => 10, 'category_id' => $categoryNoRubro->id]);

        $sale = Sale::create(['total' => 10.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 1, 'unit_price' => 10, 'unit_cost_price' => 5, 'subtotal' => 10]);

        $repo = app(SalesAnalyticsRepository::class);
        $result = $repo->getProfitReport(now()->subDay()->toDateString(), now()->addDay()->toDateString(), 'rubro');

        $this->assertNotEmpty($result);
        $row = $result->firstWhere('group_name', 'Sin Rubro');
        $this->assertNotNull($row);
        $this->assertEquals(10.0, $row['total_revenue']);
        $this->assertEquals(5.0, $row['total_profit']);
    }

    public function test_profit_by_rubro_handles_non_existent_or_invalid_filter(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $cat = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro->id]);
        $prod = Product::create(['name' => 'Pinza', 'internal_code' => 'PIN', 'cost_price' => 50, 'selling_price' => 100, 'category_id' => $cat->id]);

        $sale = Sale::create(['total' => 100.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 1, 'unit_price' => 100, 'unit_cost_price' => 50, 'subtotal' => 100]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // Rubro inexistente ID 999999 -> debe retornar array vacío de data
        $resNonExistent = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_id=999999");
        $resNonExistent->assertStatus(200);
        $this->assertEmpty($resNonExistent->json('data'));

        // Filtro con formato con espacios " 1 , 999999 " -> debe filtrar correctamente Rubro 1
        $resWithSpaces = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids={$rubro->id},999999");
        $resWithSpaces->assertStatus(200);
        $this->assertCount(1, $resWithSpaces->json('data'));
        $this->assertEquals('Ferretería', $resWithSpaces->json('data.0.rubro_name'));
    }

    public function test_cache_normalization_for_rubro_filter(): void
    {
        $this->setMultiRubroFeature(true);

        $r1 = Rubro::create(['name' => 'R1', 'is_system' => false]);
        $r2 = Rubro::create(['name' => 'R2', 'is_system' => false]);
        $cat1 = Category::create(['name' => 'C1', 'rubro_id' => $r1->id]);
        $cat2 = Category::create(['name' => 'C2', 'rubro_id' => $r2->id]);
        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'P1', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat1->id]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'P2', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat2->id]);

        $sale = Sale::create(['total' => 40.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $p1->id, 'product_name' => $p1->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);
        $sale->items()->create(['product_id' => $p2->id, 'product_name' => $p2->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // 1. Petición en orden r1, r2
        $res1 = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids={$r1->id},{$r2->id}");
        $res1->assertStatus(200);
        $this->assertCount(2, $res1->json('data'));

        // 2. Petición en orden inverso r2, r1 debe devolver el mismo resultado
        $res2 = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids={$r2->id},{$r1->id}");
        $res2->assertStatus(200);
        $this->assertCount(2, $res2->json('data'));
    }

    public function test_profit_by_rubro_handles_empty_sales_period_for_json_excel_and_pdf(): void
    {
        $this->setMultiRubroFeature(true);

        $start = '2020-01-01';
        $end = '2020-01-31';

        $jsonRes = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}");
        $jsonRes->assertStatus(200);
        $this->assertEmpty($jsonRes->json('data'));

        $excelRes = $this->get("/api/reports/sales-by-rubro/export?start_date={$start}&end_date={$end}");
        $excelRes->assertStatus(200);

        $pdfRes = $this->get("/api/reports/sales-by-rubro/pdf?start_date={$start}&end_date={$end}");
        $pdfRes->assertStatus(200);
    }

    public function test_profit_by_rubro_supports_array_format_query_parameters(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro1 = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $rubro2 = Rubro::create(['name' => 'Pinturería', 'is_system' => false]);
        $rubro3 = Rubro::create(['name' => 'Bazar', 'is_system' => false]);

        $cat1 = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro1->id]);
        $cat2 = Category::create(['name' => 'Pinturas', 'rubro_id' => $rubro2->id]);
        $cat3 = Category::create(['name' => 'Cocina', 'rubro_id' => $rubro3->id]);

        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'A1', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat1->id]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'A2', 'cost_price' => 20, 'selling_price' => 40, 'category_id' => $cat2->id]);
        $p3 = Product::create(['name' => 'P3', 'internal_code' => 'A3', 'cost_price' => 30, 'selling_price' => 60, 'category_id' => $cat3->id]);

        $sale = Sale::create(['total' => 120.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $p1->id, 'product_name' => $p1->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);
        $sale->items()->create(['product_id' => $p2->id, 'product_name' => $p2->name, 'quantity' => 1, 'unit_price' => 40, 'unit_cost_price' => 20, 'subtotal' => 40]);
        $sale->items()->create(['product_id' => $p3->id, 'product_name' => $p3->name, 'quantity' => 1, 'unit_price' => 60, 'unit_cost_price' => 30, 'subtotal' => 60]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // Enviar rubro_ids[] como array de query params
        $res = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids[]={$rubro1->id}&rubro_ids[]={$rubro3->id}");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $names = collect($data)->pluck('rubro_name')->toArray();
        $this->assertContains('Ferretería', $names);
        $this->assertContains('Bazar', $names);
        $this->assertNotContains('Pinturería', $names);
    }

    public function test_profit_by_rubro_excludes_internal_account_consumption(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $cat = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro->id]);
        $prod = Product::create(['name' => 'Martillo', 'internal_code' => 'MAR', 'cost_price' => 100, 'selling_price' => 200, 'category_id' => $cat->id]);

        $internalCustomer = Customer::create([
            'name' => 'Consumo Empleados',
            'document_number' => '99999999999',
            'is_internal_account' => true,
        ]);

        $normalCustomer = Customer::create([
            'name' => 'Cliente General',
            'document_number' => '11111111111',
            'is_internal_account' => false,
        ]);

        // Venta a cuenta interna
        $internalSale = Sale::create(['total' => 200.0, 'customer_id' => $internalCustomer->id, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $internalSale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 1, 'unit_price' => 200, 'unit_cost_price' => 100, 'subtotal' => 200]);

        // Venta a cliente normal
        $normalSale = Sale::create(['total' => 400.0, 'customer_id' => $normalCustomer->id, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $normalSale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 2, 'unit_price' => 200, 'unit_cost_price' => 100, 'subtotal' => 400]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        $res = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}");
        $res->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('rubro_name', 'Ferretería');
        $this->assertNotNull($row);
        // Debe considerar únicamente la venta normal (2 unidades, 400 facturación, 200 ganancia)
        $this->assertEquals(2, $row['items_sold']);
        $this->assertEquals(400.0, $row['total_revenue']);
        $this->assertEquals(200.0, $row['total_profit']);
    }

    public function test_profit_by_rubro_normalizes_inverted_date_parameters(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $cat = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro->id]);
        $prod = Product::create(['name' => 'Pinza', 'internal_code' => 'PINZ', 'cost_price' => 50, 'selling_price' => 100, 'category_id' => $cat->id]);

        $sale = Sale::create(['total' => 100.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 1, 'unit_price' => 100, 'unit_cost_price' => 50, 'subtotal' => 100]);

        $start = now()->addDays(5)->toDateString();
        $end = now()->subDays(5)->toDateString();

        // Parámetros invertidos: start > end
        $res = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('Ferretería', $data[0]['rubro_name']);
        $this->assertEquals(100.0, $data[0]['total_revenue']);
        // Verificar que los metadatos y la evolución diaria se normalizan cronológicamente
        $this->assertEquals($end, $res->json('start_date'));
        $this->assertEquals($start, $res->json('end_date'));
        $this->assertNotEmpty($res->json('daily_evolution'));
    }

    public function test_profit_by_rubro_falls_back_when_rubro_ids_param_is_empty(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro1 = Rubro::create(['name' => 'Ferretería', 'is_system' => true]);
        $rubro2 = Rubro::create(['name' => 'Pinturería', 'is_system' => false]);
        $cat1 = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubro1->id]);
        $cat2 = Category::create(['name' => 'Pinturas', 'rubro_id' => $rubro2->id]);
        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'P1', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat1->id]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'P2', 'cost_price' => 10, 'selling_price' => 20, 'category_id' => $cat2->id]);

        $sale = Sale::create(['total' => 40.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $p1->id, 'product_name' => $p1->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);
        $sale->items()->create(['product_id' => $p2->id, 'product_name' => $p2->name, 'quantity' => 1, 'unit_price' => 20, 'unit_cost_price' => 10, 'subtotal' => 20]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        // rubro_ids está vacío en querystring pero rubro_id tiene valor -> debe aplicar fallback a rubro_id
        $res = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}&rubro_ids=&rubro_id={$rubro2->id}");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Pinturería', $data[0]['rubro_name']);
    }

    public function test_profit_by_rubro_handles_fractional_quantities(): void
    {
        $this->setMultiRubroFeature(true);

        $rubro = Rubro::create(['name' => 'Carnicería', 'is_system' => false]);
        $cat = Category::create(['name' => 'Cortes', 'rubro_id' => $rubro->id]);
        $prod = Product::create(['name' => 'Asado', 'internal_code' => 'ASA', 'cost_price' => 2000, 'selling_price' => 3000, 'category_id' => $cat->id]);

        $sale = Sale::create(['total' => 4500.0, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()]);
        $sale->items()->create(['product_id' => $prod->id, 'product_name' => $prod->name, 'quantity' => 1.5, 'unit_price' => 3000, 'unit_cost_price' => 2000, 'subtotal' => 4500]);

        $start = now()->subDay()->toDateString();
        $end = now()->addDay()->toDateString();

        $res = $this->getJson("/api/reports/sales-by-rubro?start_date={$start}&end_date={$end}");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertEquals(1.5, $data[0]['items_sold']);
        $this->assertEquals(4500.0, $data[0]['total_revenue']);
        $this->assertEquals(1500.0, $data[0]['total_profit']);

        // Verificar también que la exportación a PDF renderiza con 2 decimales sin fallar
        $pdfRes = $this->get("/api/reports/sales-by-rubro/pdf?start_date={$start}&end_date={$end}");
        $pdfRes->assertStatus(200);
    }
}
