<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\CashShift;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceWebhook;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BlockTwoRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CashShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
        $this->shift = $this->crearTurnoAbierto(1000.00, $this->admin);
    }

    /**
     * FINDING-STOCK-01: Ajuste de stock bloquea registro y despacha sincronización
     * tanto del producto ajustado como de los combos padres vinculados.
     */
    public function test_stock_adjust_dispatches_sync_for_item_and_parent_combos(): void
    {
        Queue::fake([SyncProductStockToPlatformsJob::class]);

        $child = Product::create([
            'name' => 'Tornillo Acero 1/2',
            'internal_code' => 'TOR-01',
            'cost_price' => 10.0,
            'selling_price' => 20.0,
            'stock' => 100.0,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Pack Fijación',
            'internal_code' => 'PACK-01',
            'cost_price' => 50.0,
            'selling_price' => 100.0,
            'stock' => 0.0,
            'is_combo' => true,
            'active' => true,
        ]);
        $combo->children()->attach($child->id, ['quantity' => 5]);

        $response = $this->postJson("/api/catalog/products/{$child->id}/adjust-stock", [
            'type' => 'out',
            'quantity' => 10,
            'reason' => 'Ajuste de prueba por rotura',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(90.0, (float) $child->fresh()->stock);

        // Se deben despachar jobs para el hijo ajustado y para el combo padre
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($child) {
            return $job->productId === $child->id;
        });

        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($combo) {
            return $job->productId === $combo->id;
        });
    }

    /**
     * FINDING-STOCK-04: Anulación de venta de combo restituye stock de hijos
     * y despacha sincronización para los componentes físicos y combos afectados.
     */
    public function test_void_sale_with_combo_dispatches_sync_for_child_components(): void
    {
        $child = Product::create([
            'name' => 'Cerveza Artesanal 500ml',
            'internal_code' => 'CER-01',
            'cost_price' => 500.0,
            'selling_price' => 1000.0,
            'stock' => 18.0, // Ya se habían deducido 2 por la venta previa
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Combo Degustación x2',
            'internal_code' => 'COMBO-DEG',
            'cost_price' => 1000.0,
            'selling_price' => 1800.0,
            'stock' => 0.0,
            'is_combo' => true,
            'active' => true,
        ]);
        $combo->children()->attach($child->id, ['quantity' => 2]);

        $metodo = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'total' => 1800.0,
            'total_surcharge' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $combo->id,
            'product_name' => $combo->name,
            'quantity' => 1,
            'unit_price' => 1800.0,
            'unit_cost_price' => 1000.0,
            'subtotal' => 1800.0,
            'is_combo' => true,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount' => 1800.0,
            'surcharge_amount' => 0,
            'total_amount' => 1800.0,
        ]);

        Queue::fake([SyncProductStockToPlatformsJob::class]);

        // Anular la venta
        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'reason' => 'Cliente canceló compra',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(20.0, (float) $child->fresh()->stock);

        // Debe haberse despachado sincronización para el hijo restituido y para el combo
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($child) {
            return $job->productId === $child->id;
        });

        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($combo) {
            return $job->productId === $combo->id;
        });
    }

    /**
     * FINDING-STOCK-02: Webhook entrante de ecommerce que descuenta un componente
     * propaga el recálculo y sincronización de stock al combo padre.
     */
    public function test_inbound_ecommerce_webhook_propagates_combo_stock(): void
    {
        $integration = EcommerceIntegration::create([
            'platform' => 'mercadolibre',
            'name' => 'MercadoLibre Tienda Oficial',
            'is_active' => true,
            'credentials' => ['access_token' => 'test_token'],
        ]);

        $child = Product::create([
            'name' => 'Memoria RAM 8GB',
            'internal_code' => 'RAM-8GB',
            'cost_price' => 15000.0,
            'selling_price' => 25000.0,
            'stock' => 10.0,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Kit Upgrade PC Gamer',
            'internal_code' => 'KIT-GAMER',
            'cost_price' => 30000.0,
            'selling_price' => 50000.0,
            'stock' => 0.0,
            'is_combo' => true,
            'active' => true,
        ]);
        $combo->children()->attach($child->id, ['quantity' => 2]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $child->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_RAM_8GB',
            'sync_stock' => true,
        ]);

        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_BLOCK2_01',
            'payload' => [
                'id' => 'ORD-98765',
                'items' => [
                    ['external_product_id' => 'MLA_RAM_8GB', 'quantity' => 2],
                ],
            ],
            'status' => 'received',
        ]);

        Queue::fake([SyncProductStockToPlatformsJob::class]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        $this->assertEquals(8.0, (float) $child->fresh()->stock);

        // Se debió despachar sincronización para el hijo y para el combo padre
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($child) {
            return $job->productId === $child->id;
        });

        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($combo) {
            return $job->productId === $combo->id;
        });
    }
}
