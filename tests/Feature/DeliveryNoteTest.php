<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Sale;
use App\Models\Product;
use App\Models\User;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;

class DeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        $this->register = CashRegister::create(['name' => 'Caja 1']);
        $this->shift = CashShift::create([
            'cash_register_id' => $this->register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now()
        ]);
    }

    public function test_d01_generate_delivery_note_from_sale()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'D01', 'cost_price' => 5, 'selling_price' => 10, 'stock' => 100]);
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 20,
            'status' => 'completed'
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 10,
            'subtotal' => 20
        ]);

        $response = $this->postJson("/api/delivery-notes/from-sale/{$sale->id}", [
            'status' => 'pending'
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('delivery_notes', [
            'sale_id' => $sale->id,
            'status' => 'pending'
        ]);
        
        $this->assertDatabaseHas('delivery_note_items', [
            'product_id' => $product->id,
            'quantity_purchased' => 2,
            'quantity_delivered' => 0
        ]);
    }

    public function test_d02_update_delivery_note_status_and_stock()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'D02', 'cost_price' => 5, 'selling_price' => 10, 'stock' => 100]);
        $sale = Sale::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'total' => 50,
            'status' => 'completed'
        ]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 5,
            'unit_price' => 10,
            'subtotal' => 50
        ]);

        $noteResponse = $this->postJson("/api/delivery-notes/from-sale/{$sale->id}", [
            'status' => 'pending'
        ]);
        $noteId = $noteResponse->json('id');
        $itemId = $noteResponse->json('items.0.id');

        $updateResponse = $this->putJson("/api/delivery-notes/{$noteId}/deliver", [
            'items' => [
                [
                    'id' => $itemId,
                    'delivered_now' => 2
                ]
            ]
        ]);

        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('delivery_notes', ['id' => $noteId, 'status' => 'partial']);
        $this->assertDatabaseHas('delivery_note_items', ['id' => $itemId, 'quantity_delivered' => 2]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 98]);

        $updateResponse2 = $this->putJson("/api/delivery-notes/{$noteId}/deliver", [
            'items' => [
                [
                    'id' => $itemId,
                    'delivered_now' => 3
                ]
            ]
        ]);

        $updateResponse2->assertStatus(200);
        $this->assertDatabaseHas('delivery_notes', ['id' => $noteId, 'status' => 'delivered']);
        $this->assertDatabaseHas('delivery_note_items', ['id' => $itemId, 'quantity_delivered' => 5]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 95]);
    }
}
