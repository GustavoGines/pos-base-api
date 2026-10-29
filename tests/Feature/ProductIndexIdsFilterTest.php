<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OF-01: GET /api/catalog/products?ids=... permite al POS traer completos los productos en oferta
 * activa que quedaron fuera del bloque inicial de 500 del Acceso Rápido.
 */
class ProductIndexIdsFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(User::factory()->create(['role' => 'admin']));

        foreach (['Alfa', 'Beta', 'Gamma', 'Delta'] as $i => $name) {
            $this->products[] = Product::create([
                'name' => "Producto {$name}",
                'internal_code' => 'OF01-'.$i,
                'selling_price' => 100,
                'cost_price' => 50,
                'stock' => 10,
            ]);
        }
    }

    /** @return array<int, int> */
    private function idsFrom(string $query): array
    {
        return collect($this->getJson('/api/catalog/products?'.$query)->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_of01_filters_by_comma_separated_ids(): void
    {
        [$a, , $c] = $this->products;

        $this->assertSame([$a->id, $c->id], $this->idsFrom("ids={$a->id},{$c->id}"));
    }

    public function test_of01_accepts_array_syntax_and_ignores_invalid_values(): void
    {
        [, $b, , $d] = $this->products;

        $this->assertSame(
            [$b->id, $d->id],
            $this->idsFrom("ids[]={$b->id}&ids[]=abc&ids[]=-5&ids[]={$d->id}&ids[]={$d->id}")
        );
    }

    public function test_of01_empty_or_invalid_ids_return_no_products(): void
    {
        $this->assertSame([], $this->idsFrom('ids='));
        $this->assertSame([], $this->idsFrom('ids=abc,0,-1'));
    }

    public function test_of01_without_ids_param_keeps_legacy_listing(): void
    {
        $this->assertCount(4, $this->idsFrom('per_page=500'));
    }

    public function test_of01_returns_full_product_payload_like_regular_listing(): void
    {
        $a = $this->products[0];

        $row = $this->getJson("/api/catalog/products?ids={$a->id}")->assertOk()->json('data.0');

        foreach (['stock', 'is_sold_by_weight', 'is_combo', 'children', 'price_tiers', 'category'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }
}
