<?php

namespace Tests\Unit;

use App\DTOs\SaleContextDTO;
use App\Models\BusinessSetting;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
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

        // Stock decrementado y +1 punto de frecuencia por ticket (métrica 'tickets' por defecto)
        $this->assertEquals(48.0, (float) $product->fresh()->stock);
        $this->assertEquals(1, $product->fresh()->sales_count);

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
        // Popularidad revertida (1 ticket anulado = -1 punto): 10 - 1 = 9
        $this->assertEquals(9, $product->fresh()->sales_count);

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

        // Cable: stock sube en 4 (80 -> 84); sigue presente en el ticket -> popularidad se mantiene en 20
        $this->assertEquals(84.0, (float) $prodA->fresh()->stock);
        $this->assertEquals(20, $prodA->fresh()->sales_count);

        // Cinta: stock baja en 3 (40 -> 37); sigue presente en el ticket -> popularidad se mantiene en 10
        $this->assertEquals(37.0, (float) $prodB->fresh()->stock);
        $this->assertEquals(10, $prodB->fresh()->sales_count);
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

    // ── Popularidad (sales_count) ────────────────────────────────────────

    private function contextoMostrador(int $shiftId): SaleContextDTO
    {
        return new SaleContextDTO(
            userId: $this->user->id,
            cashShiftId: $shiftId,
            customerId: null,
            quoteId: null,
            priceList: null,
            deliveryAddress: null,
            isInternalAccount: false
        );
    }

    private function crearPesable(int $salesCount = 0): Product
    {
        return Product::create([
            'name' => 'Queso Cremoso',
            'internal_code' => 'QSO01',
            'cost_price' => 500.00,
            'selling_price' => 1000.00,
            'stock' => 20.0,
            'sales_count' => $salesCount,
            'is_sold_by_weight' => true,
            'active' => true,
        ]);
    }

    /**
     * Una venta fraccionaria por peso (0.250 kg) suma 1 punto y su anulación lo revierte exactamente.
     */
    public function test_popularidad_venta_fraccionaria_suma_un_punto_y_anulacion_lo_revierte(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = $this->crearPesable();
        $context = $this->contextoMostrador($shift->id);

        $sale = Sale::create([
            'total' => 250.00, 'status' => 'completed', 'payment_status' => 'paid',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 0.25,
            'unit_price' => 1000.00, 'unit_cost_price' => 500.00, 'subtotal' => 250.00,
        ]);

        $this->stockService->processCartStock(
            [['product_id' => $product->id, 'quantity' => 0.25]],
            new Collection([$product->id => $product]), $sale, $context, false, 'delivered'
        );
        $this->assertEquals(1, $product->fresh()->sales_count, '0.250 kg debe sumar 1 punto, no truncarse a 0');

        DB::transaction(fn () => $this->stockService->restoreStockForVoid($sale->fresh('items'), $context, null));
        $this->assertEquals(0, $product->fresh()->sales_count, 'La anulación debe revertir exactamente lo sumado');
    }

    /**
     * Order Recall: cambiar 0.300 kg → 0.500 kg no debe inflar la popularidad (sigue siendo 1 línea = 1 punto).
     */
    public function test_popularidad_recall_pesable_no_infla_contador(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        // Al guardar la orden en espera con 0.300 kg ya se sumó 1 punto.
        $product = $this->crearPesable(salesCount: 1);
        $context = $this->contextoMostrador($shift->id);

        $sale = Sale::create([
            'total' => 300.00, 'status' => 'pending', 'payment_status' => 'pending',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 0.3,
            'unit_price' => 1000.00, 'unit_cost_price' => 500.00, 'subtotal' => 300.00,
        ]);

        DB::transaction(fn () => $this->stockService->reconcileStockDiff(
            [['product_id' => $product->id, 'quantity' => 0.5]], $sale->fresh('items'), $context
        ));

        $this->assertEquals(1, $product->fresh()->sales_count, '0.3 kg → 0.5 kg sigue siendo 1 punto');
        $this->assertEquals(19.8, (float) $product->fresh()->stock, 'El stock sí debe ajustar la diferencia (0.2 kg)');
    }

    /**
     * Order Recall: quitar por completo una línea pesable (0.3 kg → 0) resta su punto y
     * volver a agregarla (0 → 0.4 kg) lo suma de nuevo: el contador queda coherente con la orden final.
     */
    public function test_popularidad_recall_quitar_y_reagregar_pesable_es_simetrico(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $product = $this->crearPesable(salesCount: 3);
        $context = $this->contextoMostrador($shift->id);

        $sale = Sale::create([
            'total' => 300.00, 'status' => 'pending', 'payment_status' => 'pending',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 0.3,
            'unit_price' => 1000.00, 'unit_cost_price' => 500.00, 'subtotal' => 300.00,
        ]);

        // Se quita la línea: 0.3 kg → 0 (ítem inexistente en el nuevo carrito).
        $otro = Product::create([
            'name' => 'Otro', 'internal_code' => 'OTR01', 'cost_price' => 1, 'selling_price' => 2,
            'stock' => 10, 'active' => true,
        ]);
        DB::transaction(fn () => $this->stockService->reconcileStockDiff(
            [['product_id' => $otro->id, 'quantity' => 1.0]], $sale->fresh('items'), $context
        ));
        $this->assertEquals(2, $product->fresh()->sales_count, 'Quitar la línea resta su punto (3 → 2)');
        $this->assertEquals(20.3, (float) $product->fresh()->stock, 'El stock vuelve (0.3 kg)');
    }

    /**
     * Anular una venta con remito diferido (stock nunca descontado en mostrador) no debe restar popularidad
     * que nunca se sumó.
     */
    public function test_popularidad_anular_remito_diferido_no_resta_puntos_no_sumados(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);
        $context = $this->contextoMostrador($shift->id);

        $product = Product::create([
            'name' => 'Bolsa Cemento 50kg', 'internal_code' => 'CEM50', 'cost_price' => 100.00,
            'selling_price' => 200.00, 'stock' => 30.0, 'sales_count' => 5, 'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 800.00, 'status' => 'completed', 'payment_status' => 'paid',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 4.0,
            'unit_price' => 200.00, 'unit_cost_price' => 100.00, 'subtotal' => 800.00,
        ]);

        // Remito pendiente: processCartStock no descontó stock ni sumó popularidad.
        $deliveryNote = DeliveryNote::create([
            'sale_id' => $sale->id, 'status' => 'pending', 'notes' => 'Envío diferido',
        ]);
        DeliveryNoteItem::create([
            'delivery_note_id' => $deliveryNote->id, 'product_id' => $product->id,
            'quantity_purchased' => 4, 'quantity_delivered' => 0,
        ]);

        DB::transaction(fn () => $this->stockService->restoreStockForVoid(
            $sale->fresh('items'), $context, $deliveryNote->fresh('items')
        ));

        $this->assertEquals(5, $product->fresh()->sales_count, 'No se debe restar popularidad que nunca se sumó');
        $this->assertEquals(30.0, (float) $product->fresh()->stock);
    }

    /**
     * Cuando pos_popularity_metric = 'units', suma la cantidad redondeada hacia arriba (ceil).
     */
    public function test_popularidad_modo_units_suma_unidades_redondeadas(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'pos_popularity_metric'],
            ['value' => 'units']
        );

        $shift = $this->crearTurnoAbierto(user: $this->user);
        $context = $this->contextoMostrador($shift->id);

        $product = Product::create([
            'name' => 'Alfajor Triple', 'internal_code' => 'ALF01', 'cost_price' => 50.00,
            'selling_price' => 100.00, 'stock' => 100.0, 'sales_count' => 0, 'active' => true,
        ]);

        $sale = Sale::create([
            'total' => 600.00, 'status' => 'completed', 'payment_status' => 'paid',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 6.0,
            'unit_price' => 100.00, 'unit_cost_price' => 50.00, 'subtotal' => 600.00,
        ]);

        $this->stockService->processCartStock(
            [['product_id' => $product->id, 'quantity' => 6.0]],
            new Collection([$product->id => $product]), $sale, $context, false, 'delivered'
        );

        $this->assertEquals(6, $product->fresh()->sales_count);
    }

    /**
     * recalculatePopularityCounts recalcula sobre la ventana móvil configurada e ignora ventas viejas o previas a un reset.
     */
    public function test_recalculate_popularity_counts_aplica_ventana_30_dias_y_reset(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->user);

        $prodReciente = Product::create([
            'name' => 'Coca Cola 2.25L', 'internal_code' => 'COC225', 'cost_price' => 1000,
            'selling_price' => 2000, 'stock' => 50, 'sales_count' => 999, 'active' => true,
        ]);

        $prodViejo = Product::create([
            'name' => 'Sidra Navideña', 'internal_code' => 'SID01', 'cost_price' => 800,
            'selling_price' => 1500, 'stock' => 20, 'sales_count' => 500, 'active' => true,
        ]);

        // Venta reciente (hace 5 días) con 10 unidades de Coca Cola en 1 ticket
        $saleReciente = Sale::create([
            'total' => 20000, 'status' => 'completed', 'payment_status' => 'paid',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        DB::table('sales')->where('id', $saleReciente->id)->update(['created_at' => now()->subDays(5)]);
        $saleReciente->items()->create([
            'product_id' => $prodReciente->id, 'product_name' => $prodReciente->name, 'quantity' => 10.0,
            'unit_price' => 2000, 'unit_cost_price' => 1000, 'subtotal' => 20000,
        ]);

        // Venta vieja (hace 45 días) de Sidra
        $saleVieja = Sale::create([
            'total' => 1500, 'status' => 'completed', 'payment_status' => 'paid',
            'cash_shift_id' => $shift->id, 'user_id' => $this->user->id,
        ]);
        DB::table('sales')->where('id', $saleVieja->id)->update(['created_at' => now()->subDays(45)]);
        $saleVieja->items()->create([
            'product_id' => $prodViejo->id, 'product_name' => $prodViejo->name, 'quantity' => 1.0,
            'unit_price' => 1500, 'unit_cost_price' => 800, 'subtotal' => 1500,
        ]);

        $updated = $this->stockService->recalculatePopularityCounts(30, 'tickets');

        $this->assertEquals(1, $updated);
        $this->assertEquals(1, $prodReciente->fresh()->sales_count, '1 ticket en los últimos 30 días = 1 punto');
        $this->assertEquals(0, $prodViejo->fresh()->sales_count, 'Venta de hace 45 días queda fuera de la ventana de 30 días');

        // Ahora probamos poner en cero (reset de temporada)
        $this->stockService->resetPopularityCounts();
        $this->assertEquals(0, $prodReciente->fresh()->sales_count);

        // Si se vuelve a ejecutar recalculatePopularityCounts tras el reset, la venta de hace 5 días no revive
        $this->stockService->recalculatePopularityCounts(30, 'tickets');
        $this->assertEquals(0, $prodReciente->fresh()->sales_count);
    }
}
