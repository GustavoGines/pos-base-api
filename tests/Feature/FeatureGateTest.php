<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FeatureGateTest
 *
 * Cubre los casos CRÍTICOS de Licencias, Roles y Feature Gates:
 *  L-01  Admin puede acceder a rutas de administración (ej. /api/settings).
 *  L-02  Cashier es rebotado (403) de rutas de administración.
 *  L-03  CheckFeatureAccess rebota peticiones si el feature no está en la licencia.
 *  L-04  Plan Básico rebota rutas de multi_caja.
 *  L-05  Plan Pro permite rutas de multi_caja.
 */
class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    // ── L-01: Admin accede a admin ─────────────────────────────────────────────

    public function test_L01_admin_puede_acceder_a_rutas_de_administracion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // /api/users está protegida por role.admin
        $response = $this->actingAsAdmin($admin)->getJson('/api/users');

        $response->assertStatus(200);
    }

    // ── L-02: Cashier rebotado ────────────────────────────────────────────────

    public function test_L02_cashier_es_rebotado_de_rutas_de_administracion(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        // Intentar acceder a endpoint admin
        $response = $this->actingAsAdmin($cashier)->getJson('/api/users');

        $response->assertStatus(403)
                 ->assertJsonPath('message', fn($msg) => str_contains(strtolower($msg), 'permiso'));
    }

    // ── L-03: FeatureGate sin licencia ────────────────────────────────────────

    public function test_L03_middleware_feature_rebota_peticion_sin_licencia(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        
        // Asegurar que el feature 'quotes' no está activo
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['quotes' => false])]
        );

        $response = $this->actingAsAdmin($admin)->getJson('/api/quotes');

        $response->assertStatus(403)
                 ->assertJsonPath('message', fn($msg) => str_contains(strtolower($msg), 'licencia'));
    }

    // ── L-04: Plan Básico y MultiCaja ─────────────────────────────────────────

    public function test_L04_plan_basico_rebota_rutas_multicaja(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // MultiCaja no habilitada
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_caja' => false])]
        );

        // Intentar crear caja adicional (Route: POST /api/registers protegida por feature:multi_caja y role.admin)
        $response = $this->actingAsAdmin($admin)->postJson('/api/registers', [
            'name' => 'Caja Nueva',
        ]);

        $response->assertStatus(403);
    }

    // ── L-05: Plan Pro y MultiCaja ────────────────────────────────────────────

    public function test_L05_plan_pro_permite_rutas_multicaja(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // MultiCaja habilitada
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_caja' => true])]
        );

        // POST a registers pasa el gate y llega a la validación (422) o creación (201)
        $response = $this->actingAsAdmin($admin)->postJson('/api/registers', [
            'name'        => 'Caja Nueva PRO',
            'terminal_id' => 'TERM_PRO_01',
        ]);

        $response->assertStatus(201);
    }
}
