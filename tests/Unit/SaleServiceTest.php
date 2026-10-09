<?php

namespace Tests\Unit;

use App\DTOs\PaySaleDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Events\SaleCompleted;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Sale;
use App\Models\User;
use App\Services\Afip\AfipWsfeService;
use App\Services\PaymentService;
use App\Services\ReportCacheService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SaleService $saleService;

    protected StockService $stockService;

    protected PaymentService $paymentService;

    protected User $user;

    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockService = app(StockService::class);
        $this->paymentService = app(PaymentService::class);
        $this->saleService = new SaleService($this->stockService, $this->paymentService, app(AfipWsfeService::class));

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->cashMethod = $this->crearMetodoEfectivo();
    }

    /**
     * Test: executeSale calcula totales, recargos, costos de envío y crea ítems con subtotales exactos.
     */
    public function test_execute_sale_calculates_totals_and_creates_items(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $prodA = Product::create([
            'name' => 'Producto A',
            'internal_code' => 'PA01',
            'cost_price' => 30.00,
            'selling_price' => 60.00,
            'stock' => 50,
            'active' => true,
        ]);

        $prodB = Product::create([
            'name' => 'Producto B',
            'internal_code' => 'PB01',
            'cost_price' => 20.00,
            'selling_price' => 40.00,
            'stock' => 50,
            'active' => true,
        ]);

        // 2 de A ($120) + 1 de B ($40) = $160 subtotal + $10 recargo + $25 envío = $195
        $dto = new ProcessSaleDTO(
            total: 160.00,
            totalSurcharge: 10.00,
            shippingCost: 25.00,
            tenderedAmount: 200.00,
            changeAmount: 5.00,
            status: 'completed',
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 160.00,
                'surcharge_amount' => 35.00, // 10 recargo + 25 envio
                'total_amount' => 195.00,
            ]],
            items: [
                ['product_id' => $prodA->id, 'quantity' => 2, 'unit_price' => 60.00],
                ['product_id' => $prodB->id, 'quantity' => 1, 'unit_price' => 40.00],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: 'Av. Siempreviva 742',
            isInternalAccount: false
        );

        $sale = $this->saleService->executeSale($dto, $context);

        $this->assertInstanceOf(Sale::class, $sale);
        $this->assertEquals(160.00, (float) $sale->total);
        $this->assertEquals(10.00, (float) $sale->total_surcharge);
        $this->assertEquals(25.00, (float) $sale->shipping_cost);
        $this->assertEquals(0.00, (float) $sale->amount_due);
        $this->assertEquals('paid', $sale->payment_status);
        $this->assertEquals('completed', $sale->status);

        // Verificar ítems de la venta
        $this->assertCount(2, $sale->items);
        $itemA = $sale->items->firstWhere('product_id', $prodA->id);
        $this->assertEquals(2.0, (float) $itemA->quantity);
        $this->assertEquals(60.00, (float) $itemA->unit_price);
        $this->assertEquals(30.00, (float) $itemA->unit_cost_price);
        $this->assertEquals(120.00, (float) $itemA->subtotal);

        $itemB = $sale->items->firstWhere('product_id', $prodB->id);
        $this->assertEquals(1.0, (float) $itemB->quantity);
        $this->assertEquals(40.00, (float) $itemB->unit_price);
        $this->assertEquals(20.00, (float) $itemB->unit_cost_price);
        $this->assertEquals(40.00, (float) $itemB->subtotal);
    }

    /**
     * Test: executeSale aplica precios por volumen automáticamente cuando no se pasa unit_price en el ítem.
     */
    public function test_execute_sale_applies_tiered_pricing_when_unit_price_omitted(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $product = Product::create([
            'name' => 'Tornillos Por Mayor',
            'internal_code' => 'TOR-MY',
            'cost_price' => 5.00,
            'selling_price' => 10.00,
            'stock' => 200,
            'active' => true,
        ]);

        // Tramo mayorista: a partir de 50 unidades, el precio unitario baja a $7.00
        ProductPriceTier::create([
            'product_id' => $product->id,
            'min_quantity' => 50,
            'unit_price' => 7.00,
        ]);

        // Ítem sin 'unit_price': el servicio debe consultar getPriceForQuantity(50) -> $7.00
        $dto = new ProcessSaleDTO(
            total: 350.00, // 50 * 7 = 350
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 350.00,
            changeAmount: 0,
            status: 'completed',
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 350.00,
                'surcharge_amount' => 0,
                'total_amount' => 350.00,
            ]],
            items: [
                ['product_id' => $product->id, 'quantity' => 50], // unit_price omitido
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $this->saleService->executeSale($dto, $context);

        $item = $sale->items->first();
        $this->assertEquals(7.00, (float) $item->unit_price, 'Debe aplicar el precio del tramo por volumen.');
        $this->assertEquals(350.00, (float) $item->subtotal);
    }

    /**
     * Test: executeSale dispara el evento SaleCompleted.
     */
    public function test_execute_sale_dispatches_sale_completed_event(): void
    {
        Event::fake([SaleCompleted::class]);

        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = Product::create([
            'name' => 'Producto Evento',
            'internal_code' => 'EV01',
            'cost_price' => 10.00,
            'selling_price' => 20.00,
            'stock' => 10,
            'active' => true,
        ]);

        $dto = new ProcessSaleDTO(
            total: 20.00,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 20.00,
            changeAmount: 0,
            status: 'completed',
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 20.00,
                'surcharge_amount' => 0,
                'total_amount' => 20.00,
            ]],
            items: [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20.00],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $this->saleService->executeSale($dto, $context);

        Event::assertDispatched(SaleCompleted::class, function ($event) use ($sale) {
            return $event->sale->id === $sale->id;
        });
    }

    /**
     * Test: executeSale invalida el caché de reportes.
     */
    public function test_execute_sale_flushes_report_cache(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = Product::create([
            'name' => 'Producto Cache Flush',
            'internal_code' => 'CF01',
            'cost_price' => 10.00,
            'selling_price' => 20.00,
            'stock' => 10,
            'active' => true,
        ]);

        $initialVersion = ReportCacheService::getVersion();

        $dto = new ProcessSaleDTO(
            total: 20.00,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 20.00,
            changeAmount: 0,
            status: 'completed',
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 20.00,
                'surcharge_amount' => 0,
                'total_amount' => 20.00,
            ]],
            items: [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20.00],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $this->saleService->executeSale($dto, $context);

        $this->assertGreaterThan($initialVersion, ReportCacheService::getVersion());
    }

    /**
     * Test: payPendingSale completa la venta, actualiza estado a pagado e invalida caché.
     */
    public function test_pay_pending_sale_completes_sale_and_flushes_cache(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = Product::create([
            'name' => 'Producto Pendiente',
            'internal_code' => 'PEND01',
            'cost_price' => 20.00,
            'selling_price' => 50.00,
            'stock' => 10,
            'active' => true,
        ]);

        // 1. Crear venta en estado pendiente
        $dtoPending = new ProcessSaleDTO(
            total: 50.00,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 0,
            changeAmount: 0,
            status: 'pending',
            payments: [],
            items: [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50.00],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $this->saleService->executeSale($dtoPending, $context);
        $this->assertEquals('pending', $sale->status);

        $vBeforePay = ReportCacheService::getVersion();

        // 2. Pagar la venta pendiente
        $payDto = new PaySaleDTO(
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 50.00,
                'surcharge_amount' => 0,
                'total_amount' => 50.00,
            ]],
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 50.00,
            changeAmount: 0,
            items: [],
            checkDetails: null
        );

        $paidSale = $this->saleService->payPendingSale($sale, $payDto, $context);

        $this->assertEquals('completed', $paidSale->status);
        $this->assertEquals('paid', $paidSale->payment_status);
        $this->assertEquals(0, (float) $paidSale->amount_due);
        $this->assertGreaterThan($vBeforePay, ReportCacheService::getVersion());
    }

    /**
     * Test: payPendingSale arroja InvalidArgumentException si la venta no está en estado pendiente.
     */
    public function test_pay_pending_sale_throws_when_not_pending(): void
    {
        $sale = Sale::create([
            'total' => 100.00,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $payDto = new PaySaleDTO(
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 100.00,
                'surcharge_amount' => 0,
                'total_amount' => 100.00,
            ]],
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 100.00,
            changeAmount: 0,
            items: [],
            checkDetails: null
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: 1,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Esta venta ya no está en estado pendiente.');

        $this->saleService->payPendingSale($sale, $payDto, $context);
    }

    /**
     * Test: voidSale restituye stock, cambia estado a voided e invalida caché.
     */
    public function test_void_sale_restores_stock_and_flushes_cache(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = Product::create([
            'name' => 'Producto Void Test',
            'internal_code' => 'VOID01',
            'cost_price' => 40.00,
            'selling_price' => 80.00,
            'stock' => 20,
            'active' => true,
        ]);

        $dto = new ProcessSaleDTO(
            total: 160.00,
            totalSurcharge: 0,
            shippingCost: 0,
            tenderedAmount: 160.00,
            changeAmount: 0,
            status: 'completed',
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 160.00,
                'surcharge_amount' => 0,
                'total_amount' => 160.00,
            ]],
            items: [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 80.00],
            ],
            checkDetails: null,
            requiresDispatch: false,
            fulfillmentStatus: 'delivered'
        );

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $sale = $this->saleService->executeSale($dto, $context);
        $this->assertEquals(18, (float) $product->fresh()->stock);

        $vBeforeVoid = ReportCacheService::getVersion();

        $voidedSale = $this->saleService->voidSale($sale, $context);

        $this->assertEquals('voided', $voidedSale->status);
        $this->assertEquals(20, (float) $product->fresh()->stock, 'El stock debe volver al nivel previo.');
        $this->assertGreaterThan($vBeforeVoid, ReportCacheService::getVersion());
    }

    /**
     * Test: voidSale arroja InvalidArgumentException si la venta ya está anulada.
     */
    public function test_void_sale_throws_when_already_voided(): void
    {
        $sale = Sale::create([
            'total' => 50.00,
            'status' => 'voided',
            'payment_status' => 'paid',
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: 1,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Esta venta ya está anulada.');

        $this->saleService->voidSale($sale, $context);
    }
}
