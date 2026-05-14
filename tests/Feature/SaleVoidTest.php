<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SaleVoidTest
 *
 * Cubre los casos CRÍTICOS de la anulación de ventas:
 *  AN-01  Anular venta completada → devuelve stock al producto.
 *  AN-02  Anular venta con COMBO → devuelve stock a los hijos, no al padre.
 *  AN-03  Anular venta en CC → revierte balance del cliente + crea CustomerTransaction de reversión.
 *  AN-04  Anular una venta ya anulada → retorna 422.
 *  AN-05  Anular con remito → solo devuelve el stock ya entregado (quantity_delivered).
 *  AN-06  Anular cancela el remito (status → 'cancelled').
 */
class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers locales ────────────────────────────────────────────────────────

    /**
     * Crea una venta completada con su ítem y pago, lista para anular.
     */
    private function crearVentaCompletada(
        Product $product,
        float $qty,
        ?Customer $customer = null,
        ?PaymentMethod $metodo = null
    ): Sale {
        $user   = User::factory()->create(['role' => 'admin']);
        $shift  = $this->crearTurnoAbierto(user: $user);
        $metodo = $metodo ?? $this->crearMetodoEfectivo();

        $sale = Sale::create([
            'total'           => $product->selling_price * $qty,
            'total_surcharge' => 0,
            'payment_status'  => 'paid',
            'amount_due'      => 0,
            'status'          => 'completed',
            'cash_shift_id'   => $shift->id,
            'user_id'         => $user->id,
            'customer_id'     => $customer?->id,
        ]);

        SaleItem::create([
            'sale_id'        => $sale->id,
            'product_id'     => $product->id,
            'product_name'   => $product->name,
            'quantity'       => $qty,
            'unit_price'     => $product->selling_price,
            'unit_cost_price'=> $product->cost_price,
            'subtotal'       => $product->selling_price * $qty,
        ]);

        SalePayment::create([
            'sale_id'           => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount'       => $product->selling_price * $qty,
            'surcharge_amount'  => 0,
            'total_amount'      => $product->selling_price * $qty,
        ]);

        return $sale;
    }

    // ── AN-01: Anular venta → devuelve stock ──────────────────────────────────

    public function test_AN01_anular_venta_devuelve_stock_al_producto(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Producto Anulable',
            'internal_code' => 'AN001',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 8, // Ya descontado (había 10, se vendieron 2)
            'active'        => true,
            'sales_count'   => 2,
        ]);

        $sale = $this->crearVentaCompletada($product, qty: 2);

        $response = $this->actingAsAdmin($admin)
            ->postJson("/api/sales/{$sale->id}/void");

        $response->assertStatus(200)
                 ->assertJsonPath('message', fn($msg) => str_contains($msg, 'anulada'));

        // Stock restaurado: 8 + 2 = 10
        $this->assertEquals(10, (float) $product->fresh()->stock,
            'El stock debe restaurarse al anular la venta');

        // La venta quedó marcada como anulada
        $this->assertEquals('voided', $sale->fresh()->status);

        // Se creó el movimiento de reversión
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type'       => 'in',
            'quantity'   => 2,
        ]);
    }

    // ── AN-02: Anular COMBO → devuelve stock a hijos ──────────────────────────

    public function test_AN02_anular_combo_devuelve_stock_a_hijos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ingrediente = Product::create([
            'name'          => 'Ingrediente',
            'internal_code' => 'ING-AN',
            'selling_price' => 10.00,
            'cost_price'    => 5.00,
            'stock'         => 14, // 20 originales - 6 vendidos (2 combos × 3 unidades)
            'active'        => true,
        ]);

        $combo = Product::create([
            'name'          => 'Combo Anulable',
            'internal_code' => 'COMBO-AN',
            'selling_price' => 500.00,
            'cost_price'    => 0.00,
            'stock'         => 99,
            'active'        => true,
            'is_combo'      => true,
        ]);

        DB::table('product_combos')->insert([
            'parent_product_id' => $combo->id,
            'child_product_id'  => $ingrediente->id,
            'quantity'          => 3,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $sale = $this->crearVentaCompletada($combo, qty: 2);

        $response = $this->actingAsAdmin($admin)
            ->postJson("/api/sales/{$sale->id}/void");

        $response->assertStatus(200);

        // Ingrediente: 14 + (2 × 3) = 20
        $this->assertEquals(20, (float) $ingrediente->fresh()->stock,
            'Los ingredientes del combo deben recuperar su stock');

        // El combo padre no cambia
        $this->assertEquals(99, (float) $combo->fresh()->stock,
            'El stock del combo padre no debe variar');
    }

    // ── AN-03: Anular CC → revierte balance del cliente ──────────────────────

    public function test_AN03_anular_venta_cc_revierte_balance_del_cliente(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cc    = $this->crearMetodoCuentaCorriente();

        $customer = Customer::create([
            'name'            => 'Cliente CC',
            'document_number' => '44444444',
            'balance'         => 500.00, // Tiene deuda de $500
        ]);

        $product = Product::create([
            'name'          => 'Producto CC',
            'internal_code' => 'CC-AN',
            'selling_price' => 500.00,
            'cost_price'    => 250.00,
            'stock'         => 3,
            'active'        => true,
        ]);

        $sale = $this->crearVentaCompletada($product, qty: 1, customer: $customer, metodo: $cc);

        // Crear la transacción de cargo que generó la venta en CC
        CustomerTransaction::create([
            'customer_id'   => $customer->id,
            'user_id'       => $admin->id,
            'sale_id'       => $sale->id,
            'type'          => 'charge',
            'amount'        => 500.00,
            'balance_after' => 500.00,
            'description'   => "Venta en Cta. Cte. — Ticket #{$sale->id}",
        ]);

        $response = $this->actingAsAdmin($admin)
            ->postJson("/api/sales/{$sale->id}/void");

        $response->assertStatus(200);

        // El balance del cliente volvió a 0
        $this->assertEquals(0.00, (float) $customer->fresh()->balance,
            'El balance del cliente debe revertirse al anular la venta CC');

        // Se creó la transacción de reversión en el ledger
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'sale_id'     => $sale->id,
            'type'        => 'payment',
            'amount'      => 500.00,
        ]);
    }

    // ── AN-04: Venta ya anulada → 422 ─────────────────────────────────────────

    public function test_AN04_anular_venta_ya_anulada_retorna_422(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Producto Anulado',
            'internal_code' => 'AN004',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 10,
            'active'        => true,
        ]);

        $sale = $this->crearVentaCompletada($product, qty: 1);
        // Marcarla manualmente como ya anulada
        $sale->update(['status' => 'voided']);

        $response = $this->actingAsAdmin($admin)
            ->postJson("/api/sales/{$sale->id}/void");

        $response->assertStatus(422)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'anulada') || str_contains($msg, 'ya está')
                 );
    }

    // ── AN-05 + AN-06: Anular con remito → solo devuelve stock entregado ──────

    public function test_AN05_AN06_anular_con_remito_solo_devuelve_stock_entregado_y_cancela_remito(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Producto Remito',
            'internal_code' => 'REM-AN',
            'selling_price' => 100.00,
            'cost_price'    => 50.00,
            'stock'         => 7, // 10 - 3 entregados (de 5 comprados)
            'active'        => true,
        ]);

        $sale = $this->crearVentaCompletada($product, qty: 5);

        // Crear remito: se compraron 5 pero solo se entregaron 3
        $deliveryNote = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status'  => 'pending',
            'notes'   => 'Entrega parcial',
        ]);

        DeliveryNoteItem::create([
            'delivery_note_id'   => $deliveryNote->id,
            'product_id'         => $product->id,
            'quantity_purchased' => 5,
            'quantity_delivered' => 3, // Solo 3 fueron entregados y descontados del stock
        ]);

        $response = $this->actingAsAdmin($admin)
            ->postJson("/api/sales/{$sale->id}/void");

        $response->assertStatus(200);

        // Solo devuelve 3 (lo entregado), no 5
        $this->assertEquals(10, (float) $product->fresh()->stock,
            'Solo debe restaurar el stock que fue efectivamente entregado (3), resultando en 7 + 3 = 10');

        // El remito queda cancelado
        $this->assertEquals('cancelled', $deliveryNote->fresh()->status,
            'El remito debe marcarse como cancelado al anular la venta');
    }
}
