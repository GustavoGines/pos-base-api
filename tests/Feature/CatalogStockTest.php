<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CatalogStockTest
 *
 * Cubre los casos CRÍTICOS de Catálogo y Stock:
 *  ST-01  Crear producto crea stock inicial y movimiento.
 *  ST-02  Editar producto modifica datos.
 *  ST-03  Soft Delete: producto desaparece del catálogo pero queda en BD.
 *  ST-04  Ajuste manual de stock actualiza la cantidad y crea StockMovement.
 *  ST-05  Ajuste manual respeta type "in" (incrementa) y "out" (decrementa).
 *  ST-07  Alertas críticas muestra productos bajo stock_min.
 */
class CatalogStockTest extends TestCase
{
    use RefreshDatabase;

    // ── ST-01: Crear producto ─────────────────────────────────────────────────

    public function test_s_t01_crear_producto_registra_stock_y_movimiento_inicial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        $response = $this->postJson('/api/catalog/products', [
            'name' => 'Producto Nuevo',
            'selling_price' => 150.00,
            'cost_price' => 100.00,
            'stock' => 50,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Producto Nuevo');

        $this->assertEquals(50, (float) $response->json('stock'));

        $productId = $response->json('id');

        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 50]);

        // Verificamos el movimiento inicial
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'in',
            'quantity' => 50,
        ]);
    }

    // ── ST-02: Editar producto ────────────────────────────────────────────────

    public function test_s_t02_editar_producto_modifica_datos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod Antiguo',
            'internal_code' => '00001',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->putJson("/api/catalog/products/{$product->id}", [
            'name' => 'Prod Editado',
            'cost_price' => 50,
            'selling_price' => 120,
            'stock' => 10, // Stock no cambia
        ]);

        $response->assertStatus(200);

        $product->refresh();
        $this->assertEquals('Prod Editado', $product->name);
        $this->assertEquals(120, (float) $product->selling_price);

        // No debe haber un nuevo movimiento de stock si el stock no cambió
        $this->assertEquals(0, $product->stockMovements()->count());
    }

    // ── ST-03: Soft Delete ────────────────────────────────────────────────────

    public function test_s_t03_borrar_producto_aplica_soft_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod a Borrar',
            'internal_code' => '00002',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->deleteJson("/api/catalog/products/{$product->id}");

        $response->assertStatus(204);

        // Verificamos que no esté en queries normales
        $this->assertNull(Product::find($product->id));

        // Verificamos que sigue en la BD (SoftDeleted)
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    // ── ST-04 & ST-05: Ajuste Manual de Stock ─────────────────────────────────

    public function test_s_t04_s_t05_ajuste_manual_incrementa_decrementa_crea_movimientos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod Ajuste',
            'internal_code' => '00003',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 20,
        ]);

        $this->actingAsAdmin($admin);

        // 1. Decrementar (out / decrement)
        $responseOut = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'decrement',
            'quantity' => 5,
        ]);

        $responseOut->assertStatus(200);
        $this->assertEquals(15, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 5,
        ]);

        // 2. Incrementar (in / increment)
        $responseIn = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'increment',
            'quantity' => 10,
        ]);

        $responseIn->assertStatus(200);
        $this->assertEquals(25, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
        ]);
    }

    public function test_s_t06_adjust_stock_request_validates_in_out_and_rejects_invalid_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Prod Validacion',
            'internal_code' => 'VAL01',
            'selling_price' => 50,
            'cost_price' => 20,
            'stock' => 30,
        ]);

        $this->actingAsAdmin($admin);

        // 1. Tipo 'in' válido
        $resIn = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 5,
            'notes' => 'Ajuste in válido',
            'min_stock' => 10,
        ]);
        $resIn->assertStatus(200);
        $this->assertEquals(35, (float) $product->fresh()->stock);

        // 2. Tipo 'out' válido
        $resOut = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'out',
            'quantity' => 15,
        ]);
        $resOut->assertStatus(200);
        $this->assertEquals(20, (float) $product->fresh()->stock);

        // 3. Tipo inválido rechazado con 422 por FormRequest
        $resInvalid = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'invalid_type',
            'quantity' => 5,
        ]);
        $resInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ── ST-07: Alerta de Stock Crítico ────────────────────────────────────────

    public function test_s_t07_alertas_muestra_productos_bajo_stock_min(): void
    {
        // Producto normal
        Product::create([
            'name' => 'Normal',
            'internal_code' => 'N001',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 50,
            'min_stock' => 10,
            'active' => true,
        ]);

        // Producto crítico (por debajo del min_stock)
        $critico = Product::create([
            'name' => 'Critico',
            'internal_code' => 'C001',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 5,
            'min_stock' => 10,
            'active' => true,
        ]);

        $response = $this->getJson('/api/catalog/products/alerts/critical');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();

        $this->assertContains($critico->id, $ids, 'El producto crítico debe estar en las alertas');
        $this->assertCount(1, $ids, 'Solo debe haber un producto crítico');
    }
}
