<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferCrudTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $product1;
    protected $product2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        $this->product1 = Product::create([
            'name' => 'Coca Cola 1.5L',
            'internal_code' => 'COCA15',
            'cost_price' => 500,
            'selling_price' => 1000,
            'stock' => 100,
            'active' => true,
        ]);

        $this->product2 = Product::create([
            'name' => 'Papas Lays',
            'internal_code' => 'LAYS',
            'cost_price' => 300,
            'selling_price' => 800,
            'stock' => 50,
            'active' => true,
        ]);
    }

    public function test_complete_offer_crud_lifecycle()
    {
        // 1. Create (Store)
        $storeRes = $this->postJson('/api/offers', [
            'name' => 'Promo Gaseosas',
            'type' => 'percentage',
            'discount_percentage' => 15,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);

        $storeRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Promo Gaseosas');
        $this->assertEquals(15, (float) $storeRes->json('data.discount_percentage'));

        $offerId = $storeRes->json('data.id');

        // 2. Read (Index & Active)
        $indexRes = $this->getJson('/api/offers');
        $indexRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'Promo Gaseosas']);

        $activeRes = $this->getJson('/api/offers/active');
        $activeRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'Promo Gaseosas']);

        // 3. Update (PUT)
        $updateRes = $this->putJson("/api/offers/{$offerId}", [
            'name' => 'Promo Gaseosas y Snacks 20% OFF',
            'type' => 'percentage',
            'discount_percentage' => 20,
            'period_type' => 'weekly',
            'products' => [
                ['product_id' => $this->product1->id],
                ['product_id' => $this->product2->id],
            ],
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Promo Gaseosas y Snacks 20% OFF')
            ->assertJsonPath('data.period_type', 'weekly');
        $this->assertEquals(20, (float) $updateRes->json('data.discount_percentage'));

        $this->assertDatabaseHas('offers', [
            'id' => $offerId,
            'name' => 'Promo Gaseosas y Snacks 20% OFF',
            'discount_percentage' => 20,
        ]);

        // 4. Delete (Destroy)
        $deleteRes = $this->deleteJson("/api/offers/{$offerId}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('offers', [
            'id' => $offerId,
        ]);
    }

    public function test_cashier_without_apply_discounts_permission_cannot_mutate_offers()
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => [],
        ]);
        $this->actingAsAdmin($cashier);

        $res = $this->postJson('/api/offers', [
            'name' => 'Oferta No Autorizada',
            'type' => 'percentage',
            'discount_percentage' => 50,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);

        $res->assertStatus(403);
    }

    public function test_percentage_offer_requires_positive_discount_percentage()
    {
        $resZero = $this->postJson('/api/offers', [
            'name' => 'Oferta 0%',
            'type' => 'percentage',
            'discount_percentage' => 0,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);
        $resZero->assertStatus(422);

        $resNull = $this->postJson('/api/offers', [
            'name' => 'Oferta Null%',
            'type' => 'percentage',
            'discount_percentage' => null,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);
        $resNull->assertStatus(422);
    }

    public function test_update_offer_preserves_original_dates_when_period_type_unchanged()
    {
        $originalStart = now()->subDays(2)->startOfDay();
        $originalEnd = now()->addDays(5)->endOfDay();

        $offer = Offer::create([
            'name' => 'Promo Semanal Original',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'weekly',
            'start_date' => $originalStart,
            'end_date' => $originalEnd,
            'is_active' => true,
        ]);
        $offer->products()->sync([$this->product1->id => ['special_price' => null]]);

        $updateRes = $this->putJson("/api/offers/{$offer->id}", [
            'name' => 'Promo Semanal Renombrada',
            'type' => 'percentage',
            'discount_percentage' => 15,
            'period_type' => 'weekly',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);

        $updateRes->assertStatus(200);
        $offer->refresh();
        $this->assertEquals($originalStart->timestamp, $offer->start_date->timestamp);
        $this->assertEquals($originalEnd->timestamp, $offer->end_date->timestamp);
    }
}
