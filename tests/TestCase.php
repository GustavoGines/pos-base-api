<?php

namespace Tests;

use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    /**
     * Retorna el usuario admin con un token válido.
     * Usar como: $this->withAdminToken($user)->postJson(...)
     */
    protected function actingAsAdmin(?User $user = null): static
    {
        $token = 'test-admin-token-' . uniqid();

        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'admin',
                'pin'  => Hash::make('1234'),
            ]);
        }

        // Escribir el token directamente en la BD para que el middleware lo encuentre
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->update(['session_token' => $token]);

        // withHeaders retorna $this configurado para el próximo request
        $this->withHeader('X-Session-Token', $token);

        return $this;
    }

    /**
     * Crea la Caja Principal (id=1) y un turno abierto.
     * Requerido por processSale() y closeShift().
     */
    protected function crearTurnoAbierto(float $fondoInicial = 1000.00, ?User $user = null): CashShift
    {
        $user = $user ?? User::factory()->create(['role' => 'admin']);

        $register = CashRegister::firstOrCreate(
            ['id' => 1],
            ['name' => 'Caja Principal', 'is_active' => true]
        );

        return CashShift::create([
            'cash_register_id' => $register->id,
            'user_id'          => $user->id,
            'opened_at'        => now(),
            'opening_balance'  => $fondoInicial,
            'status'           => 'open',
        ]);
    }

    /**
     * Crea el método de pago en efectivo (is_cash=true, code='cash').
     */
    protected function crearMetodoEfectivo(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Efectivo', 'is_cash' => true, 'surcharge_type' => 'none', 'surcharge_value' => 0]
        );
    }

    /**
     * Crea el método de pago Cuenta Corriente.
     */
    protected function crearMetodoCuentaCorriente(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'cuenta_corriente'],
            ['name' => 'Cuenta Corriente', 'is_cash' => false, 'surcharge_type' => 'none', 'surcharge_value' => 0]
        );
    }

    /**
     * Crea el método de pago Tarjeta (is_cash=false).
     */
    protected function crearMetodoTarjeta(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'card_credit'],
            ['name' => 'Tarjeta Crédito', 'is_cash' => false, 'surcharge_type' => 'none', 'surcharge_value' => 0]
        );
    }
}
