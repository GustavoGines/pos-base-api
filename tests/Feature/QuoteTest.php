<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QuoteTest
 *
 * Cubre los casos de Presupuestos (Módulo Quotes):
 *  Q-01  Crear presupuesto NO descuenta stock de ningún producto. ← REGLA DE ORO
 *  Q-02  `quote_number` se auto-genera y es secuencial.
 *  Q-03  El total del presupuesto se calcula en el servidor.
 *  Q-04  No se puede editar ni eliminar un presupuesto `approved`.
 *  Q-05  Presupuesto vencido aparece con status `expired` en el filtro.
 *  Q-06  Al cobrar el presupuesto desde POS, se marca como `approved`.
 *  Q-07  El módulo requiere el feature `quotes` habilitado (403 si no).
 */
class QuoteTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers locales ────────────────────────────────────────────────────────

    /**
     * Habilita el feature `quotes` en la tabla business_settings.
     */
    private function habilitarFeatureQuotes(): void
    {
        \App\Models\BusinessSetting::create([
            'key'   => 'license_features_dict',
            'value' => json_encode(['quotes' => true]),
        ]);
    }

    /**
     * Construye el payload base para crear un presupuesto.
     */
    private function buildQuotePayload(array $productos, array $extra = []): array
    {
        $items = collect($productos)->map(fn($p) => [
            'product_id'   => $p['product']->id,
            'product_name' => $p['product']->name,
            'unit_price'   => $p['price'] ?? $p['product']->selling_price,
            'quantity'     => $p['qty'] ?? 1,
        ])->toArray();

        return array_merge([
            'customer_name'  => 'Cliente Presupuesto',
            'customer_phone' => '11-2233-4455',
            'notes'          => 'Test presupuesto',
            'items'          => $items,
        ], $extra);
    }

    // ── Q-01: REGLA DE ORO — Crear presupuesto NO toca el stock ──────────────

    public function test_Q01_REGLA_DE_ORO_crear_presupuesto_no_descuenta_stock(): void
    {
        $this->habilitarFeatureQuotes();
        $admin = User::factory()->create(['role' => 'admin']);

        // Crear múltiples productos con stock conocido
        $p1 = Product::create([
            'name'          => 'Producto Alpha',
            'internal_code' => 'ALPHA',
            'selling_price' => 150.00,
            'cost_price'    => 70.00,
            'stock'         => 25,
            'active'        => true,
        ]);
        $p2 = Product::create([
            'name'          => 'Producto Beta',
            'internal_code' => 'BETA',
            'selling_price' => 300.00,
            'cost_price'    => 150.00,
            'stock'         => 10,
            'active'        => true,
        ]);
        $p3 = Product::create([
            'name'          => 'Producto Gamma',
            'internal_code' => 'GAMMA',
            'selling_price' => 50.00,
            'cost_price'    => 20.00,
            'stock'         => 100,
            'active'        => true,
        ]);

        // Registrar stocks ANTES del presupuesto
        $stockAntes = [$p1->stock, $p2->stock, $p3->stock];

        $this->actingAsAdmin($admin);

        $response = $this->postJson('/api/quotes', $this->buildQuotePayload([
            ['product' => $p1, 'qty' => 5,  'price' => 150.00],
            ['product' => $p2, 'qty' => 3,  'price' => 300.00],
            ['product' => $p3, 'qty' => 20, 'price' => 50.00],
        ]));

        $response->assertStatus(201);

        // ── VERIFICACIÓN ESTRICTA: ningún stock debe haber cambiado ──────────
        $this->assertEquals($stockAntes[0], (float) $p1->fresh()->stock,
            "❌ Producto Alpha: el stock NO debe cambiar al crear un presupuesto");
        $this->assertEquals($stockAntes[1], (float) $p2->fresh()->stock,
            "❌ Producto Beta: el stock NO debe cambiar al crear un presupuesto");
        $this->assertEquals($stockAntes[2], (float) $p3->fresh()->stock,
            "❌ Producto Gamma: el stock NO debe cambiar al crear un presupuesto");

        // Tampoco deben existir stock_movements
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('stock_movements')->count(),
            "❌ No debe haber stock_movements después de crear un presupuesto");
    }

    // ── Q-02: quote_number secuencial ─────────────────────────────────────────

    public function test_Q02_quote_number_se_autogenera_y_es_secuencial(): void
    {
        $this->habilitarFeatureQuotes();
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod Q02', 'internal_code' => 'Q02',
            'selling_price' => 100, 'cost_price' => 50, 'stock' => 10, 'active' => true,
        ]);

        $this->actingAsAdmin($admin);

        // Crear dos presupuestos consecutivos
        $r1 = $this->postJson('/api/quotes', $this->buildQuotePayload([['product' => $product]]));
        $r2 = $this->postJson('/api/quotes', $this->buildQuotePayload([['product' => $product]]));

        $r1->assertStatus(201);
        $r2->assertStatus(201);

        $num1 = $r1->json('quote_number');
        $num2 = $r2->json('quote_number');

        $this->assertNotNull($num1, 'El presupuesto debe tener un quote_number');
        $this->assertNotNull($num2, 'El segundo presupuesto debe tener un quote_number');
        $this->assertNotEquals($num1, $num2, 'Los quote_numbers deben ser únicos');

        // Verificar que son secuenciales (removiendo el prefijo si existe)
        $val1 = (int) preg_replace('/[^0-9]/', '', $num1);
        $val2 = (int) preg_replace('/[^0-9]/', '', $num2);
        
        $this->assertGreaterThan($val1, $val2,
            'El segundo quote_number debe ser mayor que el primero');
    }

    // ── Q-03: Total calculado en servidor ─────────────────────────────────────

    public function test_Q03_total_se_calcula_en_el_servidor_no_en_el_cliente(): void
    {
        $this->habilitarFeatureQuotes();
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod Q03', 'internal_code' => 'Q03',
            'selling_price' => 100, 'cost_price' => 50, 'stock' => 10, 'active' => true,
        ]);

        $this->actingAsAdmin($admin);

        // Enviamos items con unit_price y qty — el server debe calcular el total
        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Test Cliente',
            'items'         => [
                ['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 150.00, 'quantity' => 4],
                ['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 80.00,  'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);

        // El total correcto es (150×4) + (80×2) = 600 + 160 = 760
        $totalEnRespuesta = (float) $response->json('total');
        $this->assertEquals(760.00, $totalEnRespuesta,
            'El servidor debe calcular el total correctamente: (150×4)+(80×2) = 760');
    }

    // ── Q-04a: No se puede editar presupuesto aprobado ────────────────────────

    public function test_Q04a_no_se_puede_editar_presupuesto_aprobado(): void
    {
        $this->habilitarFeatureQuotes();
        $admin = User::factory()->create(['role' => 'admin']);

        $quote = Quote::create([
            'quote_number'  => 'P-0001',
            'status'        => 'approved',
            'subtotal'      => 500.00,
            'total'         => 500.00,
            'customer_name' => 'Cliente Aprobado',
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->putJson("/api/quotes/{$quote->id}", [
            'customer_name' => 'Intento de Cambio',
            'notes'         => 'Modificación no permitida',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'cobrado') || str_contains($msg, 'editar') || str_contains($msg, 'aprobado')
                 );

        // El nombre no debe haber cambiado
        $this->assertEquals('Cliente Aprobado', $quote->fresh()->customer_name);
    }

    // ── Q-04b: No se puede eliminar presupuesto aprobado ─────────────────────

    public function test_Q04b_no_se_puede_eliminar_presupuesto_aprobado(): void
    {
        $this->habilitarFeatureQuotes();
        $admin = User::factory()->create(['role' => 'admin']);

        $quote = Quote::create([
            'quote_number'  => 'P-0002',
            'status'        => 'approved',
            'subtotal'      => 200.00,
            'total'         => 200.00,
            'customer_name' => 'Cliente Aprobado 2',
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->deleteJson("/api/quotes/{$quote->id}");

        $response->assertStatus(422)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'cobrado') || str_contains($msg, 'eliminar')
                 );

        $this->assertDatabaseHas('quotes', ['id' => $quote->id]);
    }

    // ── Q-04c: Sí se puede eliminar un presupuesto 'pending' ─────────────────

    public function test_Q04c_se_puede_eliminar_presupuesto_pendiente(): void
    {
        $this->habilitarFeatureQuotes();
        $admin = User::factory()->create(['role' => 'admin']);

        $quote = Quote::create([
            'quote_number'  => 'P-0003',
            'status'        => 'pending',
            'subtotal'      => 100.00,
            'total'         => 100.00,
            'customer_name' => 'Cliente Borrable',
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->deleteJson("/api/quotes/{$quote->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('quotes', ['id' => $quote->id]);
    }

    // ── Q-05: Filtro 'expired' muestra presupuestos vencidos ─────────────────

    public function test_Q05_filtro_expired_muestra_presupuestos_vencidos(): void
    {
        $this->habilitarFeatureQuotes();
        $admin = User::factory()->create(['role' => 'admin']);

        // Presupuesto vencido: valid_until en el pasado y status = pending
        $vencido = Quote::create([
            'quote_number'  => 'P-EXP1',
            'status'        => 'pending',
            'subtotal'      => 100.00,
            'total'         => 100.00,
            'customer_name' => 'Cliente Vencido',
            'valid_until'   => now()->subDays(5)->toDateString(),
        ]);

        // Presupuesto vigente: valid_until en el futuro
        $vigente = Quote::create([
            'quote_number'  => 'P-VIG1',
            'status'        => 'pending',
            'subtotal'      => 200.00,
            'total'         => 200.00,
            'customer_name' => 'Cliente Vigente',
            'valid_until'   => now()->addDays(7)->toDateString(),
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->getJson('/api/quotes?status=expired');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();

        $this->assertContains($vencido->id, $ids,
            'El presupuesto vencido debe aparecer en el filtro expired');
        $this->assertNotContains($vigente->id, $ids,
            'El presupuesto vigente NO debe aparecer en el filtro expired');
    }

    // ── Q-06: Cobrar desde POS marca el presupuesto como 'approved' ──────────

    public function test_Q06_cobrar_desde_POS_marca_presupuesto_como_approved(): void
    {
        $this->habilitarFeatureQuotes();

        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $user);
        $cash  = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name'          => 'Producto Quote POS',
            'internal_code' => 'QPOS01',
            'selling_price' => 200.00,
            'cost_price'    => 100.00,
            'stock'         => 10,
            'active'        => true,
        ]);

        $quote = Quote::create([
            'quote_number'  => 'P-0010',
            'status'        => 'pending',
            'subtotal'      => 200.00,
            'total'         => 200.00,
            'customer_name' => 'Cliente POS',
        ]);

        QuoteItem::create([
            'quote_id'     => $quote->id,
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => 200.00,
            'quantity'     => 1,
            'subtotal'     => 200.00,
        ]);

        $this->actingAsAdmin($user);

        // Procesar la venta vinculada al presupuesto
        $response = $this->postJson('/api/pos/sales', [
            'total'           => 200.00,
            'total_surcharge' => 0,
            'cash_shift_id'   => $shift->id,
            'user_id'         => $user->id,
            'quote_id'        => $quote->id,
            'payments'        => [[
                'payment_method_id' => $cash->id,
                'base_amount'       => 200.00,
                'surcharge_amount'  => 0,
                'total_amount'      => 200.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'unit_price' => 200.00,
                'subtotal'   => 200.00,
            ]],
        ]);

        $response->assertStatus(201);

        $this->assertEquals('approved', $quote->fresh()->status,
            'El presupuesto debe quedar como approved al ser cobrado desde el POS');
    }

    // ── Q-07: Feature gate 'quotes' — sin habilitarlo retorna 403 ────────────

    public function test_Q07_sin_feature_quotes_habilitado_retorna_403(): void
    {
        // NO habilitamos el feature quotes
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAsAdmin($admin);

        $response = $this->getJson('/api/quotes');

        $response->assertStatus(403);
    }
}
