<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferHistoryAndRelaunchTest extends TestCase
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
            'cost_price' => 600,
            'selling_price' => 1000,
            'stock' => 100,
            'active' => true,
        ]);

        $this->product2 = Product::create([
            'name' => 'Galletitas Oreo',
            'internal_code' => 'OREO',
            'cost_price' => 700,
            'selling_price' => 800,
            'stock' => 50,
            'active' => true,
        ]);
    }

    public function test_index_filters_by_status_and_search_query()
    {
        // Activa
        $active = Offer::create([
            'name' => 'Promo Activa Bebidas',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'daily',
            'start_date' => now()->startOfDay(),
            'end_date' => now()->endOfDay(),
            'is_active' => true,
        ]);
        $active->products()->sync([$this->product1->id]);

        // Vencida
        $expired = Offer::create([
            'name' => 'Promo Vencida Pasada',
            'type' => 'percentage',
            'discount_percentage' => 15,
            'period_type' => 'daily',
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(4),
            'is_active' => true,
        ]);
        $expired->products()->sync([$this->product1->id]);

        // Pausada
        $paused = Offer::create([
            'name' => 'Promo Galletitas Pausada',
            'type' => 'percentage',
            'discount_percentage' => 20,
            'period_type' => 'weekly',
            'start_date' => now()->startOfDay(),
            'end_date' => now()->addDays(7),
            'is_active' => false,
        ]);
        $paused->products()->sync([$this->product2->id]);

        // Vencida (con is_active = false para verificar precedencia de expired sobre paused)
        $expiredPaused = Offer::create([
            'name' => 'Promo Vencida y Pausada',
            'type' => 'percentage',
            'discount_percentage' => 15,
            'period_type' => 'daily',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(8),
            'is_active' => false,
        ]);
        $expiredPaused->products()->sync([$this->product1->id]);
        $this->assertEquals('expired', $expiredPaused->status);

        // Filtrar por status=active
        $resActive = $this->getJson('/api/offers?status=active');
        $resActive->assertStatus(200)
            ->assertJsonFragment(['name' => 'Promo Activa Bebidas'])
            ->assertJsonMissing(['name' => 'Promo Vencida Pasada'])
            ->assertJsonMissing(['name' => 'Promo Galletitas Pausada']);

        // Filtrar por status=expired (debe incluir tanto la vencida con is_active=true como la vencida con is_active=false)
        $resExpired = $this->getJson('/api/offers?status=expired');
        $resExpired->assertStatus(200)
            ->assertJsonFragment(['name' => 'Promo Vencida Pasada'])
            ->assertJsonFragment(['name' => 'Promo Vencida y Pausada'])
            ->assertJsonMissing(['name' => 'Promo Activa Bebidas']);

        // Filtrar por status=paused (solo pausadas con vigencia no vencida)
        $resPaused = $this->getJson('/api/offers?status=paused');
        $resPaused->assertStatus(200)
            ->assertJsonFragment(['name' => 'Promo Galletitas Pausada'])
            ->assertJsonMissing(['name' => 'Promo Vencida y Pausada'])
            ->assertJsonMissing(['name' => 'Promo Activa Bebidas']);

        // Búsqueda por texto (q)
        $resSearch = $this->getJson('/api/offers?q=Galletitas');
        $resSearch->assertStatus(200)
            ->assertJsonFragment(['name' => 'Promo Galletitas Pausada'])
            ->assertJsonMissing(['name' => 'Promo Activa Bebidas']);
    }

    public function test_toggle_status_pauses_and_resumes_offer()
    {
        $offer = Offer::create([
            'name' => 'Promo Toggle Test',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'daily',
            'is_active' => true,
        ]);

        // 1. Pausar
        $resPause = $this->patchJson("/api/offers/{$offer->id}/toggle-status");
        $resPause->assertStatus(200)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'paused');

        $offer->refresh();
        $this->assertFalse($offer->is_active);

        // 2. Reanudar
        $resResume = $this->patchJson("/api/offers/{$offer->id}/toggle-status");
        $resResume->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $offer->refresh();
        $this->assertTrue($offer->is_active);

        // 3. Intentar pausar/reanudar una oferta ya vencida debe retornar 422
        $expiredOffer = Offer::create([
            'name' => 'Promo Vencida No Pausable',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'daily',
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(2),
            'is_active' => true,
        ]);

        $resExpiredToggle = $this->patchJson("/api/offers/{$expiredOffer->id}/toggle-status");
        $resExpiredToggle->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_relaunch_offer_with_duplicate_creates_new_offer_record()
    {
        $pastOffer = Offer::create([
            'name' => 'Promo Pasada Fin de Mes',
            'type' => 'percentage',
            'discount_percentage' => 10,
            'period_type' => 'daily',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(9),
            'is_active' => true,
        ]);
        $pastOffer->products()->sync([$this->product1->id]);

        $res = $this->postJson("/api/offers/{$pastOffer->id}/relaunch", [
            'period_type' => 'weekly',
            'duplicate' => true,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Promo Pasada Fin de Mes')
            ->assertJsonPath('data.period_type', 'weekly')
            ->assertJsonPath('data.is_active', true);

        $newId = $res->json('data.id');
        $this->assertNotEquals($pastOffer->id, $newId);

        // La oferta original sigue intacta
        $pastOffer->refresh();
        $this->assertTrue($pastOffer->end_date->isPast());

        // La nueva oferta tiene fecha vigente
        $newOffer = Offer::find($newId);
        $this->assertTrue($newOffer->end_date->isFuture());
        $this->assertEquals(1, $newOffer->products()->count());
    }

    public function test_relaunch_offer_in_place_reactivates_existing_offer()
    {
        $pastOffer = Offer::create([
            'name' => 'Promo Reactivar',
            'type' => 'percentage',
            'discount_percentage' => 12,
            'period_type' => 'daily',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(9),
            'is_active' => false,
        ]);
        $pastOffer->products()->sync([$this->product1->id]);

        $res = $this->postJson("/api/offers/{$pastOffer->id}/relaunch", [
            'period_type' => 'daily',
            'duplicate' => false,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $pastOffer->id)
            ->assertJsonPath('data.is_active', true);

        $pastOffer->refresh();
        $this->assertTrue($pastOffer->is_active);
        $this->assertTrue($pastOffer->end_date->isFuture() || $pastOffer->end_date->isToday());
    }

    public function test_relaunch_reports_margin_warning_when_discount_drops_below_cost()
    {
        // product2: Costo $700, Precio Venta $800
        // Si aplicamos 30% de descuento -> Precio con descuento = $560 (menor a $700 de costo!)
        $offer = Offer::create([
            'name' => 'Promo Riesgo Margen',
            'type' => 'percentage',
            'discount_percentage' => 30,
            'period_type' => 'daily',
            'is_active' => false,
        ]);
        $offer->products()->sync([$this->product2->id]);

        $res = $this->postJson("/api/offers/{$offer->id}/relaunch", [
            'period_type' => 'daily',
            'duplicate' => false,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $warnings = $res->json('margin_warnings');
        $this->assertNotEmpty($warnings);
        $this->assertEquals($this->product2->id, $warnings[0]['product_id']);
        $this->assertEquals(560, (float) $warnings[0]['discounted_price']);
        $this->assertEquals(700, (float) $warnings[0]['cost_price']);
    }
}
