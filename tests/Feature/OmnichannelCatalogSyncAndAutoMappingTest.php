<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceWebhook;
use App\Models\Product;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmnichannelCatalogSyncAndAutoMappingTest extends TestCase
{
    use RefreshDatabase;

    protected EcommerceIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['ecommerce_sync' => true])]
        );

        $this->integration = EcommerceIntegration::create([
            'platform' => EcommerceIntegration::PLATFORM_INTERNAL,
            'name' => 'Plataforma Web Propia Test',
            'base_url' => 'http://test-web.localhost',
            'store_id' => 'g3',
            'access_token' => 'token_secret_123',
            'webhook_secret' => 'webhook_sec_456',
            'is_active' => true,
            'sync_stock_enabled' => true,
        ]);
    }

    public function test_inbound_webhook_auto_maps_by_sku_and_deducts_stock(): void
    {
        // 1. Crear producto local con SKU (internal_code)
        $product = Product::create([
            'name' => 'Altavoz Bluetooth Pro',
            'internal_code' => 'SKU-SPEAKER-99',
            'barcode' => '7791234567890',
            'cost_price' => 10000,
            'selling_price' => 20000,
            'stock' => 15.0,
            'active' => true,
        ]);

        // 2. Webhook entrante de compra con item sin mapeo previo
        $webhook = EcommerceWebhook::create([
            'integration_id' => $this->integration->id,
            'platform' => EcommerceIntegration::PLATFORM_INTERNAL,
            'external_event_id' => 'EVT-TEST-1001',
            'event_type' => 'order.created',
            'status' => 'received',
            'payload' => [
                'event_type' => 'order.created',
                'id' => 'ORD-TEST-1001',
                'order_id' => 'ORD-TEST-1001',
                'items' => [
                    [
                        'external_product_id' => '9999', // ID remoto no coincide con local
                        'sku' => 'SKU-SPEAKER-99',
                        'name' => 'Altavoz Bluetooth Pro',
                        'quantity' => 2.0,
                    ],
                ],
            ],
        ]);

        // 3. Ejecutar Job
        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        // 4. Aserciones
        $this->assertDatabaseHas('ecommerce_product_mappings', [
            'integration_id' => $this->integration->id,
            'product_id' => $product->id,
            'external_product_id' => '9999',
            'external_sku' => 'SKU-SPEAKER-99',
            'sync_stock' => true,
        ]);

        $this->assertEquals(13.0, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'sale_ecommerce',
            'quantity' => 2.0,
        ]);

        $this->assertEquals('processed', $webhook->fresh()->status);
    }

    public function test_inbound_webhook_auto_maps_by_exact_name_when_sku_is_absent(): void
    {
        $product = Product::create([
            'name' => 'Teclado Mecanico RGB',
            'internal_code' => 'LOCAL-KB-01',
            'cost_price' => 8000,
            'selling_price' => 16000,
            'stock' => 10.0,
            'active' => true,
        ]);

        $webhook = EcommerceWebhook::create([
            'integration_id' => $this->integration->id,
            'platform' => EcommerceIntegration::PLATFORM_INTERNAL,
            'external_event_id' => 'EVT-TEST-1002',
            'event_type' => 'order.created',
            'status' => 'received',
            'payload' => [
                'order_id' => 'ORD-TEST-1002',
                'items' => [
                    [
                        'external_product_id' => 'WEB-888',
                        'sku' => null, // Sin SKU
                        'name' => 'Teclado Mecanico RGB', // Coincidencia por nombre exacto
                        'quantity' => 1.0,
                    ],
                ],
            ],
        ]);

        $job = new ProcessInboundEcommerceWebhookJob($webhook->id);
        $job->handle(app(EcommerceManager::class), app(StockService::class));

        $this->assertDatabaseHas('ecommerce_product_mappings', [
            'integration_id' => $this->integration->id,
            'product_id' => $product->id,
            'external_product_id' => 'WEB-888',
        ]);

        $this->assertEquals(9.0, (float) $product->fresh()->stock);
    }

    public function test_import_catalog_from_remote_creates_products_and_mappings(): void
    {
        Http::fake([
            'http://test-web.localhost/api/v1/catalog/export' => Http::response([
                'success' => true,
                'total' => 2,
                'products' => [
                    [
                        'id' => 101,
                        'sku' => 'PROD-101',
                        'name' => 'Mouse Gaming Optico',
                        'price' => 25000.0,
                        'cost_price' => 15000.0,
                        'stock' => 20.0,
                        'barcode' => '7799988776655',
                        'category' => 'Periféricos',
                    ],
                    [
                        'id' => 102,
                        'sku' => 'PROD-102',
                        'name' => 'Pad Mouse XXL',
                        'price' => 12000.0,
                        'cost_price' => 6000.0,
                        'stock' => 50.0,
                        'barcode' => '7799988776656',
                        'category' => 'Accesorios',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
            ->postJson("/api/ecommerce/integrations/{$this->integration->id}/import-catalog");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total_remote' => 2,
            'imported' => 2,
            'mapped' => 2,
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Mouse Gaming Optico',
            'internal_code' => 'PROD-101',
            'selling_price' => 25000.0,
        ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Periféricos',
        ]);

        $this->assertDatabaseHas('ecommerce_product_mappings', [
            'integration_id' => $this->integration->id,
            'external_product_id' => '101',
            'external_sku' => 'PROD-101',
        ]);
    }

    public function test_import_catalog_updates_existing_product_name_prices_and_stock(): void
    {
        $existing = Product::create([
            'name' => 'Nombre Viejo Del Producto',
            'internal_code' => 'SKU-UPDATE-1',
            'cost_price' => 5000,
            'selling_price' => 10000,
            'stock' => 2.0,
            'active' => true,
        ]);

        Http::fake([
            'http://test-web.localhost/api/v1/catalog/export' => Http::response([
                'success' => true,
                'total' => 1,
                'products' => [
                    [
                        'id' => 999,
                        'sku' => 'SKU-UPDATE-1',
                        'name' => 'Nombre Nuevo Actualizado',
                        'price' => 18000.0,
                        'cost_price' => 9000.0,
                        'stock' => 99.0,
                        'category' => 'Hardware',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
            ->postJson("/api/ecommerce/integrations/{$this->integration->id}/import-catalog");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'updated' => 1,
        ]);

        $fresh = $existing->fresh();
        $this->assertEquals('Nombre Nuevo Actualizado', $fresh->name);
        $this->assertEquals(18000.0, (float) $fresh->selling_price);
        $this->assertEquals(9000.0, (float) $fresh->cost_price);
        $this->assertEquals(99.0, (float) $fresh->stock);
    }

    public function test_export_catalog_to_remote_seeds_store(): void
    {
        $category = Category::create(['name' => 'Audio']);
        $product = Product::create([
            'name' => 'Auriculares Inalambricos BT',
            'internal_code' => 'AUDIO-BT-01',
            'selling_price' => 45000.0,
            'cost_price' => 28000.0,
            'stock' => 8.0,
            'active' => true,
            'category_id' => $category->id,
        ]);

        Http::fake([
            'http://test-web.localhost/api/v1/catalog/bulk-sync' => Http::response([
                'success' => true,
                'message' => 'Catálogo sembrado exitosamente en la tienda web',
                'synced_count' => 1,
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
            ->postJson("/api/ecommerce/integrations/{$this->integration->id}/export-catalog");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'exported_count' => 1,
        ]);

        $this->assertDatabaseHas('ecommerce_product_mappings', [
            'integration_id' => $this->integration->id,
            'product_id' => $product->id,
            'external_sku' => 'AUDIO-BT-01',
        ]);
    }
}
