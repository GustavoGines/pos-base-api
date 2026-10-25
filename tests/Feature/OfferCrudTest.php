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

    public function test_fixed_price_offer_rejects_products_with_selling_price_less_or_equal_to_discount_amount()
    {
        // Product 2 has selling_price = 800; discount_amount = 800 should be rejected (422)
        $resIncompatible = $this->postJson('/api/offers', [
            'name' => 'Oferta Monto Fijo Incompatible',
            'type' => 'fixed_price',
            'discount_amount' => 800,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
                ['product_id' => $this->product2->id],
            ],
        ]);
        $resIncompatible->assertStatus(422)
            ->assertJsonValidationErrors(['discount_amount']);

        // Valid fixed_price offer (discount_amount = 200 < 800)
        $resValid = $this->postJson('/api/offers', [
            'name' => 'Oferta $200 OFF',
            'type' => 'fixed_price',
            'discount_amount' => 200,
            'discount_percentage' => 15, // Should be nullified by controller
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
                ['product_id' => $this->product2->id],
            ],
        ]);
        $resValid->assertStatus(201);
        $offerId = $resValid->json('data.id');
        $this->assertNull($resValid->json('data.discount_percentage'));
        $this->assertEquals(200, (float) $resValid->json('data.discount_amount'));

        // Switch back to percentage on update: discount_amount should be nullified
        $resUpdate = $this->putJson("/api/offers/{$offerId}", [
            'name' => 'Oferta 10% OFF',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'discount_amount' => 200,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);
        $resUpdate->assertStatus(200);
        $this->assertNull($resUpdate->json('data.discount_amount'));
        $this->assertEquals(10, (float) $resUpdate->json('data.discount_percentage'));
    }

    public function test_rejects_soft_deleted_products_and_zero_discount_amount()
    {
        $this->product2->delete(); // Soft delete

        $resDeleted = $this->postJson('/api/offers', [
            'name' => 'Oferta con Producto Eliminado',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product2->id],
            ],
        ]);
        $resDeleted->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.product_id']);

        $resZeroAmt = $this->postJson('/api/offers', [
            'name' => 'Oferta $0 OFF',
            'type' => 'fixed_price',
            'discount_amount' => 0,
            'period_type' => 'daily',
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);
        $resZeroAmt->assertStatus(422)
            ->assertJsonValidationErrors(['discount_amount']);
    }

    public function test_scheduled_offer_calculates_end_date_from_start_date_not_now()
    {
        $futureStart = now()->addDays(10)->startOfDay();

        $resWeekly = $this->postJson('/api/offers', [
            'name' => 'Oferta Semanal Futura',
            'type' => 'percentage',
            'discount_percentage' => 15,
            'period_type' => 'weekly',
            'start_date' => $futureStart->toIso8601String(),
            'products' => [
                ['product_id' => $this->product1->id],
            ],
        ]);

        $resWeekly->assertStatus(201);
        $offer = Offer::find($resWeekly->json('data.id'));
        $this->assertEquals('scheduled', $offer->status);
        $this->assertEquals($futureStart->toDateString(), $offer->start_date->toDateString());
        $this->assertEquals($futureStart->copy()->addDays(7)->toDateString(), $offer->end_date->toDateString());
    }
}
