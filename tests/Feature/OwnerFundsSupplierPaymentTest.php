<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerFundsSupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => bcrypt('1234'),
        ]);
        $this->actingAsAdmin($this->admin);

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['checks' => true, 'expenses' => true, 'suppliers' => true])]
        );

        $this->supplier = Supplier::create([
            'name' => 'Proveedor Mayorista Bebidas SA',
            'cuit' => '30711223344',
            'balance' => 80000.00,
        ]);
    }

    public function test_owner_funds_payment_with_open_shift_reduces_supplier_debt_without_affecting_expected_cash_balance(): void
    {
        $shift = $this->crearTurnoAbierto(10000.00, $this->admin);

        $response = $this->postJson('/api/cash-movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'description' => 'Pago en efectivo y tarjeta del bolsillo del dueño',
            'receipt_number' => 'REC-BOLSILLO-001',
            'payments' => [
                ['amount' => 20000.00, 'payment_method' => 'cash', 'funding_source' => 'owner_funds'],
                ['amount' => 10000.00, 'payment_method' => 'card', 'funding_source' => 'owner_funds'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('movements.0.payment_method', 'cash')
            ->assertJsonPath('movements.0.funding_source', 'owner_funds')
            ->assertJsonPath('movements.1.payment_method', 'card')
            ->assertJsonPath('movements.1.funding_source', 'owner_funds');

        // 1. La deuda del proveedor bajó de 80.000 a 50.000
        $this->supplier->refresh();
        $this->assertEquals(50000.00, (float) $this->supplier->balance);

        // 2. El arqueo ciego de la caja física sigue esperando exactamente los $10.000 iniciales
        $currentShiftResp = $this->getJson('/api/shifts/current');
        $currentShiftResp->assertStatus(200);
        $this->assertEquals(10000.00, (float) $currentShiftResp->json('expected_balance'));
        $this->assertEquals(0.00, (float) $currentShiftResp->json('total_supplier_payments'));
        $this->assertEquals(30000.00, (float) $currentShiftResp->json('owner_funds_supplier_payments'));
    }

    public function test_owner_funds_payment_succeeds_when_all_cash_registers_are_closed_and_preserves_v185_json_contract(): void
    {
        // Asegurar que NO existe ningún turno abierto
        CashShift::query()->update(['status' => 'closed']);
        $this->assertEquals(0, CashShift::where('status', 'open')->count());

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'description' => 'Pago desde casa con caja cerrada',
            'payments' => [
                ['amount' => 25000.00, 'payment_method' => 'transfer', 'funding_source' => 'owner_funds'],
            ],
        ]);

        $response->assertStatus(201);

        // En BD se guarda con cash_shift_id = NULL (Foreign Key limpia)
        $movement = CashMovement::first();
        $this->assertNotNull($movement);
        $this->assertNull($movement->getRawOriginal('cash_shift_id'));
        $this->assertEquals('transfer', $movement->payment_method);
        $this->assertEquals('owner_funds', $movement->funding_source);

        // En JSON serializa cash_shift_id = 0 (entero) para no romper int.parse() en terminales v1.8.5
        $listResp = $this->getJson('/api/cash-movements');
        $listResp->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.cash_shift_id', 0)
            ->assertJsonPath('data.0.payment_method', 'transfer')
            ->assertJsonPath('data.0.funding_source', 'owner_funds');

        // Cuenta corriente del proveedor incluye payment_method y funding_source = owner_funds
        $ccResp = $this->getJson("/api/suppliers/{$this->supplier->id}/current-account");
        $ccResp->assertStatus(200)
            ->assertJsonPath('history.0.payment_method', 'transfer')
            ->assertJsonPath('history.0.funding_source', 'owner_funds');

        // La deuda del proveedor bajó de 80.000 a 55.000
        $this->supplier->refresh();
        $this->assertEquals(55000.00, (float) $this->supplier->balance);
    }

    public function test_physical_cash_payment_still_blocked_when_all_cash_registers_are_closed(): void
    {
        CashShift::query()->update(['status' => 'closed']);

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 10000.00, 'payment_method' => 'cash', 'funding_source' => 'cash_register'],
            ],
        ]);

        $response->assertStatus(403);
        $this->supplier->refresh();
        $this->assertEquals(80000.00, (float) $this->supplier->balance);
    }

    public function test_mixed_payment_cash_and_owner_funds_only_deducts_cash_portion_from_drawer(): void
    {
        $shift = $this->crearTurnoAbierto(15000.00, $this->admin);

        $response = $this->postJson('/api/cash-movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 10000.00, 'payment_method' => 'cash', 'funding_source' => 'cash_register'],
                ['amount' => 25000.00, 'payment_method' => 'cash', 'funding_source' => 'owner_funds'],
                ['amount' => 15000.00, 'payment_method' => 'card', 'funding_source' => 'owner_funds'],
            ],
        ]);

        $response->assertStatus(201);

        // El proveedor recibió $50.000 en total (deuda restante: $30.000)
        $this->supplier->refresh();
        $this->assertEquals(30000.00, (float) $this->supplier->balance);

        // La caja física tenía $15.000 y solo salieron $10.000 físicos -> quedan $5.000 esperados
        $currentShiftResp = $this->getJson('/api/shifts/current');
        $currentShiftResp->assertStatus(200);
        $this->assertEquals(5000.00, (float) $currentShiftResp->json('expected_balance'));
        $this->assertEquals(10000.00, (float) $currentShiftResp->json('total_supplier_payments'));
        $this->assertEquals(40000.00, (float) $currentShiftResp->json('owner_funds_supplier_payments'));
    }

    public function test_voiding_owner_funds_movement_outside_of_shift_restores_supplier_debt(): void
    {
        CashShift::query()->update(['status' => 'closed']);

        $createResp = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 20000.00, 'payment_method' => 'cash', 'funding_source' => 'owner_funds'],
            ],
        ]);
        $createResp->assertStatus(201);
        $movId = $createResp->json('movements.0.id');

        $this->supplier->refresh();
        $this->assertEquals(60000.00, (float) $this->supplier->balance);

        // Anular el movimiento estando todas las cajas cerradas
        $delResp = $this->deleteJson("/api/cash-movements/{$movId}");
        $delResp->assertStatus(200);

        $this->supplier->refresh();
        $this->assertEquals(80000.00, (float) $this->supplier->balance);
    }

    public function test_multi_caja_allows_owner_funds_from_suppliers_without_specifying_register_and_filters_in_expense_report(): void
    {
        $reg1 = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $reg2 = CashRegister::create(['name' => 'Caja 2', 'is_active' => true]);

        CashShift::create(['cash_register_id' => $reg1->id, 'user_id' => $this->admin->id, 'opening_balance' => 1000, 'status' => 'open', 'opened_at' => now()]);
        CashShift::create(['cash_register_id' => $reg2->id, 'user_id' => $this->admin->id, 'opening_balance' => 1000, 'status' => 'open', 'opened_at' => now()]);

        $resp = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 15000.00, 'payment_method' => 'card', 'funding_source' => 'owner_funds'],
            ],
        ]);
        $resp->assertStatus(201);

        $today = now()->toDateString();
        $reportResp = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=owner_funds");
        $reportResp->assertStatus(200)
            ->assertJsonPath('total_expenses', 15000);
        $this->assertEquals('Fondos Propios / Bolsillo', $reportResp->json('by_category.0.movements.0.cash_register_name'));
    }
}
