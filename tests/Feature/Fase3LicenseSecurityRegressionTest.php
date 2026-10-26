<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\User;
use App\Services\LicenseSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Fase3LicenseSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L-02: Cuando app_plan === 'blocked', el backend rechaza ventas y aperturas de turno con HTTP 403,
     * pero permite cerrar un turno abierto (/api/shifts/{id}/close) para que el comerciante no quede atrapado.
     */
    public function test_l02_blocked_license_blocks_pos_sales_and_shift_open_but_allows_shift_close(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $shift = $this->crearTurnoAbierto(1000.00, $admin);
        $paymentMethod = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Producto Test L-02',
            'internal_code' => '00101',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 50,
            'min_stock' => 5,
            'is_active' => true,
        ]);

        // Bloquear licencia en el backend
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'blocked']);

        // 1. Intentar procesar venta -> 403 LICENSE_BLOCKED
        $saleResponse = $this->actingAsAdmin($admin)->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 200,
                    'subtotal' => 200,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => 200,
                ],
            ],
            'total' => 200,
        ]);

        $saleResponse->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_BLOCKED');

        // 2. Intentar abrir un nuevo turno -> 403 LICENSE_BLOCKED
        $openResponse = $this->actingAsAdmin($admin)->postJson('/api/shifts/open', [
            'opening_balance' => 500,
        ]);

        $openResponse->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_BLOCKED');

        // 3. Cerrar el turno activo -> 200 OK (permitido en whitelist de emergencia)
        $closeResponse = $this->actingAsAdmin($admin)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_balance' => 1000,
            'pin' => '1234',
        ]);

        $closeResponse->assertStatus(200);
        $this->assertEquals('closed', CashShift::find($shift->id)->status);
    }

    /**
     * L-09: Si un usuario modifica manualmente en MySQL license_plan_mode = 'lifetime' o app_plan = 'pro'
     * sin pasar por la sincronización oficial, el sello HMAC de integridad falla y bloquea el acceso.
     */
    public function test_l09_sql_tampering_of_lifetime_or_plan_triggers_integrity_violation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(LicenseSyncService::class);

        Http::fake([
            '*/api/validate' => Http::response([
                'status' => 'active',
                'plan' => 'basic',
                'plan_type' => 'saas',
                'business_type' => 'retail',
                'server_time' => now()->toIso8601String(),
                'expires_at' => now()->addMonth()->toIso8601String(),
                'features' => [
                    'fast_pos' => true,
                    'multi_caja' => false,
                ],
            ], 200),
        ]);

        $service->activateManual('POS-VALID-SAAS-KEY');
        $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());

        // Ataque SQL directo: forzar lifetime en BD local para eludir expiración offline
        BusinessSetting::where('key', 'license_plan_mode')->update(['value' => 'lifetime']);

        $this->assertFalse(LicenseSyncService::verifyLocalIntegrity());

        // Cualquier llamada a rutas protegidas es rechazada con 403 LICENSE_INTEGRITY_VIOLATION
        $this->actingAsAdmin($admin)
            ->getJson('/api/sales')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_INTEGRITY_VIOLATION');
    }

    /**
     * L-01 & L-17: Cuando LICENSE_SIGNING_SECRET está configurado, LicenseSyncService rechaza
     * respuestas falsificadas (spoofing por archivo hosts) o con nonce alterado.
     */
    public function test_l01_l17_rejects_unsigned_or_forged_server_response_when_signing_secret_configured(): void
    {
        Config::set('app.license_signing_secret', 'shared-hmac-secret-production-2026');
        $service = app(LicenseSyncService::class);

        // Simulamos un servidor falso en 127.0.0.1 que devuelve plan=pro sin firma válida
        Http::fake([
            '*/api/validate' => Http::response([
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'lifetime',
                'business_type' => 'retail',
                'features' => ['multi_caja' => true],
                'signature' => 'forged-invalid-signature',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/firma criptográfica|nonce/i');

        $service->activateManual('SPOOFED-KEY');
    }

    /**
     * L-08 & L-18: LicenseSyncService persiste expires_at y utiliza server_time del servidor remoto
     * como referencia en last_license_check, bloqueando en offline si expires_at ya caducó.
     */
    public function test_l08_l18_persists_remote_server_time_and_blocks_offline_when_expires_at_passed(): void
    {
        $service = app(LicenseSyncService::class);
        $remoteTime = '2026-10-20T12:00:00+00:00';
        $expiredDate = now()->subDay()->toIso8601String();

        Http::fake([
            '*/api/validate' => Http::Sequence()
                ->push([
                    'status' => 'active',
                    'plan' => 'pro',
                    'plan_type' => 'saas',
                    'business_type' => 'retail',
                    'server_time' => $remoteTime,
                    'expires_at' => $expiredDate,
                    'features' => ['multi_caja' => true],
                ], 200)
                ->pushStatus(500), // Segunda llamada falla simulando corte/caída
        ]);

        $service->activateManual('SAAS-EXPIRING-KEY');

        $this->assertEquals(
            Carbon::parse($remoteTime)->toIso8601String(),
            BusinessSetting::where('key', 'last_license_check')->value('value')
        );
        $this->assertEquals(
            $expiredDate,
            BusinessSetting::where('key', 'license_expires_at')->value('value')
        );

        // Ahora el servidor da error 500 (entra a handleOfflineGracePeriod).
        // Aunque last_license_check sea reciente, como license_expires_at ya venció, debe bloquear.
        $service->syncHeartbeat();

        $this->assertEquals('blocked', BusinessSetting::where('key', 'app_plan')->value('value'));
    }

    /**
     * L-15: El token de sesión por query string (?token=) es rechazado en rutas de API estándar o mutaciones,
     * pero continúa permitido en GET de descarga de plantilla Excel (retrocompatibilidad con navegador).
     */
    public function test_l15_query_token_rejected_on_standard_endpoints_but_allowed_on_template_download(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'session_token' => 'secret-query-token-test-999',
        ]);

        // 1. Ruta normal con ?token= sin header -> 401 SESSION_MISSING
        $this->getJson('/api/sales?token=secret-query-token-test-999')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'SESSION_MISSING');

        // 2. Ruta de descarga de plantilla con ?token= -> 200 OK
        $this->get('/api/catalog/products/import-template?token=secret-query-token-test-999')
            ->assertStatus(200);
    }

    /**
     * FN-01: Consistencia de features entre syncHeartbeat, syncManualForce y activateManual
     * (sincroniza tanto 'checks' como 'cheques').
     */
    public function test_fn01_sync_methods_synchronize_both_checks_and_cheques_keys(): void
    {
        $service = app(LicenseSyncService::class);

        Http::fake([
            '*/api/validate' => Http::response([
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'saas',
                'business_type' => 'retail',
                'features' => [],
            ], 200),
        ]);

        $service->activateManual('PRO-CONSISTENCY-KEY');

        $features = json_decode(BusinessSetting::where('key', 'license_features_dict')->value('value'), true);
        $this->assertTrue($features['checks']);
        $this->assertTrue($features['cheques']);
        $this->assertTrue($features['multiple_prices']);
        $this->assertTrue($features['predictive_alerts']);
        $this->assertTrue($features['ecommerce_sync']);
        $this->assertTrue($features['quotes']);
        $this->assertFalse($features['logistics']);
    }

    /**
     * Bloque 5 (LIC-01, LIC-02, LIC-03, LIC-05, LIC-08):
     * - Soporta clientes v1.8.5 que tienen license_api_key y license_last_sync y esperan license_allowed_addons.
     * - Soporta respuestas del main de Render en producción (sin firma ni expires_at).
     * - Cuando Render devuelve 403 con status='expired', marca license_expires_at vencido y bloquea el plan.
     */
    public function test_bloque5_lic01_to_lic08_legacy_v185_keys_render_main_compat_and_pro_features(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(LicenseSyncService::class);

        // 1. Simular instalación v1.8.5 que solo tiene license_api_key en BD
        BusinessSetting::updateOrCreate(['key' => 'license_api_key'], ['value' => 'LEGACY-V185-PRO-KEY']);

        // Respuesta idéntica al main actual en producción de pos-license-server en Render (sin signature ni expires_at)
        Http::fake([
            '*/api/validate' => Http::Sequence()
                ->push([
                    'status' => 'active',
                    'plan' => 'pro',
                    'plan_type' => 'saas',
                    'server_time' => '2026-10-26T15:00:00+00:00',
                    'client_name' => 'Comercio v1.8.5',
                    'business_type' => 'retail',
                    'plan_espanol' => 'premium',
                    'features' => [
                        'fast_pos' => true,
                        'z_reports' => true,
                        'quotes' => true,
                        'current_accounts' => true,
                        'multiple_prices' => true,
                        'multi_caja' => true,
                        'advanced_reports' => true,
                        'predictive_alerts' => true,
                        'logistics' => false,
                        'checks' => true,
                        'mobile_app' => true,
                        'remote_access' => false,
                        'suppliers' => true,
                        'expenses' => true,
                        'multi_rubro' => true,
                        'mercadopago_qr' => true,
                        'arca_afip' => true,
                        'ecommerce_sync' => true,
                    ],
                ], 200)
                ->push([
                    'status' => 'expired',
                    'message' => 'La licencia ha expirado.',
                ], 403),
        ]);

        // LIC-01: syncHeartbeat encuentra license_api_key y la migra a license_key
        $service->syncHeartbeat();

        $this->assertEquals('LEGACY-V185-PRO-KEY', BusinessSetting::where('key', 'license_key')->value('value'));
        $this->assertEquals('pro', BusinessSetting::where('key', 'app_plan')->value('value'));

        // LIC-08: license_allowed_addons se mantiene poblado para clientes v1.8.5
        $allowedAddons = json_decode(BusinessSetting::where('key', 'license_allowed_addons')->value('value'), true);
        $this->assertIsArray($allowedAddons);
        $this->assertContains('ecommerce_sync', $allowedAddons);
        $this->assertContains('quotes', $allowedAddons);
        $this->assertNotContains('logistics', $allowedAddons);

        // LIC-01: SettingController::updateLicense acepta license_api_key de clientes v1.8.5
        // Segundo llamado en la secuencia devuelve 403 status=expired (LIC-03)
        try {
            $service->syncHeartbeat();
            $this->fail('Debió lanzar excepción al recibir 403 expired');
        } catch (\Exception $e) {
            $this->assertStringContainsString('suspendida', $e->getMessage());
        }

        $this->assertEquals('blocked', BusinessSetting::where('key', 'app_plan')->value('value'));
        $expiresAt = BusinessSetting::where('key', 'license_expires_at')->value('value');
        $this->assertNotEmpty($expiresAt);
        $this->assertTrue(Carbon::parse($expiresAt)->isPast());
    }
}
