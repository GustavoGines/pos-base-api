<?php

namespace Tests\Unit;

use App\Models\BusinessSetting;
use App\Services\LicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LicenseSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LicenseSyncService::class);
    }

    /**
     * Test: activateManual fuerza el fallback local para planes Pro y Premium habilitando multi_caja y advanced_reports.
     */
    public function test_activate_manual_enables_pro_and_premium_features_fallback(): void
    {
        Http::fake([
            '*/api/validate' => Http::response([
                'plan' => 'pro',
                'plan_type' => 'saas',
                'business_type' => 'retail',
                'features' => [], // Servidor devuelve lista vacía de features
                'expires_at' => now()->addYear()->toIso8601String(),
            ], 200),
        ]);

        $plan = $this->service->activateManual('LICENSE-KEY-PRO-001');

        $this->assertEquals('pro', $plan);
        $this->assertEquals('pro', BusinessSetting::where('key', 'app_plan')->value('value'));

        $featuresJson = BusinessSetting::where('key', 'license_features_dict')->value('value');
        $this->assertNotNull($featuresJson);

        $features = json_decode($featuresJson, true);
        $this->assertTrue($features['multi_caja'], 'MultiCaja debe habilitarse por fallback en plan Pro.');
        $this->assertTrue($features['advanced_reports'], 'Reportes avanzados deben habilitarse por fallback en plan Pro.');
    }

    /**
     * Test: activateManual para tipo de negocio hardware_store activa características especializadas.
     */
    public function test_activate_manual_hardware_store_enables_hardware_exclusive_features(): void
    {
        Http::fake([
            '*/api/validate' => Http::response([
                'plan' => 'premium',
                'plan_type' => 'lifetime',
                'business_type' => 'hardware_store',
                'features' => [],
                'expires_at' => null,
            ], 200),
        ]);

        $plan = $this->service->activateManual('LICENSE-KEY-HW-PREMIUM');

        $this->assertEquals('premium', $plan);

        $featuresJson = BusinessSetting::where('key', 'license_features_dict')->value('value');
        $features = json_decode($featuresJson, true);

        // Verificación de features exclusivas de ferretería / corralón
        $this->assertTrue($features['multi_caja']);
        $this->assertTrue($features['advanced_reports']);
        $this->assertTrue($features['multiple_prices'], 'Lista de precios múltiple debe estar habilitada.');
        $this->assertTrue($features['logistics'], 'Módulo logística (remitos) debe estar habilitado.');
        $this->assertTrue($features['cheques'], 'Módulo cartera de cheques debe estar habilitado.');
        $this->assertTrue($features['predictive_alerts'], 'Alertas predictivas deben estar habilitadas.');
    }

    /**
     * Test: activateManual ante error 500 escribe el payload en storage_path y no en ruta fija de Laragon.
     */
    public function test_activate_manual_server_error_writes_to_storage_and_not_laragon(): void
    {
        $laragonPath = 'C:\\laragon\\www\\error_body.html';
        $storageLogPath = storage_path('logs/license_sync_error.html');

        if (file_exists($laragonPath)) {
            @unlink($laragonPath);
        }
        if (file_exists($storageLogPath)) {
            @unlink($storageLogPath);
        }

        Http::fake([
            '*/api/validate' => Http::response('<h3>Internal 500 Diagnostics</h3>', 500),
        ]);

        try {
            $this->service->activateManual('TEST-ERROR-500-KEY');
            $this->fail('Se esperaba una Exception ante HTTP 500.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        // El archivo NO debe existir en la ruta obsoleta de Laragon
        $this->assertFileDoesNotExist($laragonPath);

        // El archivo DEBE existir en la ruta dinámica portable de Laravel
        $this->assertFileExists($storageLogPath);
        $this->assertStringContainsString('Internal 500 Diagnostics', file_get_contents($storageLogPath));

        @unlink($storageLogPath);
    }

    /**
     * Test: activateManual con 401/403/404 arroja excepción específica de clave inválida.
     */
    public function test_activate_manual_unauthorized_throws_exception(): void
    {
        Http::fake([
            '*/api/validate' => Http::response(['message' => 'Invalid key'], 403),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('La clave de licencia es inválida o está en uso en otra sucursal.');

        $this->service->activateManual('INVALID-BRANCH-KEY');
    }

    /**
     * Test: syncManualForce ante respuesta exitosa de retail limpia las llaves exclusivas de ferretería.
     */
    public function test_sync_manual_force_disables_hardware_features_when_retail(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'VALID-RETAIL-KEY']);

        Http::fake([
            '*/api/validate' => Http::response([
                'plan' => 'pro',
                'business_type' => 'retail',
                'features' => [
                    'quotes' => true, // Servidor envía llaves que deben desactivarse en retail
                    'logistics' => true,
                ],
            ], 200),
        ]);

        $this->service->syncManualForce();

        $featuresJson = BusinessSetting::where('key', 'license_features_dict')->value('value');
        $features = json_decode($featuresJson, true);

        // En retail, syncManualForce desactiva explícitamente quotes y logistics
        $this->assertFalse($features['quotes']);
        $this->assertFalse($features['logistics']);
    }

    /**
     * Test: syncHeartbeat ante licencia revocada (401) marca el app_plan como 'blocked'.
     */
    public function test_sync_heartbeat_unauthorized_sets_app_plan_to_blocked(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'REVOKED-KEY-XYZ']);

        Http::fake([
            '*/api/validate' => Http::response(['message' => 'License revoked'], 401),
        ]);

        try {
            $this->service->syncHeartbeat();
            $this->fail('Se esperaba una excepción ante licencia revocada.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('revocada', $e->getMessage());
        }

        $this->assertEquals('blocked', BusinessSetting::where('key', 'app_plan')->value('value'));
    }

    /**
     * Test: syncHeartbeat sin clave configurada establece el plan básico.
     */
    public function test_sync_heartbeat_without_license_key_defaults_to_basic(): void
    {
        BusinessSetting::where('key', 'license_key')->delete();

        $this->service->syncHeartbeat();

        $this->assertEquals('basic', BusinessSetting::where('key', 'app_plan')->value('value'));
    }
}
