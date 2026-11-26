<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\BusinessSetting;
use App\Models\EcommerceIntegration;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Afip\AfipWsfeService;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Services\StockService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutPreviewOptimizationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_afip_wsfe_reuses_same_guzzle_client_with_keep_alive_and_single_settings_query(): void
    {
        $cuit = '30712345678';
        BusinessSetting::create(['key' => 'afip_enabled', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_cuit', 'value' => $cuit]);
        BusinessSetting::create(['key' => 'afip_pto_vta', 'value' => '1']);
        BusinessSetting::create(['key' => 'afip_environment', 'value' => 'testing']);
        BusinessSetting::create(['key' => 'tax_condition', 'value' => BusinessSetting::ISSUER_TAX_RESPONSABLE_INSCRIPTO]);
        BusinessSetting::create(['key' => 'afip_cf_max_limit', 'value' => '340000.00']);
        BusinessSetting::create(['key' => 'is_iibb_perception_agent', 'value' => '0']);
        BusinessSetting::create(['key' => 'default_iibb_perception_rate', 'value' => '0']);

        Cache::put("afip_ta_{$cuit}_wsfe_testing", [
            'token' => 'test-token',
            'sign' => 'test-sign',
            'expiration_time' => now()->addHours(8)->toIso8601String(),
        ], 3600);

        $product = Product::create([
            'name' => 'Taladro Industrial',
            'internal_code' => 'TL001',
            'cost_price' => 5000,
            'selling_price' => 12100,
            'iva_rate' => 21,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = Sale::create([
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'total' => 12100,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 5000,
            'unit_price' => 12100,
            'subtotal' => 12100,
            'iva_rate' => 21,
            'net_amount' => 10000,
            'iva_amount' => 2100,
        ]);

        $sale->load('items.product', 'customer');

        $history = [];
        $mockHandler = new MockHandler([
            new Psr7Response(200, [], '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompUltimoAutorizadoResult><CbteNro>99</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>'),
            new Psr7Response(200, [], '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECAESolicitarResult><FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Resultado>A</Resultado><CAE>74185296301234</CAE><CAEFchVto>20261231</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse></soap:Body></soap:Envelope>'),
        ]);

        $stack = HandlerStack::create($mockHandler);
        $stack->push(Middleware::history($history));
        $sharedClient = new Client(['handler' => $stack]);

        $wsfeService = new AfipWsfeService(null, $sharedClient);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $wsfeService->authorizeInvoice($sale, [
            'voucher_type' => 6,
            'doc_type' => 99,
            'doc_number' => '0',
            'receiver_name' => 'Consumidor Final',
            'receiver_tax_condition' => 'consumidor_final',
        ]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('authorized', $result['status']);
        $this->assertSame(100, $result['voucher_number']);
        $this->assertSame('74185296301234', $result['cae']);
        $this->assertCount(2, $history, 'FECompUltimoAutorizado + FECAESolicitar deben viajar por el mismo cliente Guzzle.');
        $this->assertStringContainsString('FECompUltimoAutorizado', $history[0]['request']->getHeaderLine('SOAPAction'));
        $this->assertStringContainsString('FECAESolicitar', $history[1]['request']->getHeaderLine('SOAPAction'));
        $this->assertSame('keep-alive', strtolower($history[0]['request']->getHeaderLine('Connection')));
        $this->assertSame('keep-alive', strtolower($history[1]['request']->getHeaderLine('Connection')));

        $businessSettingsQueries = array_filter(
            $queries,
            static fn (array $q): bool => str_contains(strtolower((string) ($q['query'] ?? '')), 'business_settings')
        );
        $this->assertCount(1, $businessSettingsQueries, 'Todas las configuraciones fiscales deben resolverse en 1 sola lectura.');
    }

    public function test_ecommerce_stock_sync_skips_when_inactive_and_invalidates_cache_on_changes(): void
    {
        Queue::fake([SyncProductStockToPlatformsJob::class]);
        SyncProductStockToPlatformsJob::clearActiveIntegrationsCache();

        $product = Product::create([
            'name' => 'Amoladora Angular',
            'internal_code' => 'AMO01',
            'cost_price' => 1000,
            'selling_price' => 2500,
            'stock' => 20,
            'active' => true,
        ]);

        // Sin integraciones activas: cachea false y NO encola Jobs ni toca tabla jobs
        SyncProductStockToPlatformsJob::dispatchForProducts([$product->id]);
        Queue::assertNothingPushed();
        $this->assertTrue(Cache::has(SyncProductStockToPlatformsJob::ACTIVE_INTEGRATIONS_CACHE_KEY));

        // Al crear una integración activa, el hook del modelo invalida automáticamente la caché
        $integration = EcommerceIntegration::create([
            'platform' => 'tiendanube',
            'name' => 'Tienda Activa',
            'is_active' => true,
            'sync_stock_enabled' => true,
        ]);
        $this->assertFalse(Cache::has(SyncProductStockToPlatformsJob::ACTIVE_INTEGRATIONS_CACHE_KEY));

        SyncProductStockToPlatformsJob::dispatchForProducts([$product->id]);
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, 1);

        // Toggle via endpoint también invalida caché
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['ecommerce_sync' => true])]
        );
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);
        $this->postJson("/api/ecommerce/integrations/{$integration->id}/toggle-active")->assertStatus(200);
        $this->assertFalse(Cache::has(SyncProductStockToPlatformsJob::ACTIVE_INTEGRATIONS_CACHE_KEY));

        Queue::fake([SyncProductStockToPlatformsJob::class]);
        SyncProductStockToPlatformsJob::dispatchForProducts([$product->id]);
        Queue::assertNothingPushed();
    }

    public function test_reverb_offline_trips_circuit_breaker_and_never_blocks_subsequent_sales(): void
    {
        Cache::forget(SaleService::REVERB_CIRCUIT_BREAKER_KEY);
        SyncProductStockToPlatformsJob::clearActiveIntegrationsCache();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 59999,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
            'broadcasting.connections.reverb.client_options.timeout' => 0.5,
            'broadcasting.connections.reverb.client_options.connect_timeout' => 0.25,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashMethod = $this->crearMetodoEfectivo();
        $product = Product::create([
            'name' => 'Pintura Látex 4L',
            'internal_code' => 'PIN4L',
            'cost_price' => 3000,
            'selling_price' => 5000,
            'stock' => 20,
            'active' => true,
        ]);

        $saleService = new SaleService(
            app(StockService::class),
            app(PaymentService::class),
            app(AfipWsfeService::class)
        );

        $makeDto = fn (float $qty): ProcessSaleDTO => new ProcessSaleDTO(
            total: 5000 * $qty,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 5000 * $qty,
            changeAmount: 0,
            status: 'completed',
            payments: [[
                'payment_method_id' => $cashMethod->id,
                'base_amount' => 5000 * $qty,
                'surcharge_amount' => 0,
                'total_amount' => 5000 * $qty,
            ]],
            items: [
                ['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 5000],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $admin->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        // Nota: RefreshDatabase envuelve cada test en una transacción externa; forzamos commit limpio de los callbacks pendientes
        // invocando broadcast directamente para probar el circuit-breaker y el timeout corto sin esperar teardown.
        $sale1 = $saleService->executeSale($makeDto(1), $context);
        $this->assertNotNull($sale1->id);

        // Disparar explícitamente el broadcast fuera de transacción como ocurre en producción tras DB::afterCommit
        $refMethod = new \ReflectionMethod($saleService, 'broadcastSaleCompletedSafely');
        $refMethod->setAccessible(true);
        $refMethod->invoke($saleService, $sale1);

        $this->assertTrue(
            Cache::has(SaleService::REVERB_CIRCUIT_BREAKER_KEY),
            'Cuando Reverb no responde, debe abrirse el circuit-breaker de 60s.'
        );

        // Segunda invocación con circuit-breaker abierto debe retornar inmediatamente (< 100ms) sin intentar abrir socket
        $start = microtime(true);
        $refMethod->invoke($saleService, $sale1);
        $elapsedMs = (microtime(true) - $start) * 1000.0;
        $this->assertLessThan(100.0, $elapsedMs);
    }
}
