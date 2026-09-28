<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirdPartyCheckTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        // Enable checks module via business setting
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['checks' => true])]
        );
    }

    public function test_ch01_list_checks()
    {
        $customer = Customer::create(['name' => 'Test', 'document_number' => '1234']);
        ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank A',
            'check_number' => '111',
            'amount' => 100,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer A',
            'issuer_cuit' => '111111111',
            'status' => 'in_wallet',
        ]);
        ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank B',
            'check_number' => '222',
            'amount' => 200,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer B',
            'issuer_cuit' => '222222222',
            'status' => 'deposited',
        ]);

        $response = $this->getJson('/api/third-party-checks');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_ch02_update_check_status()
    {
        $customer = Customer::create(['name' => 'Test2', 'document_number' => '12345']);
        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank C',
            'check_number' => '333',
            'amount' => 300,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer C',
            'issuer_cuit' => '333333333',
            'status' => 'in_wallet',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'endorsed',
            'endorsement_note' => 'Endosado a proveedor X',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('check.status', 'endorsed')
            ->assertJsonPath('check.endorsement_note', 'Endosado a proveedor X');

        $this->assertDatabaseHas('third_party_checks', [
            'id' => $check->id,
            'status' => 'endorsed',
            'endorsement_note' => 'Endosado a proveedor X',
        ]);
    }

    public function test_ch03_cannot_update_voided_check()
    {
        $customer = Customer::create(['name' => 'Test3', 'document_number' => '123456']);
        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank D',
            'check_number' => '444',
            'amount' => 400,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer D',
            'issuer_cuit' => '444444444',
            'status' => 'voided',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'in_wallet',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'anulado'));
    }

    public function test_ch04_cannot_update_check_linked_to_supplier()
    {
        $supplier = \App\Models\Supplier::create(['name' => 'Proveedor Test', 'cuit' => '20123456789']);
        $customer = Customer::create(['name' => 'Test4', 'document_number' => '1234567']);
        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'supplier_id' => $supplier->id,
            'bank_name' => 'Bank E',
            'check_number' => '555',
            'amount' => 500,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer E',
            'issuer_cuit' => '555555555',
            'status' => 'endorsed',
            'endorsement_note' => 'Endosado en Movimiento #1',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'in_wallet',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'proveedor'));
    }

    public function test_ch05_returning_to_in_wallet_clears_endorsement_note()
    {
        $customer = Customer::create(['name' => 'Test5', 'document_number' => '12345678']);
        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank F',
            'check_number' => '666',
            'amount' => 600,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer F',
            'issuer_cuit' => '666666666',
            'status' => 'endorsed',
            'endorsement_note' => 'Nota manual',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'in_wallet',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('check.status', 'in_wallet')
            ->assertJsonPath('check.endorsement_note', null);

        $this->assertDatabaseHas('third_party_checks', [
            'id' => $check->id,
            'status' => 'in_wallet',
            'endorsement_note' => null,
        ]);
    }

    public function test_ch06_cannot_manually_set_voided_status()
    {
        $customer = Customer::create(['name' => 'Test6', 'document_number' => '123456789']);
        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank G',
            'check_number' => '777',
            'amount' => 700,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Issuer G',
            'issuer_cuit' => '777777777',
            'status' => 'in_wallet',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'voided',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
