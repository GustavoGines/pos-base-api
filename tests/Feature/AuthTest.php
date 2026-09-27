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

    public function test_a01_login_con_pin_valido_genera_session_token(): void
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

    public function test_a02_login_con_pin_invalido_retorna_401(): void
    {
        User::factory()->create([
            'pin' => Hash::make('1234'),
        ]);

        $response = $this->postJson('/api/auth/verify-pin', [
            'pin' => '9999',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', fn ($msg) => str_contains(strtolower($msg), 'incorrecto'));
    }

    // ── A-03: Single Active Session ───────────────────────────────────────────

    public function test_a03_single_active_session_nuevo_login_invalida_token_anterior(): void
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

    public function test_a04_usar_token_invalido_retorna_401_session_expired(): void
    {
        $response = $this->withHeader('X-Session-Token', 'token-inventado-que-no-existe')
            ->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('error_code', 'SESSION_EXPIRED');
    }

    public function test_a04b_usar_ruta_protegida_con_token_invalido_retorna_401(): void
    {
        // /api/shifts/open está protegida por session.validate
        $response = $this->withHeader('X-Session-Token', 'token-inexistente')
            ->postJson('/api/shifts/open', ['initial_amount' => 0]);

        $response->assertStatus(401);
    }

    // ── A-05: Protocolo de Rescate (Master PIN eliminado / bloqueado) ────────
    public function test_a05_legacy_backdoor_pin_retorna_401_unauthorized(): void
    {
        // Necesitamos al menos un admin en la BD
        User::factory()->create(['role' => 'admin', 'pin' => Hash::make('1234')]);

        $response = $this->postJson('/api/auth/verify-pin', [
            'pin' => '9999',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    // ── A-06: Authorize PIN con Admin exitoso ──────────────────────────────────
    public function test_a06_authorize_pin_con_admin_exitoso(): void
    {
        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('4321'),
        ]);

        $response = $this->postJson('/api/auth/authorize-pin', [
            'pin' => '4321',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'authorized' => true,
                'user' => [
                    'role' => 'admin',
                ],
            ]);
    }

    // ── A-08: Authorize PIN con PIN inexistente retorna 401 ───────────────────
    public function test_a08_authorize_pin_invalido_retorna_401(): void
    {
        $response = $this->postJson('/api/auth/authorize-pin', [
            'pin' => '0000',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'authorized' => false,
            ]);
    }

    // ── A-09: Endpoint /me con token válido retorna usuario ────────────────────
    public function test_a09_me_con_token_valido_retorna_usuario(): void
    {
        $user = User::factory()->create([
            'session_token' => 'valid-token-uuid-12345678',
        ]);

        $response = $this->withHeader('X-Session-Token', 'valid-token-uuid-12345678')
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    // ── A-10: Endpoint /me sin token retorna 401 ──────────────────────────────
    public function test_a10_me_sin_token_retorna_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('error_code', 'SESSION_MISSING');
    }

    // ── A-11: Logout invalida token en BD ─────────────────────────────────────
    public function test_a11_logout_invalida_session_token_en_bd(): void
    {
        $user = User::factory()->create([
            'session_token' => 'token-to-be-cleared',
        ]);

        $response = $this->withHeader('X-Session-Token', 'token-to-be-cleared')
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);
        $this->assertNull($user->fresh()->session_token);
    }

    // ── A-12: ValidateSessionToken puebla Auth facade nativo de Laravel ───────
    public function test_a12_validate_session_token_populates_laravel_auth_facade(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'session_token' => 'test-auth-sync-token-12345',
        ]);

        $response = $this->withHeader('X-Session-Token', 'test-auth-sync-token-12345')
            ->getJson('/api/sales');

        $response->assertStatus(200);
        $this->assertTrue(auth()->check(), 'Auth::check() debe retornar true tras validar session token');
        $this->assertEquals($user->id, auth()->id(), 'auth()->id() debe coincidir con el usuario del token');
        $this->assertEquals($user->id, auth()->user()->id, 'auth()->user() debe ser el usuario autenticado');
    }
}
