<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceSyncLog;
use App\Models\EcommerceWebhook;
use App\Models\Product;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\Adapters\WooCommerceAdapter;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceAntiOversellingChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected function createIntegration(string $platform, array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => $platform,
            'name' => 'Canal Challenger '.$platform,
            'client_id' => 'CLIENT_TEST_ID',
            'client_secret' => 'SECRET_TEST_SECRET',
            'access_token' => 'ACCESS_TEST_TOKEN',
            'refresh_token' => 'REFRESH_TEST_TOKEN',
            'token_expires_at' => now()->addHours(6),
            'base_url' => 'https://mystore.test',
            'store_id' => '987654',
            'webhook_secret' => 'WH_SEC_CHALLENGER',
            'is_active' => true,
            'sync_stock_enabled' => true,
            'auto_pause_on_zero_stock' => true,
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE 1: WOOCOMMERCE ZERO-STOCK PAYLOAD
    // Invariant: stock = 0 forces 'stock_status' => 'outofstock' AND 'backorders' => 'no'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_challenge_1_woocommerce_single_update_zero_stock_forces_outofstock_and_no_backorders(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/101' => Http::response(['id' => 101], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $result = $adapter->updateStock('101', 0.0);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/101'
                && $request->method() === 'PUT'
                && isset($data['manage_stock']) && $data['manage_stock'] === true
                && isset($data['stock_quantity']) && $data['stock_quantity'] === 0
                && isset($data['stock_status']) && $data['stock_status'] === 'outofstock'
                && isset($data['backorders']) && $data['backorders'] === 'no';
        });
    }

    public function test_challenge_1_woocommerce_fractional_sub_one_stock_floors_to_zero_and_forces_outofstock(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/102' => Http::response(['id' => 102], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        // Floor of 0.8 is 0 -> MUST be outofstock
        $result = $adapter->updateStock('102', 0.8);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/102'
                && $data['stock_quantity'] === 0
                && $data['stock_status'] === 'outofstock'
                && $data['backorders'] === 'no';
        });
    }

    public function test_challenge_1_woocommerce_batch_update_zero_stock_forces_outofstock_and_no_backorders(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/batch' => Http::response([
                'update' => [['id' => 201], ['id' => 202]],
            ], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $items = [
            ['external_product_id' => '201', 'quantity' => 0.0],
            ['external_product_id' => '202', 'quantity' => 12.0],
        ];

        $response = $adapter->batchUpdateStock($items);

        $this->assertTrue($response['success']);

        Http::assertSent(function (Request $request) {
            $update = $request['update'];

            return count($update) === 2
                && $update[0]['id'] === 201
                && $update[0]['stock_quantity'] === 0
                && $update[0]['stock_status'] === 'outofstock'
                && $update[0]['backorders'] === 'no'
                && $update[1]['id'] === 202
                && $update[1]['stock_quantity'] === 12
                && $update[1]['stock_status'] === 'instock'
                && $update[1]['backorders'] === 'no';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE 2: MERCADO LIBRE LISTING REACTIVATION & PAUSE
    // Invariant: stock > 0 sets 'status' => 'active', stock = 0 sets 'status' => 'paused'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_challenge_2_mercadolibre_restocking_sets_status_active(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA777' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');
        $adapter = new MercadoLibreAdapter($integration);

        $result = $adapter->updateStock('MLA777', 25.0);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.mercadolibre.com/items/MLA777'
                && $request->method() === 'PUT'
                && isset($data['status']) && $data['status'] === 'active'
                && isset($data['available_quantity']) && $data['available_quantity'] === 25;
        });
    }

    public function test_challenge_2_mercadolibre_zero_stock_sets_status_paused(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA777' => Http::response(['status' => 'paused'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');
        $adapter = new MercadoLibreAdapter($integration);

        $result = $adapter->updateStock('MLA777', 0.0);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.mercadolibre.com/items/MLA777'
                && $request->method() === 'PUT'
                && isset($data['status']) && $data['status'] === 'paused'
                && isset($data['available_quantity']) && $data['available_quantity'] === 0;
        });
    }

    public function test_challenge_2_mercadolibre_variation_restock_and_pause(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA888/variations/VAR1' => Http::response(['id' => 'VAR1'], 200),
            'https://api.mercadolibre.com/items/MLA888' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');
        $adapter = new MercadoLibreAdapter($integration);

        // Case A: Restocking variation
        $resultRestock = $adapter->updateStock('MLA888', 8.0, 'VAR1');
        $this->assertTrue($resultRestock);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA888'
                && $request['status'] === 'active';
        });

        // Case B: Zero stock on variation
        Http::fake([
            'https://api.mercadolibre.com/items/MLA888/variations/VAR1' => Http::response(['id' => 'VAR1'], 200),
            'https://api.mercadolibre.com/items/MLA888' => Http::response(['status' => 'paused'], 200),
        ]);

        $resultPause = $adapter->updateStock('MLA888', 0.0, 'VAR1');
        $this->assertTrue($resultPause);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA888'
                && $request['status'] === 'paused'
                && $request['available_quantity'] === 0;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE 3: COMBO PRODUCT STOCK MATH
    // Invariant: virtual stock reflects floor(child_stock / child_qty) of limiting child
    // ─────────────────────────────────────────────────────────────────────────

    public function test_challenge_3_combo_stock_math_identifies_limiting_child(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA_COMBO_1' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');

        // Child A: 100 stock / 1 required = 100 combos
        $childA = Product::create([
            'name' => 'Caja de Herramientas',
            'internal_code' => 'CH-01',
            'cost_price' => 2000,
            'selling_price' => 4000,
            'stock' => 100,
            'active' => true,
        ]);

        // Child B: 10 stock / 3 required = floor(10/3) = 3 combos (LIMITING CHILD)
        $childB = Product::create([
            'name' => 'Juego de Llaves',
            'internal_code' => 'JL-01',
            'cost_price' => 500,
            'selling_price' => 1000,
            'stock' => 10,
            'active' => true,
        ]);

        // Child C: 50 stock / 5 required = floor(50/5) = 10 combos
        $childC = Product::create([
            'name' => 'Tornillos x10',
            'internal_code' => 'TX-01',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 50,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Kit Taller Completo',
            'internal_code' => 'KIT-01',
            'cost_price' => 3000,
            'selling_price' => 6000,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);

        $combo->children()->attach($childA->id, ['quantity' => 1]);
        $combo->children()->attach($childB->id, ['quantity' => 3]);
        $combo->children()->attach($childC->id, ['quantity' => 5]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $combo->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_COMBO_1',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 0.00,
            'allocated_stock_percentage' => 100.00,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($combo->id);
        $job->handle(app(EcommerceManager::class));

        // Must receive available_quantity = 3 (limiting child B)
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA_COMBO_1'
                && $request['available_quantity'] === 3
                && $request['status'] === 'active';
        });
    }

    public function test_challenge_3_combo_with_one_exhausted_child_sends_zero_and_pauses(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/WC_COMBO_0' => Http::response(['id' => 1], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');

        $childAvailable = Product::create([
            'name' => 'Batería 18V',
            'internal_code' => 'BAT-01',
            'cost_price' => 3000,
            'selling_price' => 6000,
            'stock' => 50,
            'active' => true,
        ]);

        // Child with 0 stock
        $childExhausted = Product::create([
            'name' => 'Cargador Rápido',
            'internal_code' => 'CR-01',
            'cost_price' => 2000,
            'selling_price' => 4000,
            'stock' => 0,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Kit Taladro Inalámbrico',
            'internal_code' => 'KTI-01',
            'cost_price' => 5000,
            'selling_price' => 10000,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);

        $combo->children()->attach($childAvailable->id, ['quantity' => 1]);
        $combo->children()->attach($childExhausted->id, ['quantity' => 1]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $combo->id,
            'platform' => 'woocommerce',
            'external_product_id' => 'WC_COMBO_0',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 0.00,
            'allocated_stock_percentage' => 100.00,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($combo->id);
        $job->handle(app(EcommerceManager::class));

        // Combo must send 0 to WooCommerce: outofstock and no backorders
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/WC_COMBO_0'
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE 4: SAFETY STOCK THRESHOLD & ALLOCATED PERCENTAGE FORMULA
    // Formula: max(0, floor(($stock - threshold) * percentage / 100))
    // ─────────────────────────────────────────────────────────────────────────

    public function test_challenge_4_formula_under_various_boundary_conditions(): void
    {
        $integration = $this->createIntegration('mercadolibre');

        $testScenarios = [
            // [stock, threshold, percentage, expectedTarget]
            'exact threshold' => [10.0, 10.0, 100.0, 0],
            'below threshold' => [8.0, 10.0, 100.0, 0],
            'well below threshold' => [2.0, 50.0, 100.0, 0],
            'fractional division' => [15.0, 2.0, 33.0, 4], // floor((15-2)*0.33) = floor(4.29) = 4
            'quarter allocation' => [11.0, 0.0, 25.0, 2], // floor(11*0.25) = floor(2.75) = 2
            'zero percentage' => [100.0, 0.0, 0.0, 0],
            'full stock with margin' => [50.0, 10.0, 50.0, 20], // floor(40*0.5) = 20
            'high volume large stock' => [10000.0, 100.0, 40.0, 3960], // floor(9900*0.4) = 3960
        ];

        $idx = 0;
        foreach ($testScenarios as $label => [$stock, $threshold, $percentage, $expectedTarget]) {
            $idx++;
            $extId = 'MLA_TEST_MATH_'.$idx;
            Http::fake([
                "https://api.mercadolibre.com/items/{$extId}" => Http::response(['status' => 'ok'], 200),
            ]);

            $product = Product::create([
                'name' => "Prod {$label}",
                'internal_code' => 'P-'.uniqid(),
                'cost_price' => 100,
                'selling_price' => 200,
                'stock' => $stock,
                'active' => true,
            ]);

            $mapping = EcommerceProductMapping::create([
                'integration_id' => $integration->id,
                'product_id' => $product->id,
                'platform' => 'mercadolibre',
                'external_product_id' => $extId,
                'external_variant_id' => '0',
                'sync_stock' => true,
                'safety_stock_threshold' => $threshold,
                'allocated_stock_percentage' => $percentage,
                'is_paused' => false,
            ]);

            $job = new SyncProductStockToPlatformsJob($product->id, (float) $product->stock);
            $job->handle(app(EcommerceManager::class));

            Http::assertSent(function (Request $request) use ($extId, $expectedTarget) {
                return $request->url() === "https://api.mercadolibre.com/items/{$extId}"
                    && $request['available_quantity'] === $expectedTarget;
            });
        }
    }

    public function test_challenge_4_formula_under_negative_stock_forces_zero(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA_NEG' => Http::response(['status' => 'paused'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');

        $product = Product::create([
            'name' => 'Prod Negativo',
            'internal_code' => 'NEG-01',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => -5,
            'active' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_NEG',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 0.0,
            'allocated_stock_percentage' => 100.0,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($product->id, -5.0);
        $job->handle(app(EcommerceManager::class));

        Http::assertSent(function (Request $request) {
            return $request['available_quantity'] === 0
                && $request['status'] === 'paused';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE 5: NEGATIVE STOCK HANDLING & CROSS-PLATFORM PROPAGATION
    // Invariant: Inbound overselling drops stock negative, flags 'stock_conflict_review',
    // and sends 0 to all remaining platforms.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_challenge_5_negative_stock_handling_and_zero_out_other_platforms(): void
    {
        // Setup two platforms: Platform A (Mercado Libre - origin) and Platform B (WooCommerce - remote)
        $meliIntegration = $this->createIntegration('mercadolibre', ['name' => 'Mercado Libre Canal']);
        $wcIntegration = $this->createIntegration('woocommerce', ['name' => 'WooCommerce Canal']);

        // Product with local stock = 0 (completely exhausted)
        $product = Product::create([
            'name' => 'Disco de Corte Diamantado',
            'internal_code' => 'DISC-01',
            'cost_price' => 1500,
            'selling_price' => 3000,
            'stock' => 0,
            'active' => true,
        ]);

        // Mappings for both platforms
        $meliMapping = EcommerceProductMapping::create([
            'integration_id' => $meliIntegration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_DISCO',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        $wcMapping = EcommerceProductMapping::create([
            'integration_id' => $wcIntegration->id,
            'product_id' => $product->id,
            'platform' => 'woocommerce',
            'external_product_id' => 'WC_DISCO',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Inbound purchase arrives from Mercado Libre for 1 unit while stock is 0
        $webhook = EcommerceWebhook::create([
            'integration_id' => $meliIntegration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_OVERSELL_CHALLENGE',
            'payload' => [
                'id' => 'ORDER_MELI_OVERSELL_888',
                'items' => [
                    ['external_product_id' => 'MLA_DISCO', 'quantity' => 1],
                ],
            ],
            'status' => 'received',
        ]);

        // Process inbound webhook job
        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // Assertion 1: Local stock dropped negative (0 - 1 = -1)
        $product->refresh();
        $this->assertEquals('-1.000', $product->stock);

        // Assertion 2: Sync log recorded with status 'stock_conflict_review'
        $syncLog = EcommerceSyncLog::where('integration_id', $meliIntegration->id)
            ->where('external_order_id', 'ORDER_MELI_OVERSELL_888')
            ->first();

        $this->assertNotNull($syncLog);
        $this->assertEquals('order_deduct', $syncLog->action);
        $this->assertEquals('stock_conflict_review', $syncLog->status);
        $this->assertStringContainsString('overselling', (string) $syncLog->error_message);

        // Assertion 3: Run the cross-platform outbound job for the remaining platform (WooCommerce)
        // With product stock = -1.0 and excludedIntegrationId = meliIntegration->id
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/WC_DISCO' => Http::response(['id' => 10], 200),
        ]);

        $outboundJob = new SyncProductStockToPlatformsJob(
            productId: $product->id,
            newStock: (float) $product->stock,
            excludedIntegrationId: $meliIntegration->id
        );
        $outboundJob->handle(app(EcommerceManager::class));

        // Remote platform WooCommerce MUST receive 0 stock, outofstock, and backorders no
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/WC_DISCO'
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no';
        });

        // Mercado Libre (origin platform) MUST NOT have received a request from outbound job
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'mercadolibre.com');
        });
    }

    public function test_challenge_1_woocommerce_variation_zero_stock_forces_outofstock(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/500/variations/901' => Http::response(['id' => 901], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $result = $adapter->updateStock('500', 0.0, '901');

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/500/variations/901'
                && $data['stock_quantity'] === 0
                && $data['stock_status'] === 'outofstock'
                && $data['backorders'] === 'no';
        });
    }

    public function test_challenge_3_combo_with_negative_child_stock_evaluates_to_zero(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/WC_COMBO_NEG' => Http::response(['id' => 1], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');

        $childNegative = Product::create([
            'name' => 'Broca 8mm',
            'internal_code' => 'BRC-08',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => -3, // Negative stock on component child!
            'active' => true,
        ]);

        $childPositive = Product::create([
            'name' => 'Broca 10mm',
            'internal_code' => 'BRC-10',
            'cost_price' => 70,
            'selling_price' => 140,
            'stock' => 10,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Juego Brocas',
            'internal_code' => 'JB-01',
            'cost_price' => 120,
            'selling_price' => 240,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);

        $combo->children()->attach($childNegative->id, ['quantity' => 1]);
        $combo->children()->attach($childPositive->id, ['quantity' => 1]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $combo->id,
            'platform' => 'woocommerce',
            'external_product_id' => 'WC_COMBO_NEG',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'safety_stock_threshold' => 0.00,
            'allocated_stock_percentage' => 100.00,
            'is_paused' => false,
        ]);

        $job = new SyncProductStockToPlatformsJob($combo->id);
        $job->handle(app(EcommerceManager::class));

        // When limiting child has negative stock, max(0, -3) forces virtual stock to 0
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/WC_COMBO_NEG'
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no';
        });
    }

    public function test_challenge_5_three_way_cross_platform_fan_out_after_oversell(): void
    {
        // 3 integrations: MELI (origin), WooCommerce (remote 1), TiendaNube (remote 2)
        $meliIntegration = $this->createIntegration('mercadolibre', ['name' => 'Canal MELI Origin']);
        $wcIntegration = $this->createIntegration('woocommerce', ['name' => 'Canal WC Remote']);
        $tnIntegration = $this->createIntegration('tiendanube', ['name' => 'Canal TN Remote', 'store_id' => '654321']);

        $product = Product::create([
            'name' => 'Amoladora Angular 115mm',
            'internal_code' => 'AMO-115',
            'cost_price' => 5000,
            'selling_price' => 10000,
            'stock' => 1, // 1 unit left
            'active' => true,
        ]);

        // Mappings
        EcommerceProductMapping::create([
            'integration_id' => $meliIntegration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_AMO_115',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $wcIntegration->id,
            'product_id' => $product->id,
            'platform' => 'woocommerce',
            'external_product_id' => 'WC_AMO_115',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $tnIntegration->id,
            'product_id' => $product->id,
            'platform' => 'tiendanube',
            'external_product_id' => 'TN_AMO_115',
            'external_variant_id' => '0',
            'sync_stock' => true,
        ]);

        // Inbound purchase arrives on MELI for 3 units (overselling by 2 units)
        $webhook = EcommerceWebhook::create([
            'integration_id' => $meliIntegration->id,
            'platform' => 'mercadolibre',
            'event_type' => 'orders_v2',
            'external_event_id' => 'EVT_OVERSELL_3WAY',
            'payload' => [
                'id' => 'ORDER_MELI_3WAY_999',
                'items' => [
                    ['external_product_id' => 'MLA_AMO_115', 'quantity' => 3],
                ],
            ],
            'status' => 'received',
        ]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // 1. Stock dropped negative: 1 - 3 = -2
        $product->refresh();
        $this->assertEquals('-2.000', $product->stock);

        // 2. Audit log flags stock_conflict_review
        $this->assertDatabaseHas('ecommerce_sync_logs', [
            'integration_id' => $meliIntegration->id,
            'external_order_id' => 'ORDER_MELI_3WAY_999',
            'action' => 'order_deduct',
            'status' => 'stock_conflict_review',
        ]);

        // 3. Outbound sync propagation targeting remaining platforms
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/WC_AMO_115' => Http::response(['id' => 1], 200),
            'https://api.tiendanube.com/v1/654321/products/TN_AMO_115' => Http::response(['id' => 1], 200),
        ]);

        $outboundJob = new SyncProductStockToPlatformsJob(
            productId: $product->id,
            newStock: (float) $product->stock,
            excludedIntegrationId: $meliIntegration->id
        );
        $outboundJob->handle(app(EcommerceManager::class));

        // WooCommerce remote receives 0 stock outofstock
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/WC_AMO_115'
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no';
        });

        // TiendaNube remote receives 0 stock
        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $stock = $data['variants'][0]['stock'] ?? $data['stock'] ?? null;

            return $request->url() === 'https://api.tiendanube.com/v1/654321/products/TN_AMO_115'
                && $stock === 0;
        });

        // Mercado Libre (origin) was excluded
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'mercadolibre.com');
        });
    }
}
