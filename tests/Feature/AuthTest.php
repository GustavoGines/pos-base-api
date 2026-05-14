<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AuthTest
 *
 * Cubre los casos CRÍTICOS de Autenticación y Sesiones:
 *  A-01  Login con PIN válido genera session_token.
 *  A-02  Login con PIN inválido retorna 401.
 *  A-03  Single Active Session: un nuevo login invalida el token anterior.
 *  A-04  Usar token inválido o expirado retorna 401 SESSION_EXPIRED.
 *  A-05  Protocolo de Rescate (Master PIN) genera token forzado y flag de cambio.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── A-01: Login exitoso genera session_token ─────────────────────────────

    public function test_A01_login_con_pin_valido_genera_session_token(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'session_token' => null,
        ]);

        $response = $this->postJson('/api/auth/verify-pin', [
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['user', 'session_token', 'requires_pin_change']);

        $token = $response->json('session_token');
        $this->assertNotNull($token);

        // Verificamos que se haya guardado en la BD
        $this->assertEquals($token, $user->fresh()->session_token);
    }

    // ── A-02: Login fallido ──────────────────────────────────────────────────

    public function test_A02_login_con_pin_invalido_retorna_401(): void
    {
        User::factory()->create([
            'pin' => Hash::make('1234'),
        ]);

        $response = $this->postJson('/api/auth/verify-pin', [
            'pin' => '9999',
        ]);

        $response->assertStatus(401)
                 ->assertJsonPath('message', fn($msg) => str_contains(strtolower($msg), 'incorrecto'));
    }

    // ── A-03: Single Active Session ───────────────────────────────────────────

    public function test_A03_single_active_session_nuevo_login_invalida_token_anterior(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
        ]);

        // Login en Dispositivo A
        $responseA = $this->postJson('/api/auth/verify-pin', ['pin' => '1234']);
        $tokenA = $responseA->json('session_token');

        // Verificamos que el Token A funciona
        $this->withHeader('X-Session-Token', $tokenA)
             ->getJson('/api/auth/me')
             ->assertStatus(200);

        // Login en Dispositivo B (Mismo usuario)
        $responseB = $this->postJson('/api/auth/verify-pin', ['pin' => '1234']);
        $tokenB = $responseB->json('session_token');

        // Verificamos que el Token A ya NO funciona
        $this->withHeader('X-Session-Token', $tokenA)
             ->getJson('/api/auth/me')
             ->assertStatus(401)
             ->assertJsonPath('error_code', 'SESSION_EXPIRED');

        // Verificamos que el Token B SÍ funciona
        $this->withHeader('X-Session-Token', $tokenB)
             ->getJson('/api/auth/me')
             ->assertStatus(200);
    }

    // ── A-04: Token inválido / expirado ───────────────────────────────────────

    public function test_A04_usar_token_invalido_retorna_401_session_expired(): void
    {
        $response = $this->withHeader('X-Session-Token', 'token-inventado-que-no-existe')
                         ->getJson('/api/auth/me');

        $response->assertStatus(401)
                 ->assertJsonPath('error_code', 'SESSION_EXPIRED');
    }

    public function test_A04b_usar_ruta_protegida_con_token_invalido_retorna_401(): void
    {
        // /api/shifts/open está protegida por session.validate
        $response = $this->withHeader('X-Session-Token', 'token-inexistente')
                         ->postJson('/api/shifts/open', ['initial_amount' => 0]);

        $response->assertStatus(401);
    }

    // ── A-05: Protocolo de Rescate (Master PIN) ───────────────────────────────

    public function test_A05_protocolo_rescate_genera_token_y_flag(): void
    {
        // Necesitamos al menos un admin en la BD para que el rescate funcione
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('1234')]);

        // Mockeamos la fachada Hash para interceptar el Master PIN sin conocer la contraseña en texto plano
        Hash::shouldReceive('check')
            ->andReturnUsing(function ($value, $hashedValue) {
                if ($value === 'RESCUE_999' && $hashedValue === '$2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di') {
                    return true;
                }
                return password_verify($value, $hashedValue);
            });
            
        Hash::shouldReceive('make')
            ->andReturnUsing(function ($value) {
                return password_hash($value, PASSWORD_BCRYPT);
            });

        $response = $this->postJson('/api/auth/verify-pin', [
            'pin' => 'RESCUE_999',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('requires_pin_change', true); // Confirmamos el flag de rescate

        $token = $response->json('session_token');
        $this->assertNotNull($token);

        // Verificamos que el admin local fue forzado a iniciar sesión
        $this->assertEquals($token, $admin->fresh()->session_token);
    }
}
