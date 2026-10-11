<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\Adapters\TiendaNubeAdapter;
use App\Services\Ecommerce\Adapters\WooCommerceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceAdapterInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function createIntegration(string $platform, array $attributes = []): EcommerceIntegration
    {
        return EcommerceIntegration::create(array_merge([
            'platform' => $platform,
            'name' => 'Canal Test '.$platform,
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

    // ─────────────────────────────────────────────────────────────────────────
    // MERCADO LIBRE: REFRESH TOKEN CONCURRENTE & REACTIVACIÓN A ACTIVE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mercadolibre_refreshes_token_proactively_when_expiring_soon(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'NEW_TOKEN_XYZ',
                'refresh_token' => 'NEW_REFRESH_XYZ',
                'expires_in' => 21600,
            ], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre', [
            'token_expires_at' => now()->addMinutes(5), // < 10 minutes
        ]);

        $adapter = new MercadoLibreAdapter($integration);
        $adapter->refreshTokenIfNeeded($integration);

        $fresh = $integration->fresh();
        $this->assertEquals('NEW_TOKEN_XYZ', $fresh->decrypted_access_token);
        $this->assertEquals('NEW_REFRESH_XYZ', $fresh->decrypted_refresh_token);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/oauth/token'
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'REFRESH_123';
        });
    }

    public function test_mercadolibre_skips_refresh_when_token_is_still_fresh(): void
    {
        Http::fake();

        $integration = $this->createIntegration('mercadolibre', [
            'token_expires_at' => now()->addHours(3), // > 10 min
        ]);

        $adapter = new MercadoLibreAdapter($integration);
        $adapter->refreshTokenIfNeeded($integration);

        Http::assertNothingSent();
    }

    public function test_mercadolibre_reactivates_listing_to_active_when_stock_is_positive(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA123456' => Http::response(['status' => 'active'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');
        $adapter = new MercadoLibreAdapter($integration);

        $result = $adapter->updateStock('MLA123456', 15.8); // float quantity floor test

        $this->assertTrue($result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA123456'
                && $request->method() === 'PUT'
                && $request['status'] === 'active'
                && $request['available_quantity'] === 15;
        });
    }

    public function test_mercadolibre_pauses_listing_when_stock_reaches_zero(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLA123456' => Http::response(['status' => 'paused'], 200),
        ]);

        $integration = $this->createIntegration('mercadolibre');
        $adapter = new MercadoLibreAdapter($integration);

        $result = $adapter->updateStock('MLA123456', 0.0);

        $this->assertTrue($result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.mercadolibre.com/items/MLA123456'
                && $request->method() === 'PUT'
                && $request['status'] === 'paused'
                && $request['available_quantity'] === 0;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WOOCOMMERCE: INVARIANTES ANTI-SOBREVENTA (MANAGE_STOCK, OUTOFSTOCK, BACKORDERS NO)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_woocommerce_forces_outofstock_and_backorders_no_when_stock_is_zero(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/55' => Http::response(['id' => 55], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $result = $adapter->updateStock('55', 0);

        $this->assertTrue($result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/55'
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no';
        });
    }

    public function test_woocommerce_sets_instock_when_stock_is_positive(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/55' => Http::response(['id' => 55], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $result = $adapter->updateStock('55', 8.9);

        $this->assertTrue($result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://mystore.test/wp-json/wc/v3/products/55'
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 8
                && $request['stock_status'] === 'instock'
                && $request['backorders'] === 'no';
        });
    }

    public function test_woocommerce_batch_update_enforces_anti_overselling_invariants(): void
    {
        Http::fake([
            'https://mystore.test/wp-json/wc/v3/products/batch' => Http::response([
                'update' => [['id' => 10], ['id' => 20]],
            ], 200),
        ]);

        $integration = $this->createIntegration('woocommerce');
        $adapter = new WooCommerceAdapter($integration);

        $items = [
            ['external_product_id' => '10', 'quantity' => 12.0],
            ['external_product_id' => '20', 'quantity' => 0.0],
        ];

        $response = $adapter->batchUpdateStock($items);

        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['updated_count']);

        Http::assertSent(function (Request $request) {
            $payload = $request['update'];

            return count($payload) === 2
                && $payload[0]['id'] === 10
                && $payload[0]['stock_quantity'] === 12
                && $payload[0]['stock_status'] === 'instock'
                && $payload[0]['backorders'] === 'no'
                && $payload[1]['id'] === 20
                && $payload[1]['stock_quantity'] === 0
                && $payload[1]['stock_status'] === 'outofstock'
                && $payload[1]['backorders'] === 'no';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIENDA NUBE: VARIANTES & LEAKY BUCKET RETRY-AFTER 429
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tiendanube_handles_variant_id_resolution_cleanly(): void
    {
        Http::fake([
            'https://api.tiendanube.com/v1/123456/products/99/variants/444' => Http::response(['id' => 444], 200),
        ]);

        $integration = $this->createIntegration('tiendanube');
        $adapter = new TiendaNubeAdapter($integration);

        $result = $adapter->updateStock('99', 7.5, '444');

        $this->assertTrue($result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.tiendanube.com/v1/123456/products/99/variants/444'
                && $request['stock'] === 7;
        });
    }

    public function test_tiendanube_respects_leaky_bucket_429_retry_after(): void
    {
        Http::fake([
            'https://api.tiendanube.com/v1/123456/products/99' => Http::sequence()
                ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '1'])
                ->push(['id' => 99, 'stock' => 5], 200),
        ]);

        $integration = $this->createIntegration('tiendanube');
        $adapter = new TiendaNubeAdapter($integration);

        $result = $adapter->updateStock('99', 5.0, '0');

        $this->assertTrue($result);
        Http::assertSentCount(2);
    }
}
