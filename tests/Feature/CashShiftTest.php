<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CashShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CashShiftTest
 *
 * Cubre los casos CRÍTICOS del ciclo de vida de turnos:
 *  T-01  Abrir turno correctamente.
 *  T-02  Plan Básico: no se pueden abrir 2 turnos simultáneos.
 *  T-03  Cierre: expected_balance = fondo + ventas_efectivo (sin tarjeta).
 *  T-04  difference = actual_balance - expected_balance.
 *  T-05  Ventas en tarjeta/transferencia NO entran en expected_balance.
 *  T-06  Turno ya cerrado no puede cerrarse de nuevo.
 *  T-07  GET /shifts/current devuelve 404 si no hay turno abierto.
 */
class CashShiftTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Configura plan básico en business_settings.
     */
    private function setPlanBasico(): void
    {
        BusinessSetting::create(['key' => 'app_plan', 'value' => 'basic']);
    }

    /**
     * Crea ventas completadas en el turno con método de pago dado.
     */
    private function crearVentaEnTurno(CashShift $shift, PaymentMethod $metodo, float $monto): void
    {
        $user = User::factory()->create(['role' => 'cashier']);

        $sale = Sale::create([
            'total'           => $monto,
            'total_surcharge' => 0,
            'payment_status'  => 'paid',
            'amount_due'      => 0,
            'status'          => 'completed',
            'cash_shift_id'   => $shift->id,
            'user_id'         => $user->id,
        ]);

        SalePayment::create([
            'sale_id'           => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount'       => $monto,
            'surcharge_amount'  => 0,
            'total_amount'      => $monto,
        ]);
    }

    // ── T-01: Abrir turno ─────────────────────────────────────────────────────

    public function test_T01_abrir_turno_correctamente(): void
    {
        $this->setPlanBasico();
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAsAdmin($user)->postJson('/api/shifts/open', [
            'user_id'          => $user->id,
            'opening_balance'  => 1500.00,
            'cash_register_id' => null,
        ])->assertStatus(200)
          ->assertJsonPath('shift.status', 'open')
          ->assertJsonPath('shift.opening_balance', '1500.00');

        $this->assertDatabaseHas('cash_shifts', [
            'status'          => 'open',
            'opening_balance' => 1500.00,
        ]);
    }

    // ── T-02: Plan Básico no permite 2 turnos ─────────────────────────────────

    public function test_T02_plan_basico_no_permite_dos_turnos_simultaneos(): void
    {
        $this->setPlanBasico();
        $user = User::factory()->create(['role' => 'admin']);

        // Primer turno ya abierto
        $this->crearTurnoAbierto(user: $user);

        // Intentar abrir un segundo turno
        $response = $this->actingAsAdmin($user)->postJson('/api/shifts/open', [
            'user_id'         => $user->id,
            'opening_balance' => 500.00,
        ]);

        $response->assertStatus(403)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'turno') || str_contains($msg, 'abierto')
                 );

        // Solo debe existir 1 turno abierto en total
        $this->assertEquals(1, CashShift::where('status', 'open')->count());
    }

    // ── T-03 + T-05: expected_balance solo suma efectivo, NO tarjeta ──────────

    public function test_T03_T05_expected_balance_solo_incluye_pagos_en_efectivo(): void
    {
        $this->setPlanBasico();
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(fondoInicial: 1000.00, user: $user);
        $cash  = $this->crearMetodoEfectivo();
        $card  = $this->crearMetodoTarjeta();

        // Venta en efectivo: $500
        $this->crearVentaEnTurno($shift, $cash, 500.00);

        // Venta en tarjeta: $200 → NO debe sumar al expected_balance
        $this->crearVentaEnTurno($shift, $card, 200.00);

        $service = app(CashShiftService::class);
        $result  = $service->closeShift($shift->id, 1500.00, $user->id);

        // expected_balance = fondo (1000) + efectivo (500) = 1500
        $this->assertEquals('1500.00', $result->expected_balance,
            'expected_balance debe ser fondo + ventas_efectivo, sin incluir tarjeta');

        // difference = actual (1500) - expected (1500) = 0
        $this->assertEquals('0.00', $result->difference,
            'No debe haber diferencia cuando lo contado coincide con lo esperado');
    }

    // ── T-04: difference correcta cuando hay sobrante ─────────────────────────

    public function test_T04_difference_correcta_cuando_hay_sobrante(): void
    {
        $this->setPlanBasico();
        $user  = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(fondoInicial: 500.00, user: $user);
        $cash  = $this->crearMetodoEfectivo();

        $this->crearVentaEnTurno($shift, $cash, 300.00);

        $service = app(CashShiftService::class);
        // El cajero contó $850 pero esperábamos 500 + 300 = $800
        $result = $service->closeShift($shift->id, 850.00, $user->id);

        $this->assertEquals('800.00', $result->expected_balance);
        $this->assertEquals('50.00',  $result->difference, 'Sobrante de $50');
    }

    // ── T-06: Turno ya cerrado no puede cerrarse de nuevo ─────────────────────

    public function test_T06_turno_ya_cerrado_lanza_excepcion(): void
    {
        $this->setPlanBasico();
        $user    = User::factory()->create(['role' => 'admin']);
        $register = CashRegister::firstOrCreate(
            ['id' => 1],
            ['name' => 'Caja Principal', 'is_active' => true]
        );

        $shiftCerrado = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id'          => $user->id,
            'opened_at'        => now()->subHour(),
            'closed_at'        => now(),
            'opening_balance'  => 0,
            'status'           => 'closed',
        ]);

        $this->expectException(\Exception::class);

        app(CashShiftService::class)->closeShift($shiftCerrado->id, 0.00, $user->id);
    }

    // ── T-07: GET /shifts/current → 404 cuando no hay turno abierto ──────────

    public function test_T07_current_retorna_404_sin_turno_abierto(): void
    {
        // No creamos ningún turno abierto
        $response = $this->getJson('/api/shifts/current');

        $response->assertStatus(404)
                 ->assertJsonPath('message', fn($msg) =>
                     str_contains($msg, 'caja') || str_contains($msg, 'abierta')
                 );
    }
}
