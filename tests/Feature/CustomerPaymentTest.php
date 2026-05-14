<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CustomerPaymentTest
 *
 * Cubre los casos CRÍTICOS de Clientes y Cuenta Corriente:
 *  CC-01  Registrar abono reduce `balance` del cliente.
 *  CC-02  Abono mayor al saldo retorna 422.
 *  CC-03  Abono actualiza `amount_due` y `payment_status` de los tickets.
 *  CC-04  Abono en cheque crea ThirdPartyCheck.
 *  CC-05  No se puede eliminar cliente con `balance > 0`.
 *  CC-06  `document_number` único — no permite duplicados.
 *  CC-07  `getPendingSales` solo devuelve tickets con `amount_due > 0`.
 */
class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers locales ────────────────────────────────────────────────────────

    /**
     * Crea un cliente con deuda y tickets pendientes.
     * Retorna [Customer, Sale $ticket1, Sale $ticket2]
     */
    private function crearClienteConDeuda(float $deuda1 = 500.00, float $deuda2 = 300.00): array
    {
        $customer = Customer::create([
            'name'            => 'Cliente Test',
            'document_number' => '12345678',
            'balance'         => $deuda1 + $deuda2,
        ]);

        $shift = $this->crearTurnoAbierto();
        $cc    = $this->crearMetodoCuentaCorriente();

        $ticket1 = $this->crearTicketPendiente($customer, $shift, $cc, $deuda1);
        $ticket2 = $this->crearTicketPendiente($customer, $shift, $cc, $deuda2);

        return [$customer, $ticket1, $ticket2];
    }

    private function crearTicketPendiente(Customer $customer, $shift, PaymentMethod $metodo, float $monto): Sale
    {
        $user = User::factory()->create(['role' => 'cashier']);

        $sale = Sale::create([
            'total'           => $monto,
            'total_surcharge' => 0,
            'payment_status'  => 'pending',
            'amount_due'      => $monto,
            'status'          => 'completed',
            'cash_shift_id'   => $shift->id,
            'user_id'         => $user->id,
            'customer_id'     => $customer->id,
        ]);

        SalePayment::create([
            'sale_id'           => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount'       => $monto,
            'surcharge_amount'  => 0,
            'total_amount'      => $monto,
        ]);

        return $sale;
    }

    // ── CC-01: Abono reduce balance ────────────────────────────────────────────

    public function test_CC01_abono_reduce_balance_del_cliente(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$customer, $ticket1] = $this->crearClienteConDeuda(500.00, 300.00);

        $this->actingAsAdmin($admin);

        $response = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 200.00,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('message', fn($msg) => str_contains($msg, 'exitosamente') || str_contains($msg, 'registrado'));

        $this->assertEquals(600.00, (float) $customer->fresh()->balance,
            'El balance debe reducirse de 800 a 600 (pagó 200)');
    }

    // ── CC-02: Abono mayor al saldo → 422 ─────────────────────────────────────

    public function test_CC02_abono_mayor_al_saldo_retorna_422(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create([
            'name'            => 'Cliente Poco Saldo',
            'document_number' => '99887766',
            'balance'         => 100.00,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 500.00, // Mayor a 100 de deuda
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.amount.0', fn($msg) =>
                     str_contains($msg, 'saldo') || str_contains($msg, 'superar') || str_contains($msg, 'monto')
                 );

        // El balance no debe haber cambiado
        $this->assertEquals(100.00, (float) $customer->fresh()->balance,
            'El balance no debe cambiar cuando el abono es rechazado');
    }

    // ── CC-03: Abono actualiza amount_due y payment_status de tickets ─────────

    public function test_CC03_abono_actualiza_amount_due_y_payment_status_de_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$customer, $ticket1, $ticket2] = $this->crearClienteConDeuda(500.00, 300.00);

        $this->actingAsAdmin($admin);

        // Pagamos exactamente el monto del primer ticket ($500)
        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 500.00,
            'payment_method' => 'cash',
        ]);

        // Ticket 1 debe quedar pagado
        $this->assertEquals(0.00, (float) $ticket1->fresh()->amount_due,
            'El primer ticket debe quedar con amount_due = 0');
        $this->assertEquals('paid', $ticket1->fresh()->payment_status,
            'El primer ticket debe pasar a payment_status = paid');

        // Ticket 2 no debe tocar (todavía tiene deuda)
        $this->assertEquals(300.00, (float) $ticket2->fresh()->amount_due,
            'El segundo ticket no debe ser afectado por este abono');

        // Balance del cliente: 800 - 500 = 300
        $this->assertEquals(300.00, (float) $customer->fresh()->balance);
    }

    // ── CC-03b: Abono parcial → ticket queda en 'partial' ────────────────────

    public function test_CC03b_abono_parcial_deja_ticket_en_estado_partial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$customer, $ticket1] = $this->crearClienteConDeuda(500.00, 0.01);

        // Eliminar el ticket2 para limpiar el escenario
        Sale::find(2)?->delete();

        $customer->update(['balance' => 500.00]);

        $this->actingAsAdmin($admin);

        // Pagamos solo $200 de un ticket de $500
        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 200.00,
            'payment_method' => 'cash',
        ]);

        $ticket1->refresh();

        $this->assertEquals(300.00, (float) $ticket1->amount_due,
            'El ticket debe tener 300 de amount_due restante');
        $this->assertEquals('partial', $ticket1->payment_status,
            'El ticket debe quedar en estado partial');
    }

    // ── CC-04: Abono en cheque crea ThirdPartyCheck ───────────────────────────

    public function test_CC04_abono_en_cheque_crea_third_party_check(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create([
            'name'            => 'Cliente Cheque',
            'document_number' => '55443322',
            'balance'         => 1000.00,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 400.00,
            'payment_method' => 'cheque',
            'check_details'  => [
                'bank_name'    => 'Banco Nación',
                'check_number' => '00123456',
                'issuer_name'  => 'Juan Pérez',
                'issuer_cuit'  => '20123456789',
                'issue_date'   => now()->toDateString(),
                'payment_date' => now()->addDays(30)->toDateString(),
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('third_party_checks', [
            'customer_id'  => $customer->id,
            'amount'       => 400.00,
            'bank_name'    => 'Banco Nación',
            'check_number' => '00123456',
            'status'       => 'in_wallet',
        ]);
    }

    // ── CC-05: No se puede eliminar cliente con saldo pendiente ───────────────

    public function test_CC05_no_se_puede_eliminar_cliente_con_saldo_pendiente(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create([
            'name'            => 'Cliente Con Deuda',
            'document_number' => '11223344',
            'balance'         => 250.00,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(403)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'saldo') || str_contains($msg, 'pendiente') || str_contains($msg, 'eliminar')
                 );

        // El cliente sigue existiendo en la BD
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    // ── CC-05b: Sí se puede eliminar cliente sin deuda ────────────────────────

    public function test_CC05b_se_puede_eliminar_cliente_sin_deuda(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create([
            'name'            => 'Cliente Sin Deuda',
            'document_number' => '99112233',
            'balance'         => 0.00,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(204);

        // Soft-deleted: no debe aparecer en consultas normales
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    // ── CC-06: document_number único ──────────────────────────────────────────

    public function test_CC06_document_number_duplicado_retorna_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Customer::create([
            'name'            => 'Cliente Original',
            'document_number' => '11111111',
            'balance'         => 0,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->postJson('/api/customers', [
            'name'            => 'Cliente Duplicado',
            'document_number' => '11111111', // Mismo DNI
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.document_number.0', fn($msg) =>
                     str_contains($msg, 'existe') || str_contains($msg, 'documento') || str_contains($msg, 'unique')
                 );
    }

    // ── CC-07: getPendingSales solo devuelve tickets con amount_due > 0 ───────

    public function test_CC07_get_pending_sales_solo_devuelve_tickets_con_deuda(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$customer, $ticket1, $ticket2] = $this->crearClienteConDeuda(400.00, 300.00);

        // Pagar totalmente el ticket1
        $ticket1->update(['amount_due' => 0.00, 'payment_status' => 'paid']);

        $this->actingAsAdmin($admin);

        $response = $this->getJson("/api/customers/{$customer->id}/pending-sales");

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();

        $this->assertContains($ticket2->id, $ids,
            'El ticket2 con deuda debe aparecer en pending-sales');
        $this->assertNotContains($ticket1->id, $ids,
            'El ticket1 ya pagado NO debe aparecer en pending-sales');
    }

    // ── CC-03c: Abono global distribuye a múltiples tickets en orden ──────────

    public function test_CC03c_abono_global_distribuye_a_tickets_en_orden_cronologico(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$customer, $ticket1, $ticket2] = $this->crearClienteConDeuda(300.00, 400.00);

        $this->actingAsAdmin($admin);

        // Pagamos $700 (exactamente ambos tickets)
        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount'         => 700.00,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(0.00, (float) $customer->fresh()->balance,
            'El balance debe llegar a 0 al pagar ambos tickets');
        $this->assertEquals('paid', $ticket1->fresh()->payment_status);
        $this->assertEquals('paid', $ticket2->fresh()->payment_status);
    }
}
