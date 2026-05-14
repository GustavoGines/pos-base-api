<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PosProcessSaleTest
 *
 * Cubre los casos CRÍTICOS del método processSale():
 *  V-01  Venta simple: crea Sale, SaleItem, StockMovement y descuenta stock.
 *  V-02  Turno cerrado → 422 con mensaje legible.
 *  V-03  Venta CC sin cliente → 422.
 *  V-04  Venta CC con cliente → registra CustomerTransaction y sube balance.
 *  V-05  Venta de COMBO → descuenta stock de hijos, NO del padre.
 *  V-13  Transacción ACID → si un ítem falla, no queda nada guardado a medias.
 *  V-09  Motor de precios por volumen (unit test del modelo).
 *  V-10  Cuenta interna → NO incrementa sales_count.
 */
class PosProcessSaleTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers locales ────────────────────────────────────────────────────────

    /**
     * Construye el payload base de una venta con un solo ítem.
     */
    private function buildSalePayload(
        int $shiftId,
        int $userId,
        int $productId,
        int $paymentMethodId,
        float $qty      = 1.0,
        float $price    = 100.0,
        array $extra    = []
    ): array {
        return array_merge([
            'total'           => round($price * $qty, 2),
            'total_surcharge' => 0,
            'cash_shift_id'   => $shiftId,
            'user_id'         => $userId,
            'payments'        => [[
                'payment_method_id' => $paymentMethodId,
                'base_amount'       => round($price * $qty, 2),
                'surcharge_amount'  => 0,
                'total_amount'      => round($price * $qty, 2),
            ]],
            'items' => [[
                'product_id' => $productId,
                'quantity'   => $qty,
                'unit_price' => $price,
                'subtotal'   => round($price * $qty, 2),
            ]],
        ], $extra);
    }

    // ── V-01: Venta simple ─────────────────────────────────────────────────────

    public function test_V01_venta_simple_crea_sale_item_y_descuenta_stock(): void
    {
        $user    = User::factory()->create(['role' => 'admin']);
        $shift   = $this->crearTurnoAbierto(user: $user);
        $cash    = $this->crearMetodoEfectivo();
        $product = Product::create([
            'name'          => 'Producto Test',
            'internal_code' => 'P001',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 10,
            'active'        => true,
        ]);

        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', $this->buildSalePayload(
                $shift->id, $user->id, $product->id, $cash->id, qty: 2, price: 100.0
            ));

        $response->assertStatus(201)
                 ->assertJsonPath('message', 'Sale processed successfully');

        // Stock descontado
        $this->assertEquals(8, (float) $product->fresh()->stock);

        // Registros creados
        $this->assertDatabaseHas('sales', ['cash_shift_id' => $shift->id, 'status' => 'completed']);
        $this->assertDatabaseHas('sale_items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type'       => 'sale',
            'quantity'   => -2,
        ]);
    }

    // ── V-02: Turno cerrado ────────────────────────────────────────────────────

    public function test_V02_venta_con_turno_cerrado_retorna_422(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $cash  = $this->crearMetodoEfectivo();
        $register = CashRegister::firstOrCreate(
            ['id' => 1],
            ['name' => 'Caja Principal', 'is_active' => true]
        );

        // Turno CERRADO
        $closedShift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id'          => $user->id,
            'opened_at'        => now()->subHour(),
            'closed_at'        => now(),
            'opening_balance'  => 0,
            'status'           => 'closed',
        ]);

        $product = Product::create([
            'name'          => 'Producto Test',
            'internal_code' => 'P002',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 5,
            'active'        => true,
        ]);

        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', $this->buildSalePayload(
                $closedShift->id, $user->id, $product->id, $cash->id
            ));

        $response->assertStatus(422)
                 ->assertJsonPath('errors.cash_shift_id.0', fn($msg) =>
                     str_contains($msg, 'turno') || str_contains($msg, 'cerrado')
                 );
    }

    // ── V-03: Venta CC sin cliente ────────────────────────────────────────────

    public function test_V03_venta_cc_sin_cliente_retorna_422(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cc    = $this->crearMetodoCuentaCorriente();
        $product = Product::create([
            'name'          => 'Producto CC',
            'internal_code' => 'P003',
            'selling_price' => 200.00,
            'cost_price'    => 100.00,
            'stock'         => 5,
            'active'        => true,
        ]);

        $payload = $this->buildSalePayload(
            $shift->id, $user->id, $product->id, $cc->id, price: 200.0
        );
        // Sin customer_id (null explícito)
        $payload['customer_id'] = null;

        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', $payload);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.customer_id.0', fn($msg) =>
                     str_contains($msg, 'Cuenta Corriente') || str_contains($msg, 'cliente')
                 );
    }

    // ── V-04: Venta CC con cliente → CustomerTransaction + balance ────────────

    public function test_V04_venta_cc_con_cliente_registra_transaction_y_sube_balance(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cc    = $this->crearMetodoCuentaCorriente();

        $customer = Customer::create([
            'name'            => 'Juan Test',
            'document_number' => '12345678',
            'balance'         => 0,
        ]);

        $product = Product::create([
            'name'          => 'Producto CC',
            'internal_code' => 'P004',
            'selling_price' => 300.00,
            'cost_price'    => 150.00,
            'stock'         => 5,
            'active'        => true,
        ]);

        $payload = $this->buildSalePayload(
            $shift->id, $user->id, $product->id, $cc->id, price: 300.0
        );
        $payload['customer_id'] = $customer->id;

        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', $payload);

        $response->assertStatus(201);

        // Balance del cliente subió
        $this->assertEquals(300.00, (float) $customer->fresh()->balance);

        // Se creó la transacción en el ledger
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'type'        => 'charge',
            'amount'      => 300.00,
        ]);
    }

    // ── V-05: Venta COMBO → descuenta hijos, NO padre ────────────────────────

    public function test_V05_venta_combo_descuenta_stock_de_hijos_no_del_padre(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash  = $this->crearMetodoEfectivo();

        $ingrediente = Product::create([
            'name'          => 'Ingrediente A',
            'internal_code' => 'ING01',
            'selling_price' => 10.00,
            'cost_price'    => 5.00,
            'stock'         => 20,
            'active'        => true,
            'is_combo'      => false,
        ]);

        $combo = Product::create([
            'name'          => 'Combo Test',
            'internal_code' => 'COMBO01',
            'selling_price' => 500.00,
            'cost_price'    => 0.00,
            'stock'         => 99, // El padre no se descuenta
            'active'        => true,
            'is_combo'      => true,
        ]);

        // El combo consume 3 unidades del ingrediente por cada unidad vendida
        DB::table('product_combos')->insert([
            'parent_product_id' => $combo->id,
            'child_product_id'  => $ingrediente->id,
            'quantity'          => 3,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Vender 2 combos → debe descontar 6 del ingrediente
        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', $this->buildSalePayload(
                $shift->id, $user->id, $combo->id, $cash->id, qty: 2, price: 500.0
            ));

        $response->assertStatus(201);

        $this->assertEquals(14, (float) $ingrediente->fresh()->stock, 'Stock del ingrediente debe ser 20 - 6 = 14');
        $this->assertEquals(99, (float) $combo->fresh()->stock, 'Stock del combo padre no debe cambiar');

        // El movimiento de stock debe ser del ingrediente, no del combo
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $ingrediente->id,
            'type'       => 'sale',
            'quantity'   => -6,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $combo->id,
            'type'       => 'sale',
        ]);
    }

    // ── V-10: Cuenta interna NO incrementa sales_count ───────────────────────

    public function test_V10_venta_cuenta_interna_no_incrementa_sales_count(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash  = $this->crearMetodoEfectivo();

        $cuentaInterna = Customer::create([
            'name'                => 'Consumo Interno',
            'document_number'     => '99999999',
            'balance'             => 0,
            'is_internal_account' => true,
        ]);

        $product = Product::create([
            'name'          => 'Producto Interno',
            'internal_code' => 'P010',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 10,
            'active'        => true,
            'sales_count'   => 0,
        ]);

        $payload = $this->buildSalePayload(
            $shift->id, $user->id, $product->id, $cash->id, qty: 3, price: 100.0
        );
        $payload['customer_id'] = $cuentaInterna->id;

        $this->actingAsAdmin($user)->postJson('/api/pos/sales', $payload);

        $this->assertEquals(0, $product->fresh()->sales_count, 'Cuenta interna no debe incrementar sales_count');
    }

    // ── V-13: Transacción ACID ────────────────────────────────────────────────

    public function test_V13_si_un_producto_no_existe_la_transaccion_se_revierte(): void
    {
        $user    = User::factory()->create(['role' => 'admin']);
        $shift   = $this->crearTurnoAbierto(user: $user);
        $cash    = $this->crearMetodoEfectivo();
        $product = Product::create([
            'name'          => 'Producto Válido',
            'internal_code' => 'P013',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 5,
            'active'        => true,
        ]);

        $salesAntes = \App\Models\Sale::count();

        $response = $this->actingAsAdmin($user)
            ->postJson('/api/pos/sales', [
                'total'           => 200.00,
                'total_surcharge' => 0,
                'cash_shift_id'   => $shift->id,
                'user_id'         => $user->id,
                'payments'        => [[
                    'payment_method_id' => $cash->id,
                    'base_amount'       => 200.00,
                    'surcharge_amount'  => 0,
                    'total_amount'      => 200.00,
                ]],
                'items' => [
                    ['product_id' => $product->id,  'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100],
                    ['product_id' => 99999,          'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100], // No existe
                ],
            ]);

        // La validación debe rechazarlo antes de tocar la BD
        $response->assertStatus(422);

        // La BD no debe haber cambiado
        $this->assertEquals($salesAntes, \App\Models\Sale::count(), 'No debe crearse ninguna venta si la validación falla');
        $this->assertEquals(5, (float) $product->fresh()->stock, 'El stock no debe cambiar si la transacción se rechaza');
    }

    // ── V-09: Motor de precios volumétrico (Unit del modelo) ──────────────────

    public function test_V09_motor_de_precios_volumetrico(): void
    {
        $product = Product::create([
            'name'          => 'Producto Volumen',
            'internal_code' => 'PVOL',
            'selling_price' => 200.00,
            'cost_price'    => 100.00,
            'stock'         => 100,
            'active'        => true,
        ]);

        $product->priceTiers()->createMany([
            ['min_quantity' =>  50, 'unit_price' => 170.00],
            ['min_quantity' => 100, 'unit_price' => 140.00],
        ]);

        $this->assertEquals(200.0, $product->getPriceForQuantity(1),   'Debajo del primer tramo → precio base');
        $this->assertEquals(200.0, $product->getPriceForQuantity(49),  'Por debajo de 50 → precio base');
        $this->assertEquals(170.0, $product->getPriceForQuantity(50),  'Exactamente en tramo 50 → $170');
        $this->assertEquals(170.0, $product->getPriceForQuantity(75),  'Entre 50 y 100 → $170');
        $this->assertEquals(140.0, $product->getPriceForQuantity(100), 'En tramo 100 → $140');
        $this->assertEquals(140.0, $product->getPriceForQuantity(200), 'Más de 100 → $140');
    }
}
