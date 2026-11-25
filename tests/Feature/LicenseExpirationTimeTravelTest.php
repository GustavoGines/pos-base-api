<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SystemController;
use App\Models\BusinessSetting;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\User;
use App\Services\LicenseSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class LicenseExpirationTimeTravelTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    private function createSalePayload(Product $product, CashShift $shift, int $paymentMethodId): array
    {
        return [
            'cash_shift_id' => $shift->id,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 250,
                    'subtotal' => 250,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $paymentMethodId,
                    'base_amount' => 250,
                    'surcharge_amount' => 0,
                    'total_amount' => 250,
                ],
            ],
            'total' => 250,
        ];
    }

    public function test_demo_cuts_off_at_midnight_even_when_offline_and_allows_shift_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00', 'America/Argentina/Buenos_Aires'));

        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $shift = $this->crearTurnoAbierto(1000.00, $admin);
        $paymentMethod = $this->crearMetodoEfectivo();
        $product = Product::create([
            'name' => 'Producto Demo Corte',
            'internal_code' => 'DEMO-001',
            'cost_price' => 100,
            'selling_price' => 250,
            'stock' => 20,
            'min_stock' => 2,
            'is_active' => true,
        ]);

        $service = app(LicenseSyncService::class);
        $this->fakeSignedLicenseServer(
            [
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'saas',
                'is_trial' => true,
                'business_type' => 'retail',
                'server_time' => '2026-11-30T23:50:00-03:00',
                'expires_at' => '2026-11-30T23:59:59-03:00',
                'features' => ['fast_pos' => true, 'z_reports' => true],
            ],
            Http::response('', 500), // Simula desconexión de internet al vencer
        );

        Carbon::setTestNow(Carbon::parse('2026-11-30 23:50:00', 'America/Argentina/Buenos_Aires'));
        $service->activateManual('DEMO-KEY-12345');

        // 23:59:58 -> todavía vigente, vende normalmente
        Carbon::setTestNow(Carbon::parse('2026-11-30 23:59:58', 'America/Argentina/Buenos_Aires'));
        $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $this->createSalePayload($product, $shift, $paymentMethod->id))
            ->assertStatus(201);

        // 00:00:01 del 01/12 -> aunque pasaron solo 10 min desde last_license_check y está offline, CORTA
        Carbon::setTestNow(Carbon::parse('2026-12-01 00:00:01', 'America/Argentina/Buenos_Aires'));
        $blockedRes = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $this->createSalePayload($product, $shift, $paymentMethod->id));

        $blockedRes->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_BLOCKED')
            ->assertJsonPath('license_block_reason', LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED);

        $this->assertSame(
            LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED,
            BusinessSetting::where('key', 'license_block_reason')->value('value')
        );

        // Cierre de turno Z sigue permitido
        $this->actingAsAdmin($admin)
            ->postJson("/api/shifts/{$shift->id}/close", [
                'actual_balance' => 1250,
                'pin' => '1234',
            ])
            ->assertStatus(200);
    }

    public function test_clock_rollback_offline_is_detected_by_high_water_mark_and_sales_and_recovers_after_sync(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00', 'America/Argentina/Buenos_Aires'));

        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(500.00, $admin);
        $paymentMethod = $this->crearMetodoEfectivo();
        $product = Product::create([
            'name' => 'Producto Rollback',
            'internal_code' => 'RB-001',
            'cost_price' => 100,
            'selling_price' => 250,
            'stock' => 20,
            'min_stock' => 2,
            'is_active' => true,
        ]);

        $service = app(LicenseSyncService::class);
        $this->fakeSignedLicenseServer(
            [
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'saas',
                'is_trial' => true,
                'business_type' => 'retail',
                'server_time' => '2026-11-25T10:00:00-03:00',
                'expires_at' => '2026-11-30T23:59:59-03:00',
                'features' => ['fast_pos' => true],
            ],
            Http::response('', 500), // Caída de red para simular modo offline
        );

        $service->activateManual('DEMO-ANTI-ROLLBACK');

        // Día 3 (2026-11-27 15:00): venta normal -> avanza license_max_observed_at
        Carbon::setTestNow(Carbon::parse('2026-11-27 15:00:00', 'America/Argentina/Buenos_Aires'));
        $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $this->createSalePayload($product, $shift, $paymentMethod->id))
            ->assertStatus(201);

        $hwmDay3 = BusinessSetting::where('key', 'license_max_observed_at')->value('value');
        $this->assertNotEmpty($hwmDay3);

        // Usuario atrasa reloj de Windows al Día 1 (2026-11-25 11:00) estando offline
        Carbon::setTestNow(Carbon::parse('2026-11-25 11:00:00', 'America/Argentina/Buenos_Aires'));
        $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $this->createSalePayload($product, $shift, $paymentMethod->id))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_CLOCK_TAMPERED')
            ->assertJsonPath('license_block_reason', LicenseSyncService::BLOCK_REASON_CLOCK_TAMPERED);

        // Ajuste #1: Recuperación limpia si el reloj real estaba corregido tras un adelanto accidental (BIOS 2027 -> 2026)
        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'is_trial' => false,
            'business_type' => 'retail',
            'server_time' => '2026-11-25T11:00:00-03:00',
            'expires_at' => '2026-12-25T23:59:59-03:00',
            'features' => ['fast_pos' => true],
        ]);

        // Sincronizar con el servidor re-ancla license_max_observed_at, anchor_sale_id y limpia license_block_reason
        $this->postJson('/api/settings/license/sync')->assertStatus(200);
        $this->assertNull(BusinessSetting::where('key', 'license_block_reason')->value('value'));

        // Ahora vuelve a vender sin deadlock aunque la venta vieja tenga timestamp de 2026-11-27
        $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $this->createSalePayload($product, $shift, $paymentMethod->id))
            ->assertStatus(201);
    }

    public function test_hwm_write_is_throttled_and_demo_conversion_unlocks_immediately_preserving_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00', 'America/Argentina/Buenos_Aires'));

        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Producto Conservado',
            'internal_code' => 'KEEP-001',
            'cost_price' => 50,
            'selling_price' => 120,
            'stock' => 10,
            'min_stock' => 1,
            'is_active' => true,
        ]);

        $service = app(LicenseSyncService::class);
        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'is_trial' => true,
            'business_type' => 'retail',
            'server_time' => '2026-11-25T10:00:00-03:00',
            'expires_at' => '2026-11-30T23:59:59-03:00',
            'features' => ['fast_pos' => true],
        ]);
        $service->activateManual('DEMO-CONVERT-KEY');

        $initialHwm = BusinessSetting::where('key', 'license_max_observed_at')->value('value');

        // Request dentro de < 15 minutos no actualiza license_max_observed_at (throttle Ajuste #2)
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:05:00', 'America/Argentina/Buenos_Aires'));
        $this->actingAsAdmin($admin)->getJson('/api/sales')->assertStatus(200);
        $this->assertSame($initialHwm, BusinessSetting::where('key', 'license_max_observed_at')->value('value'));

        // Request después de >= 15 minutos sí actualiza license_max_observed_at
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:16:00', 'America/Argentina/Buenos_Aires'));
        $this->actingAsAdmin($admin)->getJson('/api/sales')->assertStatus(200);
        $this->assertNotSame($initialHwm, BusinessSetting::where('key', 'license_max_observed_at')->value('value'));

        // Simular expiración remota de la demo y conversión posterior a Lifetime
        $this->fakeSignedLicenseServer(
            Http::response([
                'status' => 'expired',
                'is_trial' => true,
                'expires_at' => '2026-11-30T23:59:59-03:00',
                'message' => 'Tu período de prueba gratuita ha finalizado.',
            ], 403),
            [
                'status' => 'active',
                'plan' => 'pro',
                'plan_type' => 'lifetime',
                'is_trial' => false,
                'business_type' => 'retail',
                'server_time' => '2026-12-01T10:00:00-03:00',
                'expires_at' => null,
                'features' => ['fast_pos' => true],
            ],
        );

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'America/Argentina/Buenos_Aires'));
        $this->postJson('/api/settings/license/sync')->assertStatus(400);
        $this->assertSame('blocked', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertSame(
            LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED,
            BusinessSetting::where('key', 'license_block_reason')->value('value')
        );

        // Usuario presiona "Verificar ahora" tras convertir Demo -> Lifetime en Filament
        $this->postJson('/api/settings/license/sync')->assertStatus(200);
        $this->assertSame('pro', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertSame('lifetime', BusinessSetting::where('key', 'license_plan_mode')->value('value'));
        $this->assertSame('0', BusinessSetting::where('key', 'license_is_trial')->value('value'));
        $this->assertNull(BusinessSetting::where('key', 'license_block_reason')->value('value'));
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_system_controller_protects_new_trial_and_hwm_keys_during_restore_guard(): void
    {
        $reflection = new \ReflectionMethod(SystemController::class, 'restoreBackup');
        $source = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString("'license_is_trial'", $source);
        $this->assertStringContainsString("'license_block_reason'", $source);
        $this->assertStringContainsString("'license_max_observed_at'", $source);
    }
}
