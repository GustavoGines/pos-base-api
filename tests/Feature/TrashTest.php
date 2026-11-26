<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrashTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
    }

    public function test_tr01_list_trashed_products()
    {
        $product1 = Product::create(['name' => 'P1', 'internal_code' => 'T01', 'cost_price' => 10, 'selling_price' => 20]);
        $product2 = Product::create(['name' => 'P2', 'internal_code' => 'T02', 'cost_price' => 10, 'selling_price' => 20]);

        $product1->delete(); // Soft delete

        $response = $this->getJson('/api/trash/products');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product1->id);
    }

    public function test_tr02_restore_product()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'T03', 'cost_price' => 10, 'selling_price' => 20]);
        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $response = $this->postJson("/api/trash/products/{$product->id}/restore");
        $response->assertStatus(200);

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_tr03_force_delete_product()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'T04', 'cost_price' => 10, 'selling_price' => 20]);
        $product->delete();

        $response = $this->deleteJson("/api/trash/products/{$product->id}/force");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_tr04_restore_customer()
    {
        $customer = Customer::create(['name' => 'C1', 'document_number' => '99912']);
        $customer->delete();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        $response = $this->postJson("/api/trash/customers/{$customer->id}/restore");
        $response->assertStatus(200);

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_tr05_restore_customer_allows_multiple_null_documents_but_blocks_active_duplicate_dni()
    {
        Customer::create(['name' => 'Activo Sin Doc', 'document_number' => null]);

        $trashedSinDoc = Customer::create(['name' => 'Borrado Sin Doc', 'document_number' => null]);
        $trashedSinDoc->delete();

        $this->postJson("/api/trash/customers/{$trashedSinDoc->id}/restore")
            ->assertStatus(200);
        $this->assertNotSoftDeleted('customers', ['id' => $trashedSinDoc->id]);

        $activeWithDni = Customer::create(['name' => 'Activo Con DNI', 'document_number' => '38452190']);
        $trashedSameDni = Customer::create(['name' => 'Duplicado Borrado', 'document_number' => '38452190']);
        $trashedSameDni->delete();

        $this->postJson("/api/trash/customers/{$trashedSameDni->id}/restore")
            ->assertStatus(422);
        $this->assertSoftDeleted('customers', ['id' => $trashedSameDni->id]);
        $this->assertNotSoftDeleted('customers', ['id' => $activeWithDni->id]);
    }
}
