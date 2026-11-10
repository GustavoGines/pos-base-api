<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CartPriceCostUpdateTest
 *
 * Verifica la edición de Precio de Venta y Costo desde el Carrito POS:
 *  1. Regla "Costo como Ancla" en BD: PUT /api/catalog/products/{id} enviando solo selling_price
 *     actualiza el precio de venta y mantiene el cost_price 100% intacto.
 *  2. Actualización conjunta Costo + Venta y recálculo automático del cost_price de combos padres.
 *  3. Validación parcial de cost_price: impide guardar un cost_price mayor al selling_price actual
 *     cuando no se envía selling_price en el payload.
 *  4. Modo "Solo esta venta" (Temporal): POST /api/pos/sales con unit_price modificado (mayor o menor)
 *     y custom_cost_price guarda esos valores en sale_items sin tocar la tabla products.
 *  5. Retrocompatibilidad v1.8.5: POST /api/pos/sales enviando un cost_price cacheado/desactualizado
 *     pero SIN custom_cost_price usa siempre el costo real de la base de datos.
 */
class CartPriceCostUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cost_as_anchor_partial_update_only_modifies_selling_price_keeping_cost_intact(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $product = Product::create([
            'name' => 'Coca Cola 2.25L',
            'internal_code' => 'CC225',
            'selling_price' => 1400.00,
            'cost_price' => 1000.00,
            'stock' => 20,
            'active' => true,
        ]);

        $response = $this->actingAsAdmin($admin)
            ->putJson("/api/catalog/products/{$product->id}", [
                'selling_price' => 1600.00,
            ]);

        $response->assertStatus(200);

        $fresh = $product->fresh();
        $this->assertEquals(1600.00, (float) $fresh->selling_price);
        $this->assertEquals(1000.00, (float) $fresh->cost_price);
    }

    public function test_updating_cost_and_selling_price_recalculates_parent_combo_cost_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ingredient = Product::create([
            'name' => 'Hamburguesa Carne',
            'internal_code' => 'ING-HAM',
            'selling_price' => 1500.00,
            'cost_price' => 800.00,
            'stock' => 50,
            'active' => true,
            'is_combo' => false,
        ]);

        $combo = Product::create([
            'name' => 'Combo Doble Burger',
            'internal_code' => 'CMB-01',
            'selling_price' => 4500.00,
            'cost_price' => 1600.00,
            'stock' => 0,
            'active' => true,
            'is_combo' => true,
        ]);

        DB::table('product_combos')->insert([
            'parent_product_id' => $combo->id,
            'child_product_id' => $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsAdmin($admin)
            ->putJson("/api/catalog/products/{$ingredient->id}", [
                'cost_price' => 1100.00,
                'selling_price' => 2000.00,
            ]);

        $response->assertStatus(200);

        $this->assertEquals(1100.00, (float) $ingredient->fresh()->cost_price);
        $this->assertEquals(2000.00, (float) $ingredient->fresh()->selling_price);
        // El combo lleva 2 unidades del ingrediente -> 2 * 1100 = 2200
        $this->assertEquals(2200.00, (float) $combo->fresh()->cost_price);
    }

    public function test_partial_cost_price_update_rejects_cost_greater_than_existing_selling_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $product = Product::create([
            'name' => 'Yerba Mate 1Kg',
            'internal_code' => 'YM1000',
            'selling_price' => 2000.00,
            'cost_price' => 1200.00,
            'stock' => 15,
            'active' => true,
        ]);

        $response = $this->actingAsAdmin($admin)
            ->putJson("/api/catalog/products/{$product->id}", [
                'cost_price' => 2500.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cost_price']);

        $this->assertEquals(1200.00, (float) $product->fresh()->cost_price);
    }

    public function test_single_sale_price_and_cost_override_impacts_only_sale_item_without_touching_catalog(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cash = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Aceite Girasol 1.5L',
            'internal_code' => 'AG15',
            'selling_price' => 1800.00,
            'cost_price' => 1200.00,
            'stock' => 10,
            'active' => true,
        ]);

        // Venta con precio mayor ($2100) y costo ajustado ($1400) solo para esta venta
        $response = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'total' => 2100.00,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 2100.00,
                    'cost_price' => 1200.00,
                    'custom_cost_price' => 1400.00,
                    'custom_description' => 'Aceite Girasol 1.5L (Precio actualizado)',
                    'subtotal' => 2100.00,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 2100.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 2100.00,
                ]],
            ]);

        $response->assertStatus(201);

        // En sale_items quedaron registrados el precio, costo y descripción personalizada de esa venta puntual
        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'unit_price' => 2100.00,
            'unit_cost_price' => 1400.00,
            'product_name' => 'Aceite Girasol 1.5L',
            'custom_description' => 'Aceite Girasol 1.5L (Precio actualizado)',
        ]);

        // El catálogo permanece 100% intacto
        $freshProduct = $product->fresh();
        $this->assertEquals(1800.00, (float) $freshProduct->selling_price);
        $this->assertEquals(1200.00, (float) $freshProduct->cost_price);
    }

    public function test_v185_backward_compatibility_ignores_stale_cost_price_when_custom_cost_price_is_absent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cash = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Harina 0000 1Kg',
            'internal_code' => 'HAR01',
            'selling_price' => 1000.00,
            'cost_price' => 750.00, // Costo real en BD
            'stock' => 30,
            'active' => true,
        ]);

        // Una terminal v1.8.5 envía cost_price: 500.00 (cacheado viejo) sin custom_cost_price
        $response = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'total' => 1000.00,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                    'cost_price' => 500.00,
                    'subtotal' => 1000.00,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 1000.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 1000.00,
                ]],
            ]);

        $response->assertStatus(201);

        // Debe haber guardado 750.00 (el costo real de BD), ignorando el 500.00 cacheado
        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'unit_price' => 1000.00,
            'unit_cost_price' => 750.00,
        ]);
    }

    public function test_collateral_audit_cash_shift_close_and_profit_reports_match_overridden_prices(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $shift = $this->crearTurnoAbierto(fondoInicial: 10000.00, user: $admin);
        $cash = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Gaseosa Cola 3L',
            'internal_code' => 'GC30',
            'selling_price' => 2000.00,
            'cost_price' => 1200.00,
            'stock' => 20,
            'active' => true,
        ]);

        // Venta de 2 unidades con Precio Venta subido a $2500 y Costo subido a $1500 (solo esta venta)
        // Ingreso real: $5000 | Costo real de la venta: $3000 | Ganancia real: $2000
        $saleRes = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'total' => 5000.00,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 2500.00,
                    'cost_price' => 1200.00,
                    'custom_cost_price' => 1500.00,
                    'subtotal' => 5000.00,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 5000.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 5000.00,
                ]],
            ]);

        $saleRes->assertStatus(201);

        // 1. Reporte de Ganancias usa unit_cost_price ($1500 * 2 = $3000) -> Ganancia = $2000
        $repo = app(\App\Repositories\SalesAnalyticsRepository::class);
        $report = $repo->getProfitReport(now()->toDateString(), now()->toDateString(), 'category');
        $group = $report->first();
        $this->assertNotNull($group);
        $this->assertEquals(5000.00, (float) $group['total_revenue']);
        $this->assertEquals(2000.00, (float) $group['total_profit']);

        // 2. Cierre de Caja: saldo esperado = apertura ($10000) + venta efectivo ($5000) = $15000
        $closeRes = $this->actingAsAdmin($admin)
            ->postJson("/api/shifts/{$shift->id}/close", [
                'actual_balance' => 15000.00,
                'closer_user_id' => $admin->id,
                'pin' => '1234',
            ]);

        $closeRes->assertStatus(200);
        $closedShift = $shift->fresh();
        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(15000.00, (float) $closedShift->expected_balance);
        $this->assertEquals(0.00, (float) $closedShift->difference);
    }

    public function test_pending_sale_preserves_custom_cost_price_and_pays_cleanly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cash = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Queso Cremoso Kg',
            'internal_code' => 'QC01',
            'selling_price' => 6000.00,
            'cost_price' => 4000.00,
            'stock' => 10,
            'active' => true,
        ]);

        // 1. Poner venta en espera con precio $6500 y costo $4500 (solo esta venta)
        $holdRes = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'status' => 'pending',
                'total' => 6500.00,
                'total_surcharge' => 0,
                'payments' => [],
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 6500.00,
                    'custom_cost_price' => 4500.00,
                    'subtotal' => 6500.00,
                ]],
            ]);

        $holdRes->assertStatus(201);
        $saleId = $holdRes->json('sale.id');

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'unit_price' => 6500.00,
            'unit_cost_price' => 4500.00,
        ]);

        // 2. Cobrar el ticket en espera enviando el ítem rehidratado con custom_cost_price
        $payRes = $this->actingAsAdmin($admin)
            ->putJson("/api/sales/{$saleId}/pay", [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'total' => 6500.00,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 6500.00,
                    'custom_cost_price' => 4500.00,
                    'subtotal' => 6500.00,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 6500.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 6500.00,
                ]],
            ]);

        $payRes->assertStatus(200);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'unit_price' => 6500.00,
            'unit_cost_price' => 4500.00,
        ]);
    }

    public function test_indivisible_agreed_lot_subtotal_3_for_10000_reconciles_without_cent_discrepancy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cash = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Alfajor Triple',
            'internal_code' => 'ALF03',
            'selling_price' => 3500.00,
            'cost_price' => 2000.00,
            'stock' => 20,
            'active' => true,
        ]);

        // "Llevo 3, ¿me los dejás en $10.000 los tres?"
        // unit_price = round(10000 / 3, 2) = 3333.33 -> 3333.33 * 3 = 9999.99, pero el subtotal pactado es 10000.00
        $res = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $admin->id,
                'total' => 10000.00,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 3333.33,
                    'subtotal' => 10000.00,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 10000.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 10000.00,
                ]],
            ]);

        $res->assertStatus(201);
        $this->assertEquals(10000.00, (float) $res->json('sale.total'));
        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 3333.33,
            'subtotal' => 10000.00,
        ]);
    }

    public function test_quick_barcode_assignment_from_pos_and_duplicate_conflict_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $existingProduct = Product::create([
            'name' => 'Gaseosa Coca Cola 2.25L',
            'internal_code' => 'CC225',
            'barcode' => '7790895000997',
            'selling_price' => 2500.00,
            'cost_price' => 1500.00,
            'stock' => 10,
            'active' => true,
        ]);

        $targetProduct = Product::create([
            'name' => 'Galletitas Oreo 118g',
            'internal_code' => 'OREO1',
            'barcode' => null,
            'selling_price' => 1200.00,
            'cost_price' => 800.00,
            'stock' => 15,
            'active' => true,
        ]);

        // 1. Intentar asignar un código de barras ya usado por Coca Cola -> 422 mencionando el nombre del producto
        $conflictRes = $this->actingAsAdmin($admin)
            ->putJson("/api/catalog/products/{$targetProduct->id}", [
                'barcode' => '7790895000997',
            ]);

        $conflictRes->assertStatus(422)
            ->assertJsonValidationErrors(['barcode']);
        $this->assertStringContainsString(
            'Gaseosa Coca Cola 2.25L',
            (string) ($conflictRes->json('errors.barcode.0') ?? '')
        );

        // 2. Asignar un código de barras nuevo válido -> 200 OK y conserva precio/costo intactos
        $okRes = $this->actingAsAdmin($admin)
            ->putJson("/api/catalog/products/{$targetProduct->id}", [
                'barcode' => '7622210654321',
            ]);

        $okRes->assertStatus(200);
        $fresh = $targetProduct->fresh();
        $this->assertSame('7622210654321', $fresh->barcode);
        $this->assertEquals(1200.00, (float) $fresh->selling_price);
        $this->assertEquals(800.00, (float) $fresh->cost_price);
    }
}
