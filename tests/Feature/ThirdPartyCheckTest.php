<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\ThirdPartyCheck;
use App\Models\Customer;
use App\Models\User;
use App\Models\BusinessSetting;

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
            'status' => 'in_wallet'
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
            'status' => 'deposited'
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
            'status' => 'in_wallet'
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'endorsed',
            'endorsement_note' => 'Endosado a proveedor X'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('check.status', 'endorsed')
                 ->assertJsonPath('check.endorsement_note', 'Endosado a proveedor X');

        $this->assertDatabaseHas('third_party_checks', [
            'id' => $check->id,
            'status' => 'endorsed',
            'endorsement_note' => 'Endosado a proveedor X'
        ]);
    }
}
