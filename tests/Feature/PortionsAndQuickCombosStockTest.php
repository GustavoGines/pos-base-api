<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PortionsAndQuickCombosStockTest
 *
 * Cubre la Fase 1:
 *  - Creación/edición de combos y porciones con ingrediente en stock 0 (y bloqueo de auto-referencia circular).
 *  - Disponibilidad de porciones con divisores periódicos (1/6 = 0.167, 1/12 = 0.083) para 1 y múltiples tortas.
 *  - Snap-to-integer en StockService::deductProductStock / addStockBack sin afectar ventas pequeñas por peso (0.005 kg).
 *  - Descuento y reversión (void) de ingredientes en Combos Rápidos temporales (is_temporary = true + combo_ingredients).
 */
class PortionsAndQuickCombosStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_and_update_portion_or_combo_when_ingredient_has_zero_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $motherCake = Product::create([
            'name' => 'Torta Chocotorta Entera',
            'internal_code' => '10001',
            'cost_price' => 12000.00,
            'selling_price' => 24000.00,
            'stock' => 0.000,
            'active' => true,
        ]);

        $createRes = $this->actingAsAdmin($admin)->postJson('/api/catalog/products', [
            'name' => 'Porción Chocotorta',
            'selling_price' => 3500.00,
            'is_combo' => true,
            'combo_ingredients' => [
                ['id' => $motherCake->id, 'quantity' => 0.125],
            ],
        ]);

        $createRes->assertStatus(201);
        $portionId = (int) $createRes->json('id');
        $this->assertEquals(0.0, (float) $createRes->json('stock'));
        $this->assertEquals(1500.00, (float) $createRes->json('cost_price'));

        $updateRes = $this->actingAsAdmin($admin)->putJson("/api/catalog/products/{$portionId}", [
            'name' => 'Porción Chocotorta',
            'selling_price' => 3800.00,
            'is_combo' => true,
            'combo_ingredients' => [
                ['id' => $motherCake->id, 'quantity' => 0.167],
            ],
        ]);

        $updateRes->assertStatus(200);
        $this->assertEquals(2004.00, (float) $updateRes->json('cost_price'));

        // Bloqueo de auto-referencia circular
        $selfRefRes = $this->actingAsAdmin($admin)->putJson("/api/catalog/products/{$portionId}", [
            'name' => 'Porción Chocotorta',
            'selling_price' => 3800.00,
            'is_combo' => true,
            'combo_ingredients' => [
                ['id' => $portionId, 'quantity' => 1],
            ],
        ]);

        $selfRefRes->assertStatus(422)
            ->assertJsonValidationErrors(['combo_ingredients']);
    }

    public function test_periodic_sixth_portions_sell_to_exact_zero_and_void_back_to_one(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash = $this->crearMetodoEfectivo();

        $motherCake = Product::create([
            'name' => 'Torta Rogel Entera',
            'internal_code' => '10002',
            'cost_price' => 18000.00,
            'selling_price' => 30000.00,
            'stock' => 1.000,
            'active' => true,
        ]);

        $portion = Product::create([
            'name' => 'Porción Rogel (1/6)',
            'internal_code' => '10003',
            'cost_price' => 3006.00,
            'selling_price' => 5500.00,
            'stock' => 6.000,
            'is_combo' => true,
            'active' => true,
        ]);
        $portion->children()->attach($motherCake->id, ['quantity' => 0.167]);

        $this->assertEquals(6.0, $portion->fresh()->calculateComboAvailableStock());

        $saleIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $res = $this->actingAsAdmin($user)->postJson('/api/pos/sales', [
                'total' => 5500.00,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift->id,
                'user_id' => $user->id,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'base_amount' => 5500.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 5500.00,
                ]],
                'items' => [[
                    'product_id' => $portion->id,
                    'quantity' => 1.0,
                    'unit_price' => 5500.00,
                    'subtotal' => 5500.00,
                ]],
            ]);
            $res->assertStatus(201);
            $saleIds[] = (int) ($res->json('sale.id') ?? $res->json('id'));

            $remainingExpected = 6 - $i;
            $this->assertEquals(
                (float) $remainingExpected,
                $portion->fresh()->calculateComboAvailableStock(),
                "Después de vender {$i} porción(es), deben quedar {$remainingExpected} disponibles."
            );
        }

        // Tras vender la 6ta porción, el stock de la torta madre debe clavarse en 0.000 y el Kardex cerrar en -1.000
        $this->assertSame('0.000', $motherCake->fresh()->stock);
        $this->assertEquals(
            -1.0,
            round((float) StockMovement::where('product_id', $motherCake->id)->sum('quantity'), 3)
        );

        // Al anular las 6 ventas, la torta madre vuelve exactamente a 1.000 sin residuos (ni 1.002)
        foreach ($saleIds as $sid) {
            $voidRes = $this->actingAsAdmin($user)->postJson("/api/sales/{$sid}/void", [
                'reason' => 'Prueba anulación de porciones',
                'cash_shift_id' => $shift->id,
            ]);
            $voidRes->assertStatus(200);
        }

        $this->assertSame('1.000', $motherCake->fresh()->stock);
        $this->assertEquals(6.0, $portion->fresh()->calculateComboAvailableStock());
    }

    public function test_multi_cake_availability_and_small_weight_sale_precision_guard(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash = $this->crearMetodoEfectivo();

        $cakeSix = Product::create([
            'name' => 'Torta Base 6u',
            'internal_code' => '10004',
            'cost_price' => 1000.00,
            'selling_price' => 2000.00,
            'stock' => 6.000,
            'active' => true,
        ]);
        $portionSixth = Product::create([
            'name' => 'Porción 1/6 Base',
            'internal_code' => '10005',
            'cost_price' => 167.00,
            'selling_price' => 500.00,
            'stock' => 36.000,
            'is_combo' => true,
            'active' => true,
        ]);
        $portionSixth->children()->attach($cakeSix->id, ['quantity' => 0.167]);
        $this->assertEquals(36.0, $portionSixth->fresh()->calculateComboAvailableStock());

        // Divisor 1/12 (0.083) después de 11 ventas (stock = 1.000 - 11 * 0.083 = 0.087): debe rendir 1 porción
        $cakeTwelve = Product::create([
            'name' => 'Torta 12 Porciones',
            'internal_code' => '10006',
            'cost_price' => 1200.00,
            'selling_price' => 2400.00,
            'stock' => 0.087,
            'active' => true,
        ]);
        $portionTwelfth = Product::create([
            'name' => 'Porción 1/12',
            'internal_code' => '10007',
            'cost_price' => 100.00,
            'selling_price' => 300.00,
            'stock' => 1.000,
            'is_combo' => true,
            'active' => true,
        ]);
        $portionTwelfth->children()->attach($cakeTwelve->id, ['quantity' => 0.083]);
        $this->assertEquals(1.0, $portionTwelfth->fresh()->calculateComboAvailableStock());

        // Divisor exacto 1/4 (0.250) con stock = 0.990: NO debe inventar una 4ta porción
        $exactChild = Product::create([
            'name' => 'Torta Exacta',
            'internal_code' => '10008',
            'cost_price' => 1000.00,
            'selling_price' => 2000.00,
            'stock' => 0.990,
            'active' => true,
        ]);
        $portionQuarter = Product::create([
            'name' => 'Porción 1/4',
            'internal_code' => '10009',
            'cost_price' => 250.00,
            'selling_price' => 600.00,
            'stock' => 3.000,
            'is_combo' => true,
            'active' => true,
        ]);
        $portionQuarter->children()->attach($exactChild->id, ['quantity' => 0.250]);
        $this->assertEquals(3.0, $portionQuarter->fresh()->calculateComboAvailableStock());

        // Venta chica por peso (0.005 kg) desde 1.000 kg: NO debe hacer snap hacia 1.000
        $spice = Product::create([
            'name' => 'Azafrán Suelto',
            'internal_code' => '10010',
            'cost_price' => 10000.00,
            'selling_price' => 20000.00,
            'stock' => 1.000,
            'is_sold_by_weight' => true,
            'unit_type' => 'kg',
            'active' => true,
        ]);

        $weightRes = $this->actingAsAdmin($user)->postJson('/api/pos/sales', [
            'total' => 100.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $shift->id,
            'user_id' => $user->id,
            'payments' => [[
                'payment_method_id' => $cash->id,
                'base_amount' => 100.00,
                'surcharge_amount' => 0,
                'total_amount' => 100.00,
            ]],
            'items' => [[
                'product_id' => $spice->id,
                'quantity' => 0.005,
                'unit_price' => 20000.00,
                'subtotal' => 100.00,
            ]],
        ]);
        $weightRes->assertStatus(201);
        $this->assertSame('0.995', $spice->fresh()->stock);
    }

    public function test_temporary_quick_combo_with_ingredients_deducts_and_void_restores_child_stock(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash = $this->crearMetodoEfectivo();

        $torta = Product::create([
            'name' => 'Torta Selva Negra',
            'internal_code' => '10011',
            'cost_price' => 16000.00,
            'selling_price' => 28000.00,
            'stock' => 2.000,
            'active' => true,
        ]);
        $gaseosa = Product::create([
            'name' => 'Gaseosa 1.5L',
            'internal_code' => '10012',
            'cost_price' => 1500.00,
            'selling_price' => 2800.00,
            'stock' => 10.000,
            'active' => true,
        ]);

        $res = $this->actingAsAdmin($user)->postJson('/api/pos/sales', [
            'total' => 11000.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $shift->id,
            'user_id' => $user->id,
            'payments' => [[
                'payment_method_id' => $cash->id,
                'base_amount' => 11000.00,
                'surcharge_amount' => 0,
                'total_amount' => 11000.00,
            ]],
            'items' => [[
                'product_id' => null,
                'is_temporary' => true,
                'is_combo' => true,
                'name' => 'Merienda x2 (2 porciones + 1 Gaseosa)',
                'quantity' => 1.0,
                'unit_price' => 11000.00,
                'subtotal' => 11000.00,
                'combo_ingredients' => [
                    ['id' => $torta->id, 'quantity' => 0.250],
                    ['id' => $gaseosa->id, 'quantity' => 1.0],
                ],
            ]],
        ]);

        $res->assertStatus(201);
        $saleId = (int) ($res->json('sale.id') ?? $res->json('id'));

        $this->assertSame('1.750', $torta->fresh()->stock);
        $this->assertSame('9.000', $gaseosa->fresh()->stock);

        // El SaleItem temporal calculó su costo automáticamente desde los ingredientes (0.25 * 16000 + 1 * 1500 = 5500)
        $saleItem = SaleItem::where('sale_id', $saleId)->firstOrFail();
        $this->assertEquals(5500.00, (float) $saleItem->unit_cost_price);

        $this->assertDatabaseHas('stock_movements', [
            'sale_id' => $saleId,
            'product_id' => $torta->id,
            'type' => 'sale',
            'quantity' => -0.250,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'sale_id' => $saleId,
            'product_id' => $gaseosa->id,
            'type' => 'sale',
            'quantity' => -1.000,
        ]);

        // Anular la venta debe restituir el stock de los hijos del combo rápido
        $voidRes = $this->actingAsAdmin($user)->postJson("/api/sales/{$saleId}/void", [
            'reason' => 'Error de carga',
            'cash_shift_id' => $shift->id,
        ]);
        $voidRes->assertStatus(200);

        $this->assertSame('2.000', $torta->fresh()->stock);
        $this->assertSame('10.000', $gaseosa->fresh()->stock);
    }
}
