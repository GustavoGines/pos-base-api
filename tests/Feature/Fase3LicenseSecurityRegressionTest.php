<?php

namespace Tests\Feature;

use App\Exceptions\LicenseSignatureException;
use App\Models\BusinessSetting;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\User;
use App\Services\LicenseSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class Fase3LicenseSecurityRegressionTest extends TestCase
{
    use FakesSignedLicenseServer;
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

        $this->fakeSignedLicenseServer([
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
     * L-01 & L-17 / FINDING-LIC-01: la firma Ed25519 es obligatoria. Sin LICENSE_SIGNING_SECRET ni
     * ninguna otra configuración, LicenseSyncService rechaza respuestas sin firma (spoofing por
     * archivo hosts), firmadas con una clave ajena o con nonce reutilizado.
     */
    public function test_l01_l17_lic01_rejects_unsigned_forged_or_replayed_server_response(): void
    {
        $this->useTestLicenseKeyPair();
        $service = app(LicenseSyncService::class);

        $spoofedBody = [
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'lifetime',
            'business_type' => 'retail',
            'features' => ['multi_caja' => true],
        ];

        // 1. Servidor falso en 127.0.0.1 sin firma Ed25519 (solo un campo HMAC legado inventado).
        $this->setLicenseServerHandler(fn ($request) => Http::response(
            $spoofedBody + ['nonce' => $request['nonce'], 'signature' => str_repeat('a', 64)],
            200
        ));
        $this->assertLicenseRejected(fn () => $service->activateManual('SPOOFED-KEY'), '/firma criptográfica ausente/');

        // 2. Atacante firma con su propio par Ed25519: el cliente solo confía en la clave pública embebida.
        $attackerKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->setLicenseServerHandler(function ($request) use ($spoofedBody, $attackerKey) {
            $body = $spoofedBody + ['nonce' => $request['nonce']];
            $body['signature_ed25519'] = bin2hex(sodium_crypto_sign_detached(
                LicenseSyncService::buildCanonicalPayload($body, $request['installation_id']),
                $attackerKey
            ));

            return Http::response($body, 200);
        });
        $this->assertLicenseRejected(fn () => $service->activateManual('SPOOFED-KEY'), '/firma criptográfica inválida/');

        // 3. Replay: respuesta legítima capturada, pero con el nonce de otro desafío.
        $this->setLicenseServerHandler(fn ($request) => Http::response(
            $this->signLicensePayload($spoofedBody, $request['installation_id'], 'nonce-capturado-antes'),
            200
        ));
        $this->assertLicenseRejected(fn () => $service->activateManual('SPOOFED-KEY'), '/nonce de desafío no coincide/');

        // 4. Payload legítimo alterado tras la firma (saas -> lifetime).
        $this->setLicenseServerHandler(function ($request) use ($spoofedBody) {
            $signed = $this->signLicensePayload(
                ['plan_type' => 'saas'] + $spoofedBody,
                $request['installation_id'],
                $request['nonce']
            );
            $signed['plan_type'] = 'lifetime';

            return Http::response($signed, 200);
        });
        $this->assertLicenseRejected(fn () => $service->activateManual('SPOOFED-KEY'), '/firma criptográfica inválida/');

        $this->assertNotEquals('pro', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertNotEquals('lifetime', BusinessSetting::where('key', 'license_plan_mode')->value('value'));

        // Control positivo: la misma respuesta firmada por el servidor legítimo sí se acepta.
        $this->fakeSignedLicenseServer(['plan_type' => 'saas'] + $spoofedBody);
        $this->assertEquals('pro', $service->activateManual('LEGIT-KEY'));
        $this->assertEquals('saas', BusinessSetting::where('key', 'license_plan_mode')->value('value'));
    }

    /**
     * FINDING-LIC-01: el heartbeat nunca aplica una respuesta sin firma válida; la trata como
     * falla de red (grace period de 72 h), por lo que el spoofing no otorga nada extra.
     */
    public function test_lic01_heartbeat_ignores_unsigned_response_and_falls_back_to_grace_period(): void
    {
        $service = app(LicenseSyncService::class);
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'SAAS-BASIC-KEY']);

        $unsignedSpoof = fn ($request) => Http::response([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'lifetime',
            'business_type' => 'retail',
            'features' => ['multi_caja' => true],
            'nonce' => $request['nonce'],
        ], 200);

        // Sin sincronización previa (sin last_license_check): grace period no disponible -> bloqueado.
        $this->setLicenseServerHandler($unsignedSpoof);
        $service->syncHeartbeat();

        $this->assertEquals('blocked', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertNotEquals('lifetime', BusinessSetting::where('key', 'license_plan_mode')->value('value'));

        // Con una sincronización legítima reciente, el spoofing solo conserva el estado previo (basic/saas).
        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'basic',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'server_time' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
            'features' => ['multi_caja' => false],
        ]);
        $service->syncHeartbeat();
        $this->assertEquals('basic', BusinessSetting::where('key', 'app_plan')->value('value'));

        $this->setLicenseServerHandler($unsignedSpoof);
        $service->syncHeartbeat();

        $this->assertEquals('basic', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertEquals('saas', BusinessSetting::where('key', 'license_plan_mode')->value('value'));
        $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());
    }

    private function assertLicenseRejected(callable $callback, string $expectedMessagePattern): void
    {
        try {
            $callback();
            $this->fail('La respuesta no firmada/falsificada debió ser rechazada.');
        } catch (LicenseSignatureException $e) {
            $this->assertMatchesRegularExpression($expectedMessagePattern, $e->getMessage());
        }
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

        $this->fakeSignedLicenseServer(
            [
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'saas',
                'business_type' => 'retail',
                'server_time' => $remoteTime,
                'expires_at' => $expiredDate,
                'features' => ['multi_caja' => true],
            ],
            Http::response('', 500), // Segunda llamada falla simulando corte/caída
        );

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

        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'features' => [],
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
     * - Soporta respuestas del servidor de Render sin expires_at (desde FINDING-LIC-01 deben venir firmadas con Ed25519).
     * - Cuando Render devuelve 403 con status='expired', marca license_expires_at vencido y bloquea el plan.
     */
    public function test_bloque5_lic01_to_lic08_legacy_v185_keys_render_main_compat_and_pro_features(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(LicenseSyncService::class);

        // 1. Simular instalación v1.8.5 que solo tiene license_api_key en BD
        BusinessSetting::updateOrCreate(['key' => 'license_api_key'], ['value' => 'LEGACY-V185-PRO-KEY']);

        // Respuesta equivalente al servidor de Render (sin expires_at), firmada con Ed25519 (FINDING-LIC-01)
        $this->fakeSignedLicenseServer(
            [
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
            ],
            Http::response([
                'status' => 'expired',
                'message' => 'La licencia ha expirado.',
            ], 403),
        );

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
