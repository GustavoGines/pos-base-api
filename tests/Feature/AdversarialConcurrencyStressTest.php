<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\DeliveryNote;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdversarialConcurrencyStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected CashShift $shift;

    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        $register = CashRegister::create(['name' => 'Caja Stress']);
        $this->shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'type' => 'cash',
            'active' => true,
        ]);

        // Enable quotes feature gate
        BusinessSetting::create([
            'key' => 'license_features_dict',
            'value' => json_encode(['quotes' => true]),
        ]);
    }

    // =========================================================================
    // SECTION 1: P2.6 Quote Sequence Number & Concurrency Edge Cases
    // =========================================================================

    public function test_p2_6_quote_number_cold_start_empty_table(): void
    {
        $this->assertEquals(0, Quote::count());
        $number = Quote::nextQuoteNumber();
        $this->assertEquals('PRES-0001', $number, 'Cold start on empty table must produce PRES-0001');
    }

    public function test_p2_6_quote_number_sequential_progression(): void
    {
        Quote::create([
            'quote_number' => 'PRES-0001',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('PRES-0002', Quote::nextQuoteNumber());

        Quote::create([
            'quote_number' => 'PRES-0002',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('PRES-0003', Quote::nextQuoteNumber());
    }

    public function test_p2_6_quote_number_boundary_and_overflow_9999_to_10000(): void
    {
        Quote::create([
            'quote_number' => 'PRES-9999',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('PRES-10000', Quote::nextQuoteNumber(), 'Overflow from 9999 must cleanly expand to 10000 without truncation');
    }

    public function test_p2_6_quote_number_large_overflow_99999_to_100000(): void
    {
        Quote::create([
            'quote_number' => 'PRES-99999',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('PRES-100000', Quote::nextQuoteNumber());
    }

    public function test_p2_6_quote_number_custom_prefix_and_fallback(): void
    {
        Quote::create([
            'quote_number' => 'COT-0042',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('COT-0043', Quote::nextQuoteNumber(), 'Custom prefix COT- should be preserved during auto-increment');

        // Test non-matching string fallback
        Quote::query()->delete();
        Quote::create([
            'quote_number' => 'NONUMBERHERE',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('PRES-0001', Quote::nextQuoteNumber(), 'Fallback when no numeric suffix found must return PRES-0001');
    }

    public function test_p2_6_quote_controller_store_collision_retry_mechanism(): void
    {
        $payload = [
            'customer_name' => 'Cliente Concurrente',
            'items' => [
                ['product_name' => 'Articulo A', 'unit_price' => 50.00, 'quantity' => 2],
            ],
        ];

        // First call creates PRES-0001
        $res1 = $this->postJson('/api/quotes', $payload);
        $res1->assertStatus(201);
        $this->assertEquals('PRES-0001', $res1->json('quote_number'));

        // Second call cleanly gets PRES-0002
        $res2 = $this->postJson('/api/quotes', $payload);
        $res2->assertStatus(201);
        $this->assertEquals('PRES-0002', $res2->json('quote_number'));

        $this->assertCount(2, Quote::all());
    }

    // =========================================================================
    // SECTION 2: P2.1 Delivery Note Concurrency & Double Deduction Prevention
    // =========================================================================

    public function test_p2_1_counter_sale_delivery_note_multiple_updates_never_double_deduct(): void
    {
        $prod = Product::create([
            'name' => 'Producto Anti-Doble-Descuento',
            'internal_code' => 'ADD-01',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 100,
            'active' => true,
        ]);

        // 1. Regular counter POS sale of 10 units (stock 100 -> 90)
        $saleRes = $this->postJson('/api/pos/sales', [
            'total' => 200.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 200.00,
                'surcharge_amount' => 0,
                'total_amount' => 200.00,
            ]],
            'items' => [[
                'product_id' => $prod->id,
                'quantity' => 10,
                'unit_price' => 20.00,
                'subtotal' => 200.00,
            ]],
        ]);

        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('sale.id');
        $this->assertEquals(90.0, (float) $prod->fresh()->stock);

        // Verify sale has deducted stock
        $sale = Sale::findOrFail($saleId);
        $this->assertTrue($sale->hasDeductedStock(), 'Counter sale must be recognized as already deducted stock');

        // 2. Generate Delivery Note for this counter sale
        $dnRes = $this->postJson("/api/delivery-notes/from-sale/{$saleId}", ['status' => 'pending']);
        $dnRes->assertStatus(201);
        $dnId = $dnRes->json('id');
        $itemId = $dnRes->json('items.0.id');

        // 3. Repeatedly call updateDelivery (simulating 5 concurrent or sequential deliveries)
        for ($i = 1; $i <= 5; $i++) {
            $deliverRes = $this->putJson("/api/delivery-notes/{$dnId}/deliver", [
                'items' => [
                    ['id' => $itemId, 'delivered_now' => 10],
                ],
            ]);
            $deliverRes->assertStatus(200);

            // EMPIRICAL ASSERTION: Stock MUST stay at 90.0 and never decrease to 80, 70, etc.
            $this->assertEquals(90.0, (float) $prod->fresh()->stock, "Attempt {$i} of updateDelivery must NOT deduct stock again for counter sale");
        }

        // Verify total stock movements for this product: exactly 1 (the initial sale)
        $movements = StockMovement::where('product_id', $prod->id)->get();
        $this->assertCount(1, $movements, 'There must be strictly 1 stock movement for counter sale, regardless of delivery updates');
    }

    public function test_p2_1_deferred_delivery_note_partial_and_over_delivery_bounds(): void
    {
        $prod = Product::create([
            'name' => 'Producto Entrega Diferida',
            'internal_code' => 'DIF-01',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 50,
            'active' => true,
        ]);

        // Create a manual sale that did NOT deduct stock at checkout
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 100,
            'status' => 'completed',
        ]);
        $saleItem = $sale->items()->create([
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'quantity' => 10,
            'unit_price' => 10,
            'subtotal' => 100,
        ]);

        $this->assertFalse($sale->hasDeductedStock(), 'Manual deferred sale should not have checkout stock movements');

        // Create delivery note: 10 purchased, 0 delivered
        $dn = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
            'notes' => 'Despacho pendiente',
        ]);
        $dnItem = $dn->items()->create([
            'product_id' => $prod->id,
            'quantity_purchased' => 10,
            'quantity_delivered' => 0,
        ]);

        // Step 1: Deliver 4 units -> stock goes from 50 to 46
        $res1 = $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 4]],
        ]);
        $res1->assertStatus(200);
        $this->assertEquals(46.0, (float) $prod->fresh()->stock);
        $this->assertEquals('partial', $dn->fresh()->status);
        $this->assertEquals(4.0, (float) $dnItem->fresh()->quantity_delivered);

        // Step 2: Attempt to over-deliver 100 units! -> should be capped at purchased (10), so only 6 more units delivered
        $res2 = $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 100]],
        ]);
        $res2->assertStatus(200);
        $this->assertEquals(40.0, (float) $prod->fresh()->stock, 'Over-delivery must cap at remaining purchased quantity (6), leaving stock at 40');
        $this->assertEquals('delivered', $dn->fresh()->status);
        $this->assertEquals(10.0, (float) $dnItem->fresh()->quantity_delivered);

        // Step 3: Attempt further delivery when already fully delivered -> actualDeliveredNow = 0, stock untouched
        $res3 = $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 5]],
        ]);
        $res3->assertStatus(200);
        $this->assertEquals(40.0, (float) $prod->fresh()->stock, 'Subsequent calls when fully delivered must not deduct any stock');
        $this->assertEquals(10.0, (float) $dnItem->fresh()->quantity_delivered);
    }

    // =========================================================================
    // SECTION 3: Combo Product Ordering & Anti-Deadlock Locking
    // =========================================================================

    public function test_combo_locking_retrieves_and_orders_all_components_ascending(): void
    {
        $stockService = app(StockService::class);

        // Create children with specific IDs
        $child1 = Product::create(['name' => 'Child Low', 'internal_code' => 'C-LOW', 'cost_price' => 5, 'selling_price' => 10, 'stock' => 100]);
        $child2 = Product::create(['name' => 'Child Mid', 'internal_code' => 'C-MID', 'cost_price' => 8, 'selling_price' => 15, 'stock' => 100]);
        $child3 = Product::create(['name' => 'Child High', 'internal_code' => 'C-HIGH', 'cost_price' => 12, 'selling_price' => 20, 'stock' => 100]);

        $comboParent = Product::create(['name' => 'Combo Pack', 'internal_code' => 'CP-01', 'cost_price' => 0, 'selling_price' => 50, 'stock' => 0, 'is_combo' => true]);

        // Attach children in reverse order
        DB::table('product_combos')->insert([
            ['parent_product_id' => $comboParent->id, 'child_product_id' => $child3->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['parent_product_id' => $comboParent->id, 'child_product_id' => $child1->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['parent_product_id' => $comboParent->id, 'child_product_id' => $child2->id, 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::transaction(function () use ($stockService, $comboParent, $child1, $child2, $child3) {
            $locked = $stockService->lockProducts([$comboParent->id]);

            // All 4 products (parent + 3 children) must be loaded
            $this->assertCount(4, $locked);
            $this->assertTrue($locked->has($comboParent->id));
            $this->assertTrue($locked->has($child1->id));
            $this->assertTrue($locked->has($child2->id));
            $this->assertTrue($locked->has($child3->id));

            // Verify order of keys is strictly ascending
            $keys = $locked->keys()->toArray();
            $sortedKeys = $keys;
            sort($sortedKeys, SORT_NUMERIC);
            $this->assertSame($sortedKeys, $keys, 'lockProducts MUST return items sorted in strictly ascending ID order');
        });
    }

    public function test_combo_delivery_note_deducts_components_cleanly(): void
    {
        $comp1 = Product::create(['name' => 'Component A', 'internal_code' => 'CMP-A', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 50]);
        $comp2 = Product::create(['name' => 'Component B', 'internal_code' => 'CMP-B', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 30]);

        $combo = Product::create(['name' => 'Kit AB', 'internal_code' => 'KIT-AB', 'cost_price' => 0, 'selling_price' => 60, 'stock' => 0, 'is_combo' => true]);

        DB::table('product_combos')->insert([
            ['parent_product_id' => $combo->id, 'child_product_id' => $comp1->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['parent_product_id' => $combo->id, 'child_product_id' => $comp2->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Create deferred sale with combo
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 180,
            'status' => 'completed',
        ]);
        $sale->items()->create([
            'product_id' => $combo->id,
            'product_name' => $combo->name,
            'quantity' => 3,
            'unit_price' => 60,
            'subtotal' => 180,
        ]);

        $dn = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
            'notes' => 'Envío de Kit',
        ]);
        $dnItem = $dn->items()->create([
            'product_id' => $combo->id,
            'quantity_purchased' => 3,
            'quantity_delivered' => 0,
        ]);

        // Deliver 2 kits:
        // Comp A should deduct: 2 kits * 2 units = 4 units -> stock 50 - 4 = 46
        // Comp B should deduct: 2 kits * 1 unit = 2 units -> stock 30 - 2 = 28
        // Combo parent stock: 0 (unchanged)
        $res = $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 2]],
        ]);

        $res->assertStatus(200);
        $this->assertEquals(46.0, (float) $comp1->fresh()->stock);
        $this->assertEquals(28.0, (float) $comp2->fresh()->stock);
        $this->assertEquals(0.0, (float) $combo->fresh()->stock);
    }

    // =========================================================================
    // SECTION 4: Sale Void Stock Restoration & Zero Inventory Leaks
    // =========================================================================

    public function test_sale_void_counter_sale_with_delivery_note_restores_full_stock(): void
    {
        $prod = Product::create([
            'name' => 'Prod Void Counter',
            'internal_code' => 'PVC-01',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 100,
            'active' => true,
        ]);

        // 1. Counter sale: 5 units sold, stock 100 -> 95
        $saleRes = $this->postJson('/api/pos/sales', [
            'total' => 100.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 100.00,
                'surcharge_amount' => 0,
                'total_amount' => 100.00,
            ]],
            'items' => [[
                'product_id' => $prod->id,
                'quantity' => 5,
                'unit_price' => 20.00,
                'subtotal' => 100.00,
            ]],
        ]);
        $saleId = $saleRes->json('sale.id');
        $this->assertEquals(95.0, (float) $prod->fresh()->stock);

        // 2. Delivery Note created with partial delivery (say 2 delivered)
        $dnRes = $this->postJson("/api/delivery-notes/from-sale/{$saleId}", ['status' => 'pending']);
        $dnId = $dnRes->json('id');
        $dnItemId = $dnRes->json('items.0.id');

        $this->putJson("/api/delivery-notes/{$dnId}/deliver", [
            'items' => [['id' => $dnItemId, 'delivered_now' => 2]],
        ]);

        // Stock is still 95 (no double deduction)
        $this->assertEquals(95.0, (float) $prod->fresh()->stock);

        // 3. Void the sale!
        // Because the sale originally deducted 5 units at counter checkout,
        // voiding it MUST restore all 5 units (95 + 5 = 100), NOT just the 2 on the delivery note!
        $voidRes = $this->postJson("/api/sales/{$saleId}/void", ['cash_shift_id' => $this->shift->id]);
        $voidRes->assertStatus(200);

        $this->assertEquals(100.0, (float) $prod->fresh()->stock, 'Voiding a counter sale MUST restore full purchased quantity (100.0), avoiding stock leakage');
        $this->assertEquals('cancelled', DeliveryNote::find($dnId)->status, 'Delivery note must be marked cancelled');
    }

    public function test_sale_void_deferred_sale_only_restores_actually_delivered_stock(): void
    {
        $prod = Product::create([
            'name' => 'Prod Void Deferred',
            'internal_code' => 'PVD-01',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 100, // starting stock
            'active' => true,
        ]);

        // 1. Create a deferred sale (stock NOT deducted at checkout)
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 200,
            'status' => 'completed',
        ]);
        $sale->items()->create([
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'quantity' => 10,
            'unit_price' => 20,
            'subtotal' => 200,
        ]);

        // Stock is still 100
        $this->assertEquals(100.0, (float) $prod->fresh()->stock);

        // 2. Delivery Note: 10 purchased, deliver 3
        $dn = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
            'notes' => 'Envío a domicilio',
        ]);
        $dnItem = $dn->items()->create([
            'product_id' => $prod->id,
            'quantity_purchased' => 10,
            'quantity_delivered' => 0,
        ]);

        // Deliver 3 units -> stock becomes 97
        $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 3]],
        ]);
        $this->assertEquals(97.0, (float) $prod->fresh()->stock);

        // 3. Void the deferred sale!
        // Since only 3 units were actually delivered/deducted from warehouse,
        // voiding MUST restore ONLY 3 units (97 + 3 = 100), NOT 10!
        // If it restored 10, stock would be 107 (a 7-unit phantom inventory leak).
        $voidRes = $this->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $this->shift->id]);
        $voidRes->assertStatus(200);

        $this->assertEquals(100.0, (float) $prod->fresh()->stock, 'Voiding deferred sale must restore EXACTLY delivered quantity (3 units), leaving stock at original 100');
        $this->assertEquals('cancelled', $dn->fresh()->status);
    }

    public function test_sale_void_deferred_zero_delivered_restores_zero_stock(): void
    {
        $prod = Product::create([
            'name' => 'Prod Zero Delivered',
            'internal_code' => 'PZD-01',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 50,
            'active' => true,
        ]);

        // Deferred sale of 5 units (stock not deducted)
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 100,
            'status' => 'completed',
        ]);
        $sale->items()->create([
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'quantity' => 5,
            'unit_price' => 20,
            'subtotal' => 100,
        ]);

        // Delivery Note: 5 purchased, 0 delivered
        $dn = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
        ]);
        $dn->items()->create([
            'product_id' => $prod->id,
            'quantity_purchased' => 5,
            'quantity_delivered' => 0,
        ]);

        $this->assertEquals(50.0, (float) $prod->fresh()->stock);

        // Void the sale -> zero delivered means zero restored
        $voidRes = $this->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $this->shift->id]);
        $voidRes->assertStatus(200);

        $this->assertEquals(50.0, (float) $prod->fresh()->stock, 'Voiding sale with 0 delivered must restore 0 units, keeping stock exactly at 50');
        $this->assertEquals('cancelled', $dn->fresh()->status);
    }

    public function test_sale_void_combo_deferred_delivery_restores_components_proportionally(): void
    {
        $c1 = Product::create(['name' => 'Burger Patty', 'internal_code' => 'BP-01', 'cost_price' => 2, 'selling_price' => 5, 'stock' => 40]);
        $c2 = Product::create(['name' => 'Burger Bun', 'internal_code' => 'BB-01', 'cost_price' => 1, 'selling_price' => 2, 'stock' => 40]);

        $combo = Product::create(['name' => 'Double Burger Pack', 'internal_code' => 'DBP-01', 'cost_price' => 0, 'selling_price' => 20, 'stock' => 0, 'is_combo' => true]);

        DB::table('product_combos')->insert([
            ['parent_product_id' => $combo->id, 'child_product_id' => $c1->id, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['parent_product_id' => $combo->id, 'child_product_id' => $c2->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Deferred sale: 5 Double Burger Packs
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 100,
            'status' => 'completed',
        ]);
        $sale->items()->create([
            'product_id' => $combo->id,
            'product_name' => $combo->name,
            'quantity' => 5,
            'unit_price' => 20,
            'subtotal' => 100,
        ]);

        // Delivery note: 5 purchased, deliver 2
        $dn = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
        ]);
        $dnItem = $dn->items()->create([
            'product_id' => $combo->id,
            'quantity_purchased' => 5,
            'quantity_delivered' => 0,
        ]);

        // Deliver 2 packs:
        // c1: - (2 * 2) = -4 -> 40 - 4 = 36
        // c2: - (2 * 1) = -2 -> 40 - 2 = 38
        $this->putJson("/api/delivery-notes/{$dn->id}/deliver", [
            'items' => [['id' => $dnItem->id, 'delivered_now' => 2]],
        ]);

        $this->assertEquals(36.0, (float) $c1->fresh()->stock);
        $this->assertEquals(38.0, (float) $c2->fresh()->stock);

        // Void the deferred combo sale!
        // Should restore ONLY the 2 delivered packs:
        // c1: + 4 -> 40
        // c2: + 2 -> 40
        $voidRes = $this->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $this->shift->id]);
        $voidRes->assertStatus(200);

        $this->assertEquals(40.0, (float) $c1->fresh()->stock, 'Component 1 stock must be exactly restored to initial 40');
        $this->assertEquals(40.0, (float) $c2->fresh()->stock, 'Component 2 stock must be exactly restored to initial 40');
        $this->assertEquals(0.0, (float) $combo->fresh()->stock, 'Combo parent stock must remain 0');
    }
}
