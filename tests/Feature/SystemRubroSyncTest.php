<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Rubro;
use App\Services\LicenseSyncService;
use App\Services\SystemRubroSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemRubroSyncTest extends TestCase
{
    use RefreshDatabase;

    private SystemRubroSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = app(SystemRubroSyncService::class);

        // Estado determinístico: un único rubro de sistema con el nombre por defecto
        // que deja la migración en una instalación sin licencia ("Comercio General").
        BusinessSetting::where('key', SystemRubroSyncService::SYNCED_KEY)->delete();
        Category::query()->delete();
        Rubro::query()->delete();
        Rubro::create(['name' => 'Comercio General', 'is_system' => true]);
    }

    private function systemRubro(): Rubro
    {
        return Rubro::where('is_system', true)->firstOrFail();
    }

    private function syncedType(): ?string
    {
        return BusinessSetting::where('key', SystemRubroSyncService::SYNCED_KEY)->value('value');
    }

    public function test_fresh_install_gets_rubro_matching_hardware_store_license(): void
    {
        $this->sync->sync('hardware_store');

        $this->assertSame('Ferretería', $this->systemRubro()->name);
        $this->assertSame('hardware_store', $this->syncedType());
        $this->assertSame(1, Rubro::count());
    }

    public function test_license_business_type_change_renames_system_rubro(): void
    {
        $this->sync->sync('hardware_store');
        $this->assertSame('Ferretería', $this->systemRubro()->name);

        $this->sync->sync('retail');

        $this->assertSame('Comercio General', $this->systemRubro()->name);
        $this->assertSame('retail', $this->syncedType());
        $this->assertSame(1, Rubro::count());
    }

    public function test_same_business_type_does_not_overwrite_manual_rename(): void
    {
        $this->sync->sync('hardware_store');
        $this->systemRubro()->update(['name' => 'Mi Corralón']);

        // Heartbeats repetidos cada 3 minutos con el mismo tipo de negocio.
        $this->sync->sync('hardware_store');
        $this->sync->sync('hardware_store');

        $this->assertSame('Mi Corralón', $this->systemRubro()->name);
    }

    public function test_first_sync_does_not_overwrite_a_name_edited_before_this_feature(): void
    {
        $this->systemRubro()->update(['name' => 'Pinturería Central']);

        $this->sync->sync('hardware_store');

        $this->assertSame('Pinturería Central', $this->systemRubro()->name);
        $this->assertSame('hardware_store', $this->syncedType());
    }

    public function test_business_type_change_renames_even_after_manual_edit(): void
    {
        $this->sync->sync('retail');
        $this->systemRubro()->update(['name' => 'Mi Almacén']);

        $this->sync->sync('hardware_store');

        $this->assertSame('Ferretería', $this->systemRubro()->name);
    }

    public function test_unknown_or_null_business_type_is_ignored(): void
    {
        $this->sync->sync('pharmacy');
        $this->sync->sync(null);

        $this->assertSame('Comercio General', $this->systemRubro()->name);
        $this->assertNull($this->syncedType());
    }

    public function test_rename_is_skipped_without_error_when_target_name_already_exists(): void
    {
        $this->sync->sync('retail');
        Rubro::create(['name' => 'Ferretería', 'is_system' => false]);

        $this->sync->sync('hardware_store');

        $this->assertSame('Comercio General', $this->systemRubro()->name);
        $this->assertSame(2, Rubro::count());
        $this->assertSame('hardware_store', $this->syncedType());
    }

    public function test_missing_system_rubro_is_created_or_promoted(): void
    {
        Rubro::query()->delete();
        $this->sync->sync('hardware_store');
        $this->assertSame('Ferretería', $this->systemRubro()->name);

        Rubro::query()->delete();
        Rubro::create(['name' => 'Ferretería', 'is_system' => false]);
        $this->sync->sync('hardware_store');

        $this->assertSame(1, Rubro::count());
        $this->assertTrue(Rubro::where('name', 'Ferretería')->value('is_system'));
    }

    public function test_sync_never_touches_categories(): void
    {
        $rubro = $this->systemRubro();
        $category = Category::create(['name' => 'Bebidas', 'rubro_id' => $rubro->id]);

        $this->sync->sync('hardware_store');

        $this->assertSame($rubro->id, $category->fresh()->rubro_id);
        $this->assertSame(1, Category::count());
    }

    public function test_license_heartbeat_syncs_rubro_with_server_business_type(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'KEY-1']);

        // 1ª respuesta: ferretería. 2ª respuesta: el administrador cambió el negocio a retail.
        Http::fake([
            '*/api/validate' => Http::sequence()
                ->push(['plan' => 'premium', 'business_type' => 'hardware_store', 'features' => []], 200)
                ->push(['plan' => 'premium', 'business_type' => 'retail', 'features' => []], 200),
        ]);

        app(LicenseSyncService::class)->syncHeartbeat();
        $this->assertSame('Ferretería', $this->systemRubro()->name);

        app(LicenseSyncService::class)->syncHeartbeat();
        $this->assertSame('Comercio General', $this->systemRubro()->name);
    }

    public function test_activate_manual_gives_new_installation_the_correct_rubro(): void
    {
        Http::fake([
            '*/api/validate' => Http::response([
                'plan' => 'basic',
                'business_type' => 'hardware_store',
                'features' => [],
            ], 200),
        ]);

        app(LicenseSyncService::class)->activateManual('NEW-INSTALL-KEY');

        $this->assertSame('Ferretería', $this->systemRubro()->name);
    }

    public function test_rubro_sync_failure_does_not_break_license_sync(): void
    {
        $this->mock(SystemRubroSyncService::class, function ($mock) {
            $mock->shouldReceive('sync')->andThrow(new \RuntimeException('boom'));
        });

        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'KEY-2']);
        Http::fake([
            '*/api/validate' => Http::response([
                'plan' => 'premium',
                'business_type' => 'hardware_store',
                'features' => ['multi_rubro' => true],
            ], 200),
        ]);

        app(LicenseSyncService::class)->syncHeartbeat();

        $this->assertSame('premium', BusinessSetting::where('key', 'app_plan')->value('value'));
        $this->assertSame('hardware_store', BusinessSetting::where('key', 'license_business_type')->value('value'));
        $this->assertNotNull(BusinessSetting::where('key', 'last_license_check')->value('value'));
    }
}
