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

    public function test_ST01_crear_producto_registra_stock_y_movimiento_inicial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        $response = $this->postJson('/api/catalog/products', [
            'name'          => 'Producto Nuevo',
            'selling_price' => 150.00,
            'cost_price'    => 100.00,
            'stock'         => 50,
            'active'        => true,
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
            'type'       => 'in',
            'quantity'   => 50,
        ]);
    }

    // ── ST-02: Editar producto ────────────────────────────────────────────────

    public function test_ST02_editar_producto_modifica_datos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Prod Antiguo',
            'internal_code' => '00001',
            'selling_price' => 100,
            'cost_price'    => 50,
            'stock'         => 10,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->putJson("/api/catalog/products/{$product->id}", [
            'name'          => 'Prod Editado',
            'cost_price'    => 50,
            'selling_price' => 120,
            'stock'         => 10, // Stock no cambia
        ]);

        $response->assertStatus(200);

        $product->refresh();
        $this->assertEquals('Prod Editado', $product->name);
        $this->assertEquals(120, (float) $product->selling_price);

        // No debe haber un nuevo movimiento de stock si el stock no cambió
        $this->assertEquals(0, $product->stockMovements()->count());
    }

    // ── ST-03: Soft Delete ────────────────────────────────────────────────────

    public function test_ST03_borrar_producto_aplica_soft_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Prod a Borrar',
            'internal_code' => '00002',
            'selling_price' => 100,
            'cost_price'    => 50,
            'stock'         => 10,
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

    public function test_ST04_ST05_ajuste_manual_incrementa_decrementa_crea_movimientos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name'          => 'Prod Ajuste',
            'internal_code' => '00003',
            'selling_price' => 100,
            'cost_price'    => 50,
            'stock'         => 20,
        ]);

        $this->actingAsAdmin($admin);

        // 1. Decrementar (out / decrement)
        $responseOut = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type'     => 'decrement',
            'quantity' => 5,
        ]);

        $responseOut->assertStatus(200);
        $this->assertEquals(15, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type'       => 'out',
            'quantity'   => 5,
        ]);

        // 2. Incrementar (in / increment)
        $responseIn = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type'     => 'increment',
            'quantity' => 10,
        ]);

        $responseIn->assertStatus(200);
        $this->assertEquals(25, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type'       => 'in',
            'quantity'   => 10,
        ]);
    }

    // ── ST-07: Alerta de Stock Crítico ────────────────────────────────────────

    public function test_ST07_alertas_muestra_productos_bajo_stock_min(): void
    {
        // Producto normal
        Product::create([
            'name'          => 'Normal',
            'internal_code' => 'N001',
            'selling_price' => 100,
            'cost_price'    => 50,
            'stock'         => 50,
            'min_stock'     => 10,
            'active'        => true,
        ]);

        // Producto crítico (por debajo del min_stock)
        $critico = Product::create([
            'name'          => 'Critico',
            'internal_code' => 'C001',
            'selling_price' => 100,
            'cost_price'    => 50,
            'stock'         => 5,
            'min_stock'     => 10,
            'active'        => true,
        ]);

        $response = $this->getJson('/api/catalog/products/alerts/critical');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();

        $this->assertContains($critico->id, $ids, 'El producto crítico debe estar en las alertas');
        $this->assertCount(1, $ids, 'Solo debe haber un producto crítico');
    }
}
