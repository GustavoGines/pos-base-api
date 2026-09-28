<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashMovementSupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $shift;
    protected $supplier;
    protected $check;

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
            ['value' => json_encode(['checks' => true, 'expenses' => true])]
        );

        $this->shift = $this->crearTurnoAbierto(1000.00, $this->admin);
        $this->supplier = Supplier::create([
            'name' => 'Distribuidora Mayorista SA',
            'balance' => 100000.00,
        ]);

        $customer = Customer::create(['name' => 'Cliente Test', 'document_number' => '20123456789']);
        $this->check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Banco Galicia',
            'check_number' => 'CHK-9901',
            'amount' => 50000.00,
            'issue_date' => now(),
            'payment_date' => now(),
            'issuer_name' => 'Empresa Cliente',
            'issuer_cuit' => '30712345678',
            'status' => 'in_wallet',
        ]);
    }

    public function test_v01_rejects_duplicate_check_id_in_payload(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.check_id', 'payments.1.check_id']);
    }

    public function test_v01_allows_multiple_cash_payments_with_null_check_id(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 5000.00, 'payment_method' => 'cash'],
                ['amount' => 3000.00, 'payment_method' => 'cash'],
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $this->supplier->refresh();
        $this->assertEquals(42000.00, (float) $this->supplier->balance);
    }

    public function test_v02_rejects_mismatched_check_face_value(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 12000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.amount']);
    }

    public function test_v02_accepts_check_with_exact_face_value(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $this->check->refresh();
        $this->assertEquals('endorsed', $this->check->status);
    }

    public function test_v08_v10_endorses_check_with_supplier_and_note(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'receipt_number' => 'REC-00123',
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
                ['amount' => 10000.00, 'payment_method' => 'cash'],
            ],
        ]);

        $response->assertStatus(201);

        $this->check->refresh();
        $this->assertEquals('endorsed', $this->check->status);
        $this->assertEquals($this->supplier->id, $this->check->supplier_id);
        $this->assertStringContainsString('REC-00123', $this->check->endorsement_note);

        $this->supplier->refresh();
        $this->assertEquals(40000.00, (float) $this->supplier->balance);
    }

    public function test_v09_generates_batch_uuid_in_response(): void
    {
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(201);
        $batchUuid = $response->json('batch_uuid');
        $this->assertNotNull($batchUuid);
        $this->assertTrue(Str::isUuid($batchUuid));
    }

    public function test_v10_rejects_check_if_not_in_wallet(): void
    {
        // Alterar estado del cheque a 'endorsed' previo al movimiento
        $this->check->update(['status' => 'endorsed']);

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        // Debe ser rechazado por la regla de validación de check_id
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.check_id']);

        // El balance del proveedor no debe haber cambiado
        $this->supplier->refresh();
        $this->assertEquals(100000.00, (float) $this->supplier->balance);
    }

    public function test_v10_transaction_rolls_back_if_check_becomes_unavailable(): void
    {
        // Simular condición de carrera donde otro proceso endosó el cheque
        // justo después de pasar la validación inicial
        \App\Models\CashMovement::saving(function ($movement) {
            if ($movement->payment_method === 'check') {
                $this->check->update(['status' => 'endorsed']);
            }
        });

        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Error crítico al procesar el movimiento.',
                'error' => "El cheque #{$this->check->id} ya no se encuentra disponible en cartera.",
            ]);

        // Asegurar que no se persistió ningún movimiento (rollback atómico)
        $this->assertDatabaseCount('cash_movements', 0);
        // Asegurar que el saldo del proveedor no sufrió modificaciones
        $this->supplier->refresh();
        $this->assertEquals(100000.00, (float) $this->supplier->balance);
    }

    public function test_v11_destroy_reverts_check_status_and_clears_supplier(): void
    {
        // 1. Crear pago a proveedor
        $response = $this->postJson('/api/cash-movements', [
            'type' => 'supplier_payment',
            'supplier_id' => $this->supplier->id,
            'receipt_number' => 'REC-00124',
            'payments' => [
                ['amount' => 50000.00, 'payment_method' => 'check', 'check_id' => $this->check->id],
            ],
        ]);
        $response->assertStatus(201);
        $movementId = $response->json('movements.0.id');

        // 2. Anular movimiento
        $this->actingAsAdmin($this->admin);
        $delResponse = $this->deleteJson("/api/cash-movements/{$movementId}");
        $delResponse->assertStatus(200);

        // 3. Verificar estado revertido de forma simétrica
        $this->check->refresh();
        $this->assertEquals('in_wallet', $this->check->status);
        $this->assertNull($this->check->supplier_id);
        $this->assertNull($this->check->endorsement_note);

        // 4. Verificar balance de proveedor restaurado
        $this->supplier->refresh();
        $this->assertEquals(100000.00, (float) $this->supplier->balance);
    }
}
