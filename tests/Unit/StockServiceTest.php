<?php

namespace Tests\Unit;

use App\DTOs\SaleContextDTO;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $stockService;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockService = app(StockService::class);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Test: calculateCostPrice devuelve el cost_price del producto simple.
     */
    public function test_calculate_cost_price_returns_product_cost_for_simple_product(): void
    {
        $product = Product::create([
            'name' => 'Taladro Percutor',
            'internal_code' => 'TAL01',
            'cost_price' => 85.50,
            'selling_price' => 150.00,
            'stock' => 10,
            'is_combo' => false,
            'active' => true,
        ]);

        $cost = $this->stockService->calculateCostPrice($product);

        $this->assertEquals(85.50, $cost);
    }

    /**
     * Test: calculateCostPrice calcula la sumatoria ponderada de los costos de los hijos de un combo.
     */
    public function test_calculate_cost_price_sums_children_weighted_costs_for_combo(): void
    {
        $childA = Product::create([
            'name' => 'Mecha 6mm',
            'internal_code' => 'MEC06',
            'cost_price' => 15.00,
            'selling_price' => 30.00,
            'stock' => 100,
            'is_combo' => false,
            'active' => true,
        ]);

        $childB = Product::create([
            'name' => 'Tarugo 6mm Pack x100',
            'internal_code' => 'TAR06',
            'cost_price' => 40.00,
            'selling_price' => 80.00,
            'stock' => 50,
            'is_combo' => false,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Kit Fijación (2 Mechas + 3 Tarugos)',
            'internal_code' => 'KIT-FIJ',
            'cost_price' => 0.00, // Debe calcularse dinámicamente
            'selling_price' => 300.00,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);

        // 2 Mechas ($15 * 2 = $30) + 3 Tarugos ($40 * 3 = $120) = $150.00 costo total
        $combo->children()->attach($childA->id, ['quantity' => 2]);
        $combo->children()->attach($childB->id, ['quantity' => 3]);

        $cost = $this->stockService->calculateCostPrice($combo->fresh(['children']));

        $this->assertEquals(150.00, $cost, 'El costo del combo debe sumar el costo ponderado de sus ingredientes.');
    }

    /**
     * Test: processCartStock descuenta stock de producto simple y genera movimiento en el kardex.
     */
    public function test_process_cart_stock_deducts_simple_product_stock_and_logs_movement(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $product = Product::create([
            'name' => 'Pintura Látex 20L',
            'internal_code' => 'PIN20',
            'cost_price' => 100.00,
            'selling_price' => 200.00,
            'stock' => 50.0,
            'sales_count' => 0,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 400.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        $products = new Collection([$product->id => $product]);
        $items = [['product_id' => $product->id, 'quantity' => 2.0]];

        $this->stockService->processCartStock($items, $products, $sale, $context, false, 'delivered');

        // Stock decrementado
        $this->assertEquals(48.0, (float) $product->fresh()->stock);
        $this->assertEquals(2, $product->fresh()->sales_count);

        // Movimiento registrado en kardex
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => -2.0,
            'sale_id' => $sale->id,
        ]);
    }

    /**
     * Test: processCartStock descuenta stock de los productos hijos del combo y deja intacto el stock del padre.
     */
    public function test_process_cart_stock_deducts_combo_children_and_leaves_parent_stock_unchanged(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $child1 = Product::create([
            'name' => 'Lija Fina',
            'internal_code' => 'LIJ01',
            'cost_price' => 5.00,
            'selling_price' => 10.00,
            'stock' => 100.0,
            'active' => true,
        ]);

        $child2 = Product::create([
            'name' => 'Pincel 1 pulgada',
            'internal_code' => 'PIN01',
            'cost_price' => 20.00,
            'selling_price' => 40.00,
            'stock' => 50.0,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Kit Lijado y Pintura',
            'internal_code' => 'KIT-LIJ',
            'cost_price' => 0.00,
            'selling_price' => 80.00,
            'stock' => 0.0,
            'is_combo' => true,
            'active' => true,
        ]);

        // Combo contiene 3 lijas y 1 pincel
        $combo->children()->attach($child1->id, ['quantity' => 3]);
        $combo->children()->attach($child2->id, ['quantity' => 1]);

        $sale = Sale::create([
            'total' => 160.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        // Pre-cargar productos con hijos para el servicio
        $comboFresh = $combo->fresh(['children']);
        $products = new Collection([
            $combo->id => $comboFresh,
            $child1->id => $child1,
            $child2->id => $child2,
        ]);

        // Vender 5 combos (debe descontar 5 * 3 = 15 lijas, 5 * 1 = 5 pinceles)
        $items = [['product_id' => $combo->id, 'quantity' => 5.0]];

        $this->stockService->processCartStock($items, $products, $sale, $context, false, 'delivered');

        // Stock del padre permanece en 0
        $this->assertEquals(0.0, (float) $combo->fresh()->stock);

        // Stock de los hijos descontado
        $this->assertEquals(85.0, (float) $child1->fresh()->stock, 'Lija: 100 - 15 = 85');
        $this->assertEquals(45.0, (float) $child2->fresh()->stock, 'Pincel: 50 - 5 = 45');

        // Kardex de los hijos
        $this->assertDatabaseHas('stock_movements', ['product_id' => $child1->id, 'quantity' => -15.0]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $child2->id, 'quantity' => -5.0]);
    }

    /**
     * Test: restoreStockForVoid devuelve el stock y descuenta sales_count.
     */
    public function test_restore_stock_for_void_restores_stock_and_adjusts_sales_count(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $product = Product::create([
            'name' => 'Amoladora Angular',
            'internal_code' => 'AMO01',
            'cost_price' => 200.00,
            'selling_price' => 400.00,
            'stock' => 15.0,
            'sales_count' => 10,
            'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 800.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2.0,
            'unit_price' => 400.00,
            'unit_cost_price' => 200.00,
            'subtotal' => 800.00,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        DB::transaction(function () use ($sale, $context) {
            $this->stockService->restoreStockForVoid($sale, $context, null);
        });

        // Stock restaurado: 15 + 2 = 17
        $this->assertEquals(17.0, (float) $product->fresh()->stock);
        // Popularidad revertida: 10 - 2 = 8
        $this->assertEquals(8, $product->fresh()->sales_count);

        // Movimiento de entrada registrado en kardex
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 2.0,
            'sale_id' => $sale->id,
        ]);
    }

    /**
     * Test: reconcileStockDiff ajusta el stock al modificar una venta en espera (Order Recall).
     */
    public function test_reconcile_stock_diff_adjusts_stock_during_order_recall(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $prodA = Product::create([
            'name' => 'Cable Unipolar 2.5mm',
            'internal_code' => 'CAB25',
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'stock' => 80.0,
            'sales_count' => 20,
            'active' => true,
        ]);

        $prodB = Product::create([
            'name' => 'Cinta Aisladora',
            'internal_code' => 'CIN01',
            'cost_price' => 10.00,
            'selling_price' => 25.00,
            'stock' => 40.0,
            'sales_count' => 10,
            'active' => true,
        ]);

        // Venta original con 10 cables y 2 cintas
        $sale = Sale::create([
            'total' => 1050.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $sale->items()->create([
            'product_id' => $prodA->id,
            'product_name' => $prodA->name,
            'quantity' => 10.0,
            'unit_price' => 100.00,
            'unit_cost_price' => 50.00,
            'subtotal' => 1000.00,
        ]);

        $sale->items()->create([
            'product_id' => $prodB->id,
            'product_name' => $prodB->name,
            'quantity' => 2.0,
            'unit_price' => 25.00,
            'unit_cost_price' => 10.00,
            'subtotal' => 50.00,
        ]);

        $context = new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shift->id,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );

        // Nuevo carrito: reduce cables a 6 (-4 unidades devueltas) y sube cintas a 5 (+3 unidades adicionales)
        $newItems = [
            ['product_id' => $prodA->id, 'quantity' => 6.0],
            ['product_id' => $prodB->id, 'quantity' => 5.0],
        ];

        DB::transaction(function () use ($newItems, $sale, $context) {
            $this->stockService->reconcileStockDiff($newItems, $sale, $context);
        });

        // Cable: stock sube en 4 (80 -> 84)
        $this->assertEquals(84.0, (float) $prodA->fresh()->stock);
        $this->assertEquals(16, $prodA->fresh()->sales_count); // 20 - 4 = 16

        // Cinta: stock baja en 3 (40 -> 37)
        $this->assertEquals(37.0, (float) $prodB->fresh()->stock);
        $this->assertEquals(13, $prodB->fresh()->sales_count); // 10 + 3 = 13
    }

    /**
     * Test: lockProducts arroja RuntimeException si se invoca fuera de una transacción de BD.
     */
    public function test_lock_products_throws_exception_outside_transaction(): void
    {
        // En RefreshDatabase el test corre dentro de una transacción activa (level 1).
        // Hacemos rollback temporal para simular la invocación sin transacción activa.
        DB::rollBack();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('lockProducts debe ser llamado dentro de una transacción activa');

            $this->stockService->lockProducts([1, 2, 3]);
        } finally {
            // Restaurar la transacción para que el tearDown de RefreshDatabase finalice limpiamente
            DB::beginTransaction();
        }
    }

    /**
     * Test: lockProducts bloquea y ordena la colección de forma ascendente por id dentro de una transacción.
     */
    public function test_lock_products_locks_and_orders_collection_inside_transaction(): void
    {
        $p1 = Product::create(['name' => 'P1', 'internal_code' => 'LK1', 'cost_price' => 1, 'selling_price' => 2]);
        $p2 = Product::create(['name' => 'P2', 'internal_code' => 'LK2', 'cost_price' => 1, 'selling_price' => 2]);
        $p3 = Product::create(['name' => 'P3', 'internal_code' => 'LK3', 'cost_price' => 1, 'selling_price' => 2]);

        $locked = DB::transaction(function () use ($p3, $p1, $p2) {
            // Pasar en desorden intencionalmente: [p3, p1, p2]
            return $this->stockService->lockProducts([$p3->id, $p1->id, $p2->id]);
        });

        $this->assertInstanceOf(Collection::class, $locked);
        $keys = $locked->keys()->toArray();

        // Las llaves deben estar ordenadas ascendentemente [p1, p2, p3] para prevenir deadlocks circulares
        $expectedOrder = [$p1->id, $p2->id, $p3->id];
        sort($expectedOrder);

        $this->assertEquals($expectedOrder, $keys);
    }
}
