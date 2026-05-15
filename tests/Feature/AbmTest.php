<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CashRegister;
use App\Models\PaymentMethod;

class AbmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        \App\Models\BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_caja' => true])]
        );
    }

    public function test_abm01_brand_crud()
    {
        // Create
        $createRes = $this->postJson('/api/catalog/brands', ['name' => 'Marca Nueva']);
        $createRes->assertStatus(201)->assertJsonPath('name', 'Marca Nueva');
        $id = $createRes->json('id');

        // Read
        $this->getJson('/api/catalog/brands')->assertStatus(200)->assertJsonFragment(['name' => 'Marca Nueva']);

        // Update
        $this->putJson("/api/catalog/brands/{$id}", ['name' => 'Marca Editada'])
             ->assertStatus(200)->assertJsonPath('name', 'Marca Editada');

        // Delete
        $this->deleteJson("/api/catalog/brands/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('brands', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_abm02_category_crud()
    {
        $createRes = $this->postJson('/api/catalog/categories', ['name' => 'Categoría Nueva']);
        $createRes->assertStatus(201);
        $id = $createRes->json('id');

        $this->putJson("/api/catalog/categories/{$id}", ['name' => 'Categoría Editada'])->assertStatus(200);
        $this->deleteJson("/api/catalog/categories/{$id}")->assertStatus(204);
    }

    public function test_abm03_cash_register_crud()
    {
        CashRegister::create(['id' => 1, 'name' => 'Caja Principal']);

        $createRes = $this->postJson('/api/registers', ['name' => 'Caja Nueva']);
        $createRes->assertStatus(201);
        $id = $createRes->json('id');

        $this->putJson("/api/registers/{$id}", ['name' => 'Caja Editada'])->assertStatus(200);
        
        $this->deleteJson("/api/registers/{$id}")->assertStatus(200);
    }

    public function test_abm04_user_crud()
    {
        $createRes = $this->postJson('/api/users', [
            'name' => 'Juan Perez',
            'email' => 'juan@test.com',
            'pin' => '9999',
            'role' => 'cashier',
            'password' => '12345678'
        ]);
        $createRes->assertStatus(201);
        $id = $createRes->json('id');

        $this->putJson("/api/users/{$id}", ['name' => 'Juan Editado', 'role' => 'admin'])->assertStatus(200);
        $this->deleteJson("/api/users/{$id}")->assertStatus(200);
    }

    public function test_abm05_payment_method_crud()
    {
        $createRes = $this->postJson('/api/payment-methods', [
            'name' => 'QR Pago',
            'code' => 'qr_pago',
            'is_cash' => false,
            'surcharge_type' => 'percent',
            'surcharge_value' => 5
        ]);
        $createRes->assertStatus(201);
        $id = $createRes->json('id');

        $this->putJson("/api/payment-methods/{$id}", ['name' => 'QR Pago Modificado'])->assertStatus(200);
        $this->deleteJson("/api/payment-methods/{$id}")->assertStatus(200);
    }
}
