<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Búsqueda de productos por palabras sueltas (scope Product::matchingSearch).
 *
 * Cubre el listado del catálogo (/api/catalog/products?search=) y el buscador del POS
 * (/api/pos/products/search?query=).
 */
class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));

        $this->crear('STANLEY Termo Clasico 1L Verde', '00001', '7790001');
        $this->crear('STANLEY Termo Clasico 1L Negro', '00002', '7790002');
        $this->crear('Jarra Stanley Verde', '00003', '7790003');
        $this->crear('Monitor Xiaomi A27i', '00004', '7790004');
        $this->crear('Descuento 50% Promo', '00005', '7790005');
        $this->crear('Cable COD_USB', '00006', '7790006');
    }

    protected function crear(string $name, string $code, string $barcode): Product
    {
        return Product::create([
            'name' => $name,
            'internal_code' => $code,
            'barcode' => $barcode,
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
        ]);
    }

    /** @return array<int, string> */
    protected function nombresCatalogo(string $search): array
    {
        return collect($this->getJson('/api/catalog/products?search='.urlencode($search))->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_palabras_sueltas_encuentran_el_producto_aunque_haya_texto_en_el_medio(): void
    {
        $this->assertSame(
            ['Jarra Stanley Verde', 'STANLEY Termo Clasico 1L Verde'],
            $this->nombresCatalogo('stanley verde')
        );
    }

    public function test_el_orden_de_las_palabras_no_importa(): void
    {
        $this->assertSame($this->nombresCatalogo('stanley verde'), $this->nombresCatalogo('verde stanley'));
    }

    public function test_espacios_sobrantes_se_ignoran(): void
    {
        $this->assertCount(3, $this->nombresCatalogo('  stanley  '));
        $this->assertCount(2, $this->nombresCatalogo(' stanley    verde '));
    }

    public function test_una_palabra_sigue_funcionando_y_busca_en_barcode_y_codigo_interno(): void
    {
        $this->assertCount(3, $this->nombresCatalogo('stanley'));
        $this->assertSame(['Monitor Xiaomi A27i'], $this->nombresCatalogo('7790004'));
        $this->assertSame(['Monitor Xiaomi A27i'], $this->nombresCatalogo('00004'));
    }

    public function test_todas_las_palabras_deben_coincidir(): void
    {
        $this->assertSame([], $this->nombresCatalogo('stanley xiaomi'));
    }

    public function test_los_comodines_like_se_toman_como_texto_literal(): void
    {
        $this->assertSame(['Descuento 50% Promo'], $this->nombresCatalogo('50%'));
        $this->assertSame(['Cable COD_USB'], $this->nombresCatalogo('COD_USB'));
        // Un "_" suelto NO debe actuar como comodín de un carácter: solo matchea nombres con "_" real.
        $this->assertSame(['Cable COD_USB'], $this->nombresCatalogo('_'));
        $this->assertSame([], $this->nombresCatalogo('%%%zzz'));
    }

    public function test_buscador_del_pos_usa_palabras_sueltas_y_mantiene_codigo_exacto(): void
    {
        $porNombre = collect($this->getJson('/api/pos/products/search?query='.urlencode('verde stanley'))->assertOk()->json())
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['Jarra Stanley Verde', 'STANLEY Termo Clasico 1L Verde'], $porNombre);

        // Escáner: coincidencia exacta de código de barras
        $porBarcode = $this->getJson('/api/pos/products/search?query=7790004')->assertOk()->json();
        $this->assertCount(1, $porBarcode);
        $this->assertSame('Monitor Xiaomi A27i', $porBarcode[0]['name']);
    }
}
