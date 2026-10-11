<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\Afip\AfipWsfeService;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EcommerceOutboundSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function createIntegration(string $platform, array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => $platform,
            'name' => 'Canal '.$platform,
            'client_id' => 'CLIENT_123',
            'client_secret' => 'SECRET_XYZ',
            'access_token' => 'TOKEN_ABC',
            'refresh_token' => 'REFRESH_123',
            'token_expires_at' => now()->addHours(6),
            'base_url' => 'https://mystore.test',
            'store_id' => '123456',
            'webhook_secret' => 'WH_SEC_999',
            'is_active' => true,
            'sync_stock_enabled' => true,
            'auto_pause_on_zero_stock' => true,
        ], $attributes));
    }

    public function test_outbound_job_calculates_allocated_stock_formula(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA100' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Taladro Percutor',
            'internal_code' => 'TAL-01',
            'cost_price' => 5000,
            'selling_price' => 9000,
            'stock' => 50,
            'active' => true,
        ]);

        // Formula: max(0, floor(($stock - threshold) * (percentage / 100)))
        // (50 - 10) * (50 / 100) = 40 * 0.5 = 20
        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA100',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 10.00,
            'allocated_stock_percentage' => 50.00,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($product->id, (float) $product->stock);
        $job->handle(app(EcommerceManager::class));

        // Assert HTTP PUT was sent with target stock = 20
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA100'
                && $request['available_quantity'] === 20
                && $request['status'] === 'active';
        });

        // Assert Anti-Echo cache is present
        $this->assertTrue(Cache::has('ecommerce_echo:mercadolibre:MLA100:20'));

        // Assert mapping is updated
        $mapping->refresh();
        $this->assertEquals('20.000', $mapping->last_known_external_stock);
        $this->assertEquals('synced', $mapping->sync_status);

        // Assert sync log is written
        $this->assertDatabaseHas('ecommerce_sync_logs', [
            'integration_id' => $integration->id,
            'mapping_id' => $mapping->id,
            'direction' => 'outbound',
            'action' => 'update_stock',
            'stock_after' => '20.000',
            'status' => 'success',
        ]);
    }

    public function test_outbound_job_sends_zero_when_mapping_is_paused_or_integration_inactive(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA200' => Http::response(['status' => 'paused'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Amoladora',
            'internal_code' => 'AMO-01',
            'cost_price' => 3000,
            'selling_price' => 6000,
            'stock' => 100,
            'active' => true,
        ]);

        // Paused mapping
        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA200',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'is_paused' => true, // PAUSED!
        ]);

        $job = new SyncProductStockToPlatformsJob($product->id, 100.0);
        $job->handle(app(EcommerceManager::class));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA200'
                && $request['available_quantity'] === 0
                && $request['status'] === 'paused';
        });
    }

    public function test_outbound_job_calculates_virtual_stock_for_combos(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/999' => Http::response(['id' => 999], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');

        // Combo: 1 destornillador + 2 puntas
        $child1 = Product::create([
            'name' => 'Mango Destornillador',
            'internal_code' => 'MNG-01',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10, // allows 10 combos
            'active' => true,
        ]);

        $child2 = Product::create([
            'name' => 'Punta Phillips',
            'internal_code' => 'PNT-01',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 6, // allows 6 / 2 = 3 combos (limiting child!)
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Set Destornillador + Puntas',
            'internal_code' => 'SET-01',
            'cost_price' => 200,
            'selling_price' => 400,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);

        $combo->children()->attach($child1->id, ['quantity' => 1]);
        $combo->children()->attach($child2->id, ['quantity' => 2]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $combo->id,
            'platform' => 'woocommerce',
            'external_product_id' => '999',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 0.00,
            'allocated_stock_percentage' => 100.00,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($combo->id);
        $job->handle(app(EcommerceManager::class));

        // Limiting child is child2 (6 / 2 = 3 combos available)
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/999'
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 3
                && $request['stock_status'] === 'instock'
                && $request['backorders'] === 'no';
        });
    }

    public function test_sale_service_dispatches_outbound_sync_job_after_commit(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);

        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'efectivo',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Pinza Universal',
            'internal_code' => 'PIN-01',
            'cost_price' => 1000,
            'selling_price' => 2000,
            'stock' => 15,
            'active' => true,
        ]);

        $saleService = new SaleService(
            app(StockService::class),
            app(PaymentService::class),
            app(AfipWsfeService::class)
        );

        $dto = new ProcessSaleDTO(
            items: [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 2000],
            ],
            payments: [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => 4000.0,
                    'surcharge_amount' => 0.0,
                    'total_amount' => 4000.0,
                ],
            ],
            total: 4000,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 4000,
            changeAmount: 0,
            status: 'completed',
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $saleService->executeSale($dto, $context);

        $this->assertNotNull($sale);

        // Verify SyncProductStockToPlatformsJob was pushed to queue ecommerce-sync
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($product) {
            return $job->productId === $product->id
                && $job->queue === 'ecommerce-sync';
        });
    }
}
