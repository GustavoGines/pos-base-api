<?php

namespace Tests\Feature;

use App\DTOs\PaySaleDTO;
use App\DTOs\SaleContextDTO;
use App\Exports\MonthlyBalanceExport;
use App\Exports\ProfitByCategoryExport;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockController;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Repositories\SalesAnalyticsRepository;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AdversarialChallenger2Test
 *
 * Empirical verification and adversarial stress-testing for Phase P2:
 * 1. P2.2 - SaleService Pricing & Atomic Subtotal (extreme fractions, forged subtotals, zero price, negative clamp, price tiers, recall)
 * 2. P2.3 - StockController & AdjustStockRequest (valid types in/out/inc/dec, invalid types, qty=0 min_stock update, 500-char notes, dead code removal)
 * 3. P2.4 - Excel Exports & SalesAnalyticsRepository (SQLite execution, internal accounts exclusion, cash expense deduction, zero-revenue margin guard)
 * 4. P2.5 - Native Laravel Auth (auth()->user(), auth()->id(), request()->user(), missing token 401, expired token 401, token rotation)
 */
class AdversarialChallenger2Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected CashShift $shift;

    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->shift = $this->crearTurnoAbierto(user: $this->admin);
        $this->cashMethod = $this->crearMetodoEfectivo();
    }

    /*
    |--------------------------------------------------------------------------
    | AREA 1: P2.2 - SaleService Pricing & Atomic Subtotal
    |--------------------------------------------------------------------------
    */

    public function test_p2_2_extreme_fractional_quantities_and_atomic_subtotal_calculation(): void
    {
        $product = Product::create([
            'name' => 'Producto Fraccionable',
            'internal_code' => 'FRAC01',
            'selling_price' => 15.75,
            'cost_price' => 5.00,
            'stock' => 100.0,
            'active' => true,
        ]);

        // Extreme fractional quantity: 2.335 kg at $15.75/kg
        // Expected subtotal: round(15.75 * 2.335, 2) = round(36.77625, 2) = 36.78
        // Client attempts to send forged subtotal of $0.05
        $saleTotal = 36.78;
        $payload = [
            'total' => $saleTotal,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => $saleTotal,
                'surcharge_amount' => 0,
                'total_amount' => $saleTotal,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2.335,
                'unit_price' => 15.75,
                'subtotal' => 0.05, // Forged subtotal by malicious client
            ]],
        ];

        $response = $this->actingAsAdmin($this->admin)->postJson('/api/pos/sales', $payload);
        $response->assertStatus(201);

        $item = SaleItem::where('product_id', $product->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals(2.335, (float) $item->quantity);
        $this->assertEquals(15.75, (float) $item->unit_price);
        $this->assertEquals(36.78, (float) $item->subtotal, 'Client forged subtotal must be rejected and calculated atomically');
        $this->assertEquals(round($item->unit_price * $item->quantity, 2), (float) $item->subtotal);

        // Another extreme boundary: quantity = 0.001 at $1,000.00/unit -> subtotal = 1.00
        $expensive = Product::create([
            'name' => 'Azafran Gramos',
            'internal_code' => 'AZAF01',
            'selling_price' => 1000.00,
            'cost_price' => 400.00,
            'stock' => 10.0,
            'active' => true,
        ]);

        $payload2 = [
            'total' => 1.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 1.00,
                'surcharge_amount' => 0,
                'total_amount' => 1.00,
            ]],
            'items' => [[
                'product_id' => $expensive->id,
                'quantity' => 0.001,
                'unit_price' => 1000.00,
                'subtotal' => 999.99, // Forged
            ]],
        ];

        $response2 = $this->actingAsAdmin($this->admin)->postJson('/api/pos/sales', $payload2);
        $response2->assertStatus(201);

        $item2 = SaleItem::where('product_id', $expensive->id)->first();
        $this->assertEquals(0.001, (float) $item2->quantity);
        $this->assertEquals(1000.00, (float) $item2->unit_price);
        $this->assertEquals(1.00, (float) $item2->subtotal);
    }

    public function test_p2_2_negotiated_unit_price_prevails_and_supports_raw_price_keys(): void
    {
        $product = Product::create([
            'name' => 'Articulo Negociable',
            'internal_code' => 'NEG01',
            'selling_price' => 100.00, // Catálogo
            'cost_price' => 40.00,
            'stock' => 50.0,
            'active' => true,
        ]);

        // Client negotiates $62.50 per unit (catalog is $100.00).
        $qty = 3.0;
        $unitPrice = 62.50;
        $expectedSubtotal = 187.50;

        $payload = [
            'total' => $expectedSubtotal,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => $expectedSubtotal,
                'surcharge_amount' => 0,
                'total_amount' => $expectedSubtotal,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => 9999.00, // Forged subtotal
            ]],
        ];

        $response = $this->actingAsAdmin($this->admin)->postJson('/api/pos/sales', $payload);
        $response->assertStatus(201);

        $item = SaleItem::where('product_id', $product->id)->first();
        $this->assertEquals(62.50, (float) $item->unit_price, 'Negotiated unit_price must prevail over catalog price');
        $this->assertEquals(187.50, (float) $item->subtotal, 'Subtotal must equal unit_price * quantity');

        // Test with raw 'price' key directly in SaleService::processItems
        $saleService = app(SaleService::class);
        $sale = Sale::create([
            'total' => 90.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'user_id' => $this->admin->id,
        ]);

        $reflection = new \ReflectionClass(SaleService::class);
        $method = $reflection->getMethod('processItems');
        $method->setAccessible(true);

        $context = SaleContextDTO::fromArray([], $this->admin->id);
        $products = Product::where('id', $product->id)->get()->keyBy('id');

        // Pass 'price' key instead of 'unit_price'
        $method->invokeArgs($saleService, [
            $sale,
            [['product_id' => $product->id, 'quantity' => 2.0, 'price' => 45.00]],
            $products,
            $context,
        ]);

        $createdItem = $sale->items()->first();
        $this->assertEquals(45.00, (float) $createdItem->unit_price, "SaleService must recognize 'price' fallback key");
        $this->assertEquals(90.00, (float) $createdItem->subtotal);
    }

    public function test_p2_2_zero_price_100_percent_discount_promo(): void
    {
        $product = Product::create([
            'name' => 'Articulo Promocion Gratis',
            'internal_code' => 'FREE01',
            'selling_price' => 50.00,
            'cost_price' => 20.00,
            'stock' => 20.0,
            'active' => true,
        ]);

        $payload = [
            'total' => 0.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 0.00,
                'surcharge_amount' => 0,
                'total_amount' => 0.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 5.0,
                'unit_price' => 0.00, // 100% discount promo
                'subtotal' => 100.00, // Forged subtotal
            ]],
        ];

        $response = $this->actingAsAdmin($this->admin)->postJson('/api/pos/sales', $payload);
        $response->assertStatus(201);

        $item = SaleItem::where('product_id', $product->id)->first();
        $this->assertEquals(0.00, (float) $item->unit_price);
        $this->assertEquals(0.00, (float) $item->subtotal, 'Zero unit price must yield 0.00 subtotal');
    }

    public function test_p2_2_negative_unit_price_is_clamped_to_zero_by_sale_service(): void
    {
        $product = Product::create([
            'name' => 'Articulo Precio Negativo',
            'internal_code' => 'NEGPR01',
            'selling_price' => 30.00,
            'cost_price' => 10.00,
            'stock' => 10.0,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 0.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'user_id' => $this->admin->id,
        ]);

        $saleService = app(SaleService::class);
        $reflection = new \ReflectionClass(SaleService::class);
        $method = $reflection->getMethod('processItems');
        $method->setAccessible(true);

        $context = SaleContextDTO::fromArray([], $this->admin->id);
        $products = Product::where('id', $product->id)->get()->keyBy('id');

        // Pass negative unit price (-15.00)
        $method->invokeArgs($saleService, [
            $sale,
            [['product_id' => $product->id, 'quantity' => 2.0, 'unit_price' => -15.00]],
            $products,
            $context,
        ]);

        $item = $sale->items()->first();
        $this->assertEquals(0.00, (float) $item->unit_price, 'Negative unit_price must be clamped to 0.0');
        $this->assertEquals(0.00, (float) $item->subtotal);
    }

    public function test_p2_2_price_tiers_applied_when_unit_price_not_provided_and_overridden_when_negotiated(): void
    {
        $product = Product::create([
            'name' => 'Articulo Con Tramos',
            'internal_code' => 'TIER01',
            'selling_price' => 100.00,
            'cost_price' => 40.00,
            'stock' => 200.0,
            'active' => true,
        ]);

        $product->priceTiers()->createMany([
            ['min_quantity' => 10, 'unit_price' => 80.00],
            ['min_quantity' => 50, 'unit_price' => 60.00],
        ]);

        $saleService = app(SaleService::class);
        $reflection = new \ReflectionClass(SaleService::class);
        $method = $reflection->getMethod('processItems');
        $method->setAccessible(true);
        $context = SaleContextDTO::fromArray([], $this->admin->id);
        $products = Product::with('priceTiers')->where('id', $product->id)->get()->keyBy('id');

        // Case 1: No unit_price sent, qty = 15 -> hits tier 10 (80.00) -> subtotal = 1200.00
        $sale1 = Sale::create(['total' => 0, 'status' => 'pending', 'user_id' => $this->admin->id]);
        $method->invokeArgs($saleService, [
            $sale1,
            [['product_id' => $product->id, 'quantity' => 15.0]],
            $products,
            $context,
        ]);
        $item1 = $sale1->items()->first();
        $this->assertEquals(80.00, (float) $item1->unit_price, 'Should resolve tier price 80.00 for quantity 15');
        $this->assertEquals(1200.00, (float) $item1->subtotal);

        // Case 2: No unit_price sent, qty = 60 -> hits tier 50 (60.00) -> subtotal = 3600.00
        $sale2 = Sale::create(['total' => 0, 'status' => 'pending', 'user_id' => $this->admin->id]);
        $method->invokeArgs($saleService, [
            $sale2,
            [['product_id' => $product->id, 'quantity' => 60.0]],
            $products,
            $context,
        ]);
        $item2 = $sale2->items()->first();
        $this->assertEquals(60.00, (float) $item2->unit_price, 'Should resolve tier price 60.00 for quantity 60');
        $this->assertEquals(3600.00, (float) $item2->subtotal);

        // Case 3: Negotiated price explicitly sent (72.00) for qty 60 -> negotiated prevails over tier 60.00
        $sale3 = Sale::create(['total' => 0, 'status' => 'pending', 'user_id' => $this->admin->id]);
        $method->invokeArgs($saleService, [
            $sale3,
            [['product_id' => $product->id, 'quantity' => 60.0, 'unit_price' => 72.00]],
            $products,
            $context,
        ]);
        $item3 = $sale3->items()->first();
        $this->assertEquals(72.00, (float) $item3->unit_price, 'Explicit negotiated unit price must prevail over tier price');
        $this->assertEquals(4320.00, (float) $item3->subtotal);
    }

    public function test_p2_2_order_recall_recalculates_sale_total_atomically_from_items_subtotal(): void
    {
        $product1 = Product::create([
            'name' => 'Prod 1',
            'internal_code' => 'RCL01',
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock' => 20.0,
            'active' => true,
        ]);
        $product2 = Product::create([
            'name' => 'Prod 2',
            'internal_code' => 'RCL02',
            'selling_price' => 50.00,
            'cost_price' => 20.00,
            'stock' => 30.0,
            'active' => true,
        ]);

        // Create pending sale with Prod 1 (1 x $100)
        $sale = Sale::create([
            'total' => 100.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'cashier_id' => $this->admin->id,
            'user_id' => $this->admin->id,
            'cash_shift_id' => $this->shift->id,
        ]);
        $sale->items()->create([
            'product_id' => $product1->id,
            'product_name' => $product1->name,
            'quantity' => 1.0,
            'unit_price' => 100.00,
            'unit_cost_price' => 50.00,
            'subtotal' => 100.00,
        ]);

        // Order recall: modify items to Prod 1 (qty 2 @ $80 negotiated) + Prod 2 (qty 3 @ $50)
        // Subtotals: 2 * 80 = 160; 3 * 50 = 150. New total must be 310.00!
        $payDto = new PaySaleDTO(
            payments: [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 310.00,
                'surcharge_amount' => 0,
                'total_amount' => 310.00,
            ]],
            tenderedAmount: 310.00,
            changeAmount: 0.00,
            totalSurcharge: 0.00,
            shippingCost: 0.00,
            items: [
                ['product_id' => $product1->id, 'quantity' => 2.0, 'unit_price' => 80.00, 'subtotal' => 999.0],
                ['product_id' => $product2->id, 'quantity' => 3.0, 'unit_price' => 50.00, 'subtotal' => 1.0],
            ],
            checkDetails: null
        );

        $context = SaleContextDTO::fromArray(['cash_shift_id' => $this->shift->id], $this->admin->id);

        $saleService = app(SaleService::class);
        $updatedSale = $saleService->payPendingSale($sale, $payDto, $context);

        $this->assertEquals('completed', $updatedSale->status);
        $this->assertEquals(310.00, (float) $updatedSale->total, 'Sale total must be reconciled to sum of subtotals (160 + 150 = 310)');

        $items = $updatedSale->items()->get();
        $this->assertCount(2, $items);
        $item1 = $items->firstWhere('product_id', $product1->id);
        $item2 = $items->firstWhere('product_id', $product2->id);

        $this->assertEquals(80.00, (float) $item1->unit_price);
        $this->assertEquals(160.00, (float) $item1->subtotal);

        $this->assertEquals(50.00, (float) $item2->unit_price);
        $this->assertEquals(150.00, (float) $item2->subtotal);
    }

    /*
    |--------------------------------------------------------------------------
    | AREA 2: P2.3 - StockController & AdjustStockRequest
    |--------------------------------------------------------------------------
    */

    public function test_p2_3_valid_adjust_types_in_out_increment_decrement(): void
    {
        $product = Product::create([
            'name' => 'Prod Multi Type',
            'internal_code' => 'STKTYPE01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 50,
        ]);

        $this->actingAsAdmin($this->admin);

        // 1. type = 'in' (+10 -> 60)
        $resIn = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 10,
            'notes' => 'Ingreso mercaderia',
        ]);
        $resIn->assertStatus(200);
        $this->assertEquals(60, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
        ]);

        // 2. type = 'increment' (+5 -> 65)
        $resInc = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'increment',
            'quantity' => 5,
        ]);
        $resInc->assertStatus(200);
        $this->assertEquals(65, (float) $product->fresh()->stock);

        // 3. type = 'out' (-12 -> 53)
        $resOut = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'out',
            'quantity' => 12,
        ]);
        $resOut->assertStatus(200);
        $this->assertEquals(53, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 12,
        ]);

        // 4. type = 'decrement' (-3 -> 50)
        $resDec = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'decrement',
            'quantity' => 3,
        ]);
        $resDec->assertStatus(200);
        $this->assertEquals(50, (float) $product->fresh()->stock);
    }

    public function test_p2_3_invalid_adjust_types_are_rejected_with_422(): void
    {
        $product = Product::create([
            'name' => 'Prod Invalido',
            'internal_code' => 'STKINV01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 20,
        ]);

        $this->actingAsAdmin($this->admin);

        $invalidTypes = ['invalid', 'transfer', '', 'none', 'adjust'];

        foreach ($invalidTypes as $badType) {
            $response = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
                'type' => $badType,
                'quantity' => 5,
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['type']);
        }
    }

    public function test_p2_3_quantity_zero_with_min_stock_update_does_not_create_phantom_movement(): void
    {
        $product = Product::create([
            'name' => 'Prod Min Stock Update',
            'internal_code' => 'MINSTK01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 30,
            'min_stock' => 5,
        ]);

        $this->actingAsAdmin($this->admin);

        $initialMovements = StockMovement::where('product_id', $product->id)->count();

        // Send quantity = 0 with min_stock = 25
        $response = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 0,
            'min_stock' => 25,
            'notes' => 'Solo actualizando stock minimo',
        ]);

        $response->assertStatus(200);
        $freshProduct = $product->fresh();

        $this->assertEquals(30, (float) $freshProduct->stock, 'Physical stock must remain unchanged when quantity is 0');
        $this->assertEquals(25, (float) $freshProduct->min_stock, 'min_stock must be updated to 25');

        $finalMovements = StockMovement::where('product_id', $product->id)->count();
        $this->assertEquals($initialMovements, $finalMovements, 'Zero-quantity adjust must not create phantom StockMovement');
    }

    public function test_p2_3_notes_boundary_length_500_allowed_and_501_rejected(): void
    {
        $product = Product::create([
            'name' => 'Prod Notes Boundary',
            'internal_code' => 'NOTE01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 20,
        ]);

        $this->actingAsAdmin($this->admin);

        // Exactly 500 characters
        $notes500 = str_repeat('A', 500);
        $res500 = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 1,
            'notes' => $notes500,
        ]);
        $res500->assertStatus(200);

        // Exactly 501 characters -> must fail with 422
        $notes501 = str_repeat('B', 501);
        $res501 = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 1,
            'notes' => $notes501,
        ]);
        $res501->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_p2_3_out_of_stock_fails_gracefully(): void
    {
        $product = Product::create([
            'name' => 'Prod Limite Stock',
            'internal_code' => 'LIM01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 5,
        ]);

        $this->actingAsAdmin($this->admin);

        $response = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'out',
            'quantity' => 10, // Exceeds available stock (5)
        ]);

        $response->assertStatus(422);
        $this->assertEquals(5, (float) $product->fresh()->stock);
    }

    public function test_p2_3_dead_method_product_controller_adjust_stock_is_completely_removed(): void
    {
        $this->assertFalse(
            method_exists(ProductController::class, 'adjustStock'),
            'ProductController::adjustStock must be completely deleted.'
        );

        $route = Route::getRoutes()->getByName('products.adjust-stock');
        if (! $route) {
            // Check by URI
            $route = Route::getRoutes()->match(
                Request::create('/api/catalog/products/1/adjust-stock', 'POST')
            );
        }

        $this->assertNotNull($route);
        $this->assertStringContainsString(
            StockController::class,
            $route->getActionName(),
            'Route /api/catalog/products/{product}/adjust-stock must point to StockController'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AREA 3: P2.4 - Excel Exports & SalesAnalyticsRepository
    |--------------------------------------------------------------------------
    */

    public function test_p2_4_monthly_balance_export_and_repository_sqlite_driver(): void
    {
        $category = Category::create(['name' => 'Alimentos']);
        $product = Product::create([
            'name' => 'Galletitas',
            'internal_code' => 'GALL01',
            'category_id' => $category->id,
            'selling_price' => 100.00,
            'cost_price' => 40.00,
            'stock' => 50,
            'active' => true,
        ]);

        $currentMonth = now()->format('Y-m');

        $sale = Sale::create([
            'total' => 200.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2.0,
            'unit_price' => 100.00,
            'unit_cost_price' => 40.00,
            'subtotal' => 200.00,
        ]);

        $repo = app(SalesAnalyticsRepository::class);
        $data = $repo->getMonthlyBalance($currentMonth, $currentMonth);

        $this->assertArrayHasKey('months', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertNotEmpty($data['months']);

        $export = new MonthlyBalanceExport($currentMonth, $currentMonth, $repo);
        $collection = $export->collection();
        $this->assertNotEmpty($collection);

        $headings = $export->headings();
        $this->assertContains('Mes / Período', $headings);
        $this->assertContains('Facturación Total', $headings);
        $this->assertContains('Ganancia Neta', $headings);

        $mapped = $export->map($collection->first());
        $this->assertCount(6, $mapped);
        $this->assertEquals(200.00, (float) $mapped[2], 'Mapped total_revenue must be 200');
        $this->assertEquals(80.00, (float) $mapped[3], 'Mapped total_cost must be 80');
        $this->assertEquals(120.00, (float) $mapped[4], 'Mapped total_profit must be 120');
        $this->assertEquals(0.60, round((float) $mapped[5], 2), 'Mapped margin must be 0.60 (60%)');

        $formats = $export->columnFormats();
        $this->assertArrayHasKey('C', $formats);
        $this->assertArrayHasKey('F', $formats);
    }

    public function test_p2_4_monthly_balance_excludes_internal_customer_accounts(): void
    {
        $internalCustomer = Customer::create([
            'name' => 'Cuenta Interna Consumo Propio',
            'document_number' => '99990001',
            'is_internal_account' => true,
        ]);

        $normalCustomer = Customer::create([
            'name' => 'Cliente Normal Comprador',
            'document_number' => '20123456789',
            'is_internal_account' => false,
        ]);

        $product = Product::create([
            'name' => 'Gaseosa',
            'internal_code' => 'GAS01',
            'selling_price' => 500.00,
            'cost_price' => 200.00,
            'stock' => 100,
            'active' => true,
        ]);

        $currentMonth = now()->format('Y-m');

        // Internal account sale ($500)
        $saleInternal = Sale::create([
            'total' => 500.00,
            'customer_id' => $internalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        $saleInternal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1.0,
            'unit_price' => 500.00,
            'unit_cost_price' => 200.00,
            'subtotal' => 500.00,
        ]);

        // Regular customer sale ($200)
        $saleNormal = Sale::create([
            'total' => 200.00,
            'customer_id' => $normalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        $saleNormal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1.0,
            'unit_price' => 200.00,
            'unit_cost_price' => 80.00,
            'subtotal' => 200.00,
        ]);

        $repo = app(SalesAnalyticsRepository::class);
        $data = $repo->getMonthlyBalance($currentMonth, $currentMonth);

        $monthRecord = collect($data['months'])->firstWhere('period', $currentMonth);
        $this->assertNotNull($monthRecord);
        $this->assertEquals(200.00, (float) $monthRecord['total_revenue'], 'Internal account sale ($500) must be strictly excluded');
        $this->assertEquals(1, (int) $monthRecord['transactions'], 'Internal account transaction must be excluded from count');
    }

    public function test_p2_4_monthly_balance_deducts_active_cash_expenses_and_ignores_soft_deleted_and_incomes(): void
    {
        $product = Product::create([
            'name' => 'Herramienta',
            'internal_code' => 'HERR01',
            'selling_price' => 200.00,
            'cost_price' => 80.00,
            'stock' => 10,
            'active' => true,
        ]);

        $currentMonth = now()->format('Y-m');

        // Normal sale: Revenue = 200, Cost = 80, Gross Profit = 120
        $sale = Sale::create([
            'total' => 200.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1.0,
            'unit_price' => 200.00,
            'unit_cost_price' => 80.00,
            'subtotal' => 200.00,
        ]);

        // 1. Active Cash Expense ($30.00) -> MUST be deducted
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 30.00,
            'description' => 'Gasto de flete',
            'created_at' => now(),
        ]);

        // 2. Active Cash Income ($100.00) -> MUST NOT be deducted as expense
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'income',
            'amount' => 100.00,
            'description' => 'Ingreso extraordinario',
            'created_at' => now(),
        ]);

        // 3. Soft-deleted Cash Expense ($50.00) -> MUST NOT be deducted
        $deletedExpense = CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 50.00,
            'description' => 'Gasto anulado / revertido',
            'created_at' => now(),
        ]);
        $deletedExpense->delete(); // Soft delete

        $repo = app(SalesAnalyticsRepository::class);
        $data = $repo->getMonthlyBalance($currentMonth, $currentMonth);
        $monthRecord = collect($data['months'])->firstWhere('period', $currentMonth);

        $this->assertNotNull($monthRecord);
        // Gross Profit was 120. Net profit must be 120 - 30 (only active expense) = 90.00!
        $this->assertEquals(90.00, (float) $monthRecord['total_profit'], 'Only active cash expense ($30) must be deducted');
    }

    public function test_p2_4_profit_by_category_export_excludes_internal_accounts(): void
    {
        $cat = Category::create(['name' => 'Bebidas']);
        $product = Product::create([
            'name' => 'Cerveza Artesanal',
            'internal_code' => 'CERV01',
            'category_id' => $cat->id,
            'selling_price' => 300.00,
            'cost_price' => 100.00,
            'stock' => 50,
            'active' => true,
        ]);

        $internalCustomer = Customer::create([
            'name' => 'Consumo Empleados',
            'document_number' => '99990002',
            'is_internal_account' => true,
        ]);

        $normalCustomer = Customer::create([
            'name' => 'Cliente Bar',
            'document_number' => '20999888771',
            'is_internal_account' => false,
        ]);

        // Internal sale $600
        $saleInternal = Sale::create([
            'total' => 600.00,
            'customer_id' => $internalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        $saleInternal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2.0,
            'unit_price' => 300.00,
            'unit_cost_price' => 100.00,
            'subtotal' => 600.00,
        ]);

        // Normal sale $300
        $saleNormal = Sale::create([
            'total' => 300.00,
            'customer_id' => $normalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        $saleNormal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1.0,
            'unit_price' => 300.00,
            'unit_cost_price' => 100.00,
            'subtotal' => 300.00,
        ]);

        $startDate = now()->startOfMonth()->toDateString();
        $endDate = now()->endOfMonth()->toDateString();

        $export = new ProfitByCategoryExport($startDate, $endDate, 'category');
        $collection = $export->collection();

        $bebidasRow = $collection->firstWhere('category_name', 'Bebidas');
        $this->assertNotNull($bebidasRow);
        $this->assertEquals(300.00, (float) $bebidasRow['total_revenue'], 'Profit report must exclude internal account sales');
        $this->assertEquals(1, (int) $bebidasRow['items_sold']);

        $mapped = $export->map($bebidasRow);
        $this->assertEquals('Bebidas', $mapped[0]);
        $this->assertEquals(1, $mapped[1]);
        $this->assertEquals(300.00, $mapped[2]);
        $this->assertEquals(200.00, $mapped[3]); // Profit: 300 - 100 = 200
        $this->assertEquals(0.67, round($mapped[4], 2)); // Margin: 200/300 = 0.6667
    }

    public function test_p2_4_profit_by_category_handles_zero_revenue_without_division_by_zero(): void
    {
        $startDate = now()->startOfMonth()->toDateString();
        $endDate = now()->endOfMonth()->toDateString();

        $export = new ProfitByCategoryExport($startDate, $endDate, 'category');

        // Pass row with 0 revenue
        $zeroRow = [
            'category_name' => 'Vacio',
            'items_sold' => 0,
            'total_revenue' => 0.0,
            'total_profit' => 0.0,
            'revenue_with_cost' => 0.0,
        ];

        $mapped = $export->map($zeroRow);
        $this->assertEquals(0, $mapped[4], 'Margin with 0 revenue must be 0, not DivisionByZeroError');
    }

    /*
    |--------------------------------------------------------------------------
    | AREA 4: P2.5 - Native Laravel Auth & ValidateSessionToken
    |--------------------------------------------------------------------------
    */

    public function test_p2_5_authenticated_request_populates_auth_facade_and_request_user(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'session_token' => 'adversarial-token-xyz-12345',
        ]);

        $response = $this->withHeader('X-Session-Token', 'adversarial-token-xyz-12345')
            ->getJson('/api/sales');

        $response->assertStatus(200);

        // 1. Auth facade check
        $this->assertTrue(Auth::check(), 'Auth::check() must return true');
        $this->assertTrue(auth()->check(), 'auth()->check() must return true');

        // 2. Auth ID resolution
        $this->assertEquals($user->id, Auth::id(), 'Auth::id() must return authenticated user id');
        $this->assertEquals($user->id, auth()->id(), 'auth()->id() must return authenticated user id');

        // 3. Auth user object resolution
        $this->assertNotNull(Auth::user());
        $this->assertEquals($user->id, Auth::user()->id, 'Auth::user()->id must match');
        $this->assertEquals($user->id, auth()->user()->id, 'auth()->user()->id must match');

        // 4. Request user resolution
        $this->assertEquals($user->id, request()->user()?->id, '$request->user()->id must match');
    }

    public function test_p2_5_unauthenticated_request_missing_token_returns_401_session_missing(): void
    {
        // Protected route without X-Session-Token
        $response = $this->getJson('/api/sales');

        $response->assertStatus(401)
            ->assertJson([
                'error_code' => 'SESSION_MISSING',
            ]);
    }

    public function test_p2_5_invalid_token_returns_401_session_expired(): void
    {
        // Protected route with non-existent session token
        $response = $this->withHeader('X-Session-Token', 'completely-non-existent-token')
            ->getJson('/api/sales');

        $response->assertStatus(401)
            ->assertJson([
                'error_code' => 'SESSION_EXPIRED',
            ]);
    }

    public function test_p2_5_session_rotation_concurrent_device_login_invalidates_previous_token(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'session_token' => 'original-device-token',
        ]);

        // Request with original token succeeds
        $res1 = $this->withHeader('X-Session-Token', 'original-device-token')->getJson('/api/sales');
        $res1->assertStatus(200);

        // Another device logs in: token is updated in DB
        $user->update(['session_token' => 'new-device-token']);

        // Request with original token now fails with SESSION_EXPIRED
        $res2 = $this->withHeader('X-Session-Token', 'original-device-token')->getJson('/api/sales');
        $res2->assertStatus(401)
            ->assertJson([
                'error_code' => 'SESSION_EXPIRED',
            ]);

        // Request with new token succeeds
        $res3 = $this->withHeader('X-Session-Token', 'new-device-token')->getJson('/api/sales');
        $res3->assertStatus(200);
    }
}
