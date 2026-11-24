<?php

namespace Tests\Feature;

use App\Exports\PriceListExport;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Catalog\CatalogImportService;
use App\Services\InventoryAlertService;
use App\Services\LicenseSyncService;
use App\Services\SystemRubroSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class MultiVerticalSupportTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    public function test_license_sync_persists_secondary_business_types_and_creates_secondary_rubros(): void
    {
        $this->fakeSignedLicenseServer([
            'plan' => 'basic',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'secondary_business_types' => ['hardware_store', 'retail', 'invalid_type'],
            'features' => [
                'multi_rubro' => true,
                'batch_expiration' => true,
                'quotes' => true,
                'logistics' => true,
                'multiple_prices' => true,
                'production' => false,
            ],
        ]);

        app(LicenseSyncService::class)->activateManual('POLY-TEST-001');

        $this->assertSame('retail', BusinessSetting::where('key', 'license_business_type')->value('value'));
        $this->assertSame(
            '["hardware_store"]',
            BusinessSetting::where('key', 'license_secondary_business_types')->value('value')
        );

        $this->assertDatabaseHas('rubros', [
            'name' => 'Comercio General',
            'vertical_type' => 'retail',
            'is_system' => true,
        ]);
        $this->assertDatabaseHas('rubros', [
            'name' => 'Ferretería',
            'vertical_type' => 'hardware_store',
            'is_system' => false,
        ]);

        // Re-ejecutar heartbeat no duplica rubros secundarios
        app(LicenseSyncService::class)->syncHeartbeat();
        $this->assertSame(1, Rubro::where('vertical_type', 'hardware_store')->count());
    }

    public function test_non_food_verticals_disable_batch_expiration_fallback_while_butcher_and_retail_enable_it(): void
    {
        Product::create([
            'name' => 'Producto Test Vencimiento',
            'internal_code' => '70099',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'active' => true,
            'expiration_date' => now()->addDays(2)->format('Y-m-d'),
            'expiry_alert_days' => 15,
        ]);

        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'basic']);
        $alertService = app(InventoryAlertService::class);

        foreach (['apparel', 'tech', 'general', 'hardware_store'] as $vertical) {
            BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => $vertical]);
            BusinessSetting::where('key', 'license_secondary_business_types')->delete();
            BusinessSetting::where('key', 'license_features_dict')->delete();

            $alerts = $alertService->getPredictiveAlerts();
            $this->assertEmpty(
                $alerts['expiration_alerts'],
                "Vertical {$vertical} no debe activar alertas de vencimiento por fallback"
            );

            $res = $this->getJson('/api/pos/batches/scan?code=BATCH-TEST');
            $res->assertStatus(403)
                ->assertJsonPath('required', 'batch_expiration');
        }

        foreach (['retail', 'butcher'] as $foodVertical) {
            BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => $foodVertical]);
            BusinessSetting::where('key', 'license_secondary_business_types')->delete();
            BusinessSetting::where('key', 'license_features_dict')->delete();

            $alerts = $alertService->getPredictiveAlerts();
            $this->assertNotEmpty(
                $alerts['expiration_alerts'],
                "Vertical {$foodVertical} debe activar alertas de vencimiento por fallback"
            );

            $res = $this->getJson('/api/pos/batches/scan?code=BATCH-TEST');
            $this->assertNotSame(403, $res->status(), "Vertical {$foodVertical} no debe ser bloqueado por feature:batch_expiration");
        }
    }

    public function test_rubro_crud_validates_and_persists_vertical_type_and_description(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => true])]
        );

        $createRes = $this->postJson('/api/catalog/rubros', [
            'name' => 'Carnicería del Barrio',
            'description' => 'Cortes frescos y achuras',
            'vertical_type' => 'butcher',
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('vertical_type', 'butcher')
            ->assertJsonPath('description', 'Cortes frescos y achuras');

        $rubroId = (int) $createRes->json('id');

        $this->putJson("/api/catalog/rubros/{$rubroId}", [
            'name' => 'Carnicería y Pollería',
            'vertical_type' => 'butcher',
        ])->assertOk()
            ->assertJsonPath('name', 'Carnicería y Pollería')
            ->assertJsonPath('vertical_type', 'butcher');

        $this->postJson('/api/catalog/rubros', [
            'name' => 'Rubro Inválido',
            'vertical_type' => 'astrologia',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['vertical_type']);
    }

    public function test_update_calc_config_allowed_when_hardware_store_is_secondary_vertical(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'session_token' => 'calc-sec-token',
        ]);

        $product = Product::create([
            'name' => 'Machimbre 1/2 x 4 x 3.00m',
            'internal_code' => '70001',
            'cost_price' => 1000,
            'selling_price' => 2000,
            'stock' => 50,
            'active' => true,
        ]);

        // Caso 1: retail puro -> 403 HARDWARE_STORE_ONLY
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '[]']);

        $this->withHeaders(['X-Session-Token' => 'calc-sec-token'])
            ->patchJson("/api/catalog/products/{$product->id}/calc-config", [
                'calc_config' => ['type' => 'wood', 'length_m' => 3.0],
            ])->assertStatus(403);

        // Caso 2: retail + secondary hardware_store -> 200 OK
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '["hardware_store"]']);

        $this->withHeaders(['X-Session-Token' => 'calc-sec-token'])
            ->patchJson("/api/catalog/products/{$product->id}/calc-config", [
                'calc_config' => ['type' => 'wood', 'length_m' => 4.0],
            ])->assertOk();
    }

    public function test_system_rubro_sync_maps_all_six_business_types(): void
    {
        $sync = app(SystemRubroSyncService::class);
        foreach (SystemRubroSyncService::BUSINESS_TYPE_NAMES as $type => $expectedName) {
            $sync->sync($type);
            $rubro = Rubro::where('is_system', true)->firstOrFail();
            $this->assertSame($expectedName, $rubro->name);
            $this->assertSame($type, $rubro->vertical_type);
        }
    }

    public function test_settings_update_blocks_business_type_and_secondary_business_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        BusinessSetting::updateOrCreate(['key' => 'business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'secondary_business_types'], ['value' => '[]']);

        $this->putJson('/api/settings', [
            'business_name' => 'Autoservicio Formosa',
            'business_type' => 'hardware_store',
            'secondary_business_types' => ['hardware_store', 'tech'],
            'license_business_type' => 'hardware_store',
        ])->assertOk();

        $this->assertSame('Autoservicio Formosa', BusinessSetting::where('key', 'business_name')->value('value'));
        $this->assertSame('retail', BusinessSetting::where('key', 'business_type')->value('value'));
        $this->assertSame('retail', BusinessSetting::where('key', 'license_business_type')->value('value'));
        $this->assertSame('[]', BusinessSetting::where('key', 'secondary_business_types')->value('value'));
    }

    public function test_update_calc_config_allowed_when_product_category_belongs_to_hardware_store_rubro(): void
    {
        User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'session_token' => 'calc-rubro-token',
        ]);

        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '[]']);

        $hwRubro = Rubro::create([
            'name' => 'Ferretería Interna',
            'vertical_type' => 'hardware_store',
            'is_system' => false,
        ]);
        $hwCategory = Category::create([
            'name' => 'Maderas Duras',
            'rubro_id' => $hwRubro->id,
        ]);
        $woodProduct = Product::create([
            'name' => 'Madera Lapacho 1 x 6 x 4m',
            'internal_code' => '70088',
            'category_id' => $hwCategory->id,
            'cost_price' => 15000,
            'selling_price' => 25000,
            'stock' => 20,
            'active' => true,
        ]);

        $this->withHeaders(['X-Session-Token' => 'calc-rubro-token'])
            ->patchJson("/api/catalog/products/{$woodProduct->id}/calc-config", [
                'calc_config' => ['type' => 'wood', 'inch_preset' => '6', 'length_m' => 4.0],
            ])->assertOk();
    }

    public function test_catalog_import_supports_apparel_talle_color_perchero_and_docena(): void
    {
        $csvContent = "codigo_interno,nombre,talle,color,perchero,unidad,costo,precio,stock\n"
            ."00801,Remera Algodon Basica,XL,Negro,Perchero B,docena,45000,68000,10\n"
            ."00802,Pantalon Jean Clorinda,42,Azul,,un,18000,29000,6\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'import_apparel_').'.csv';
        file_put_contents($tmpFile, $csvContent);

        try {
            $result = app(CatalogImportService::class)->import($tmpFile);
            $this->assertSame(2, $result['created_count']);

            $shirt = Product::where('internal_code', '00801')->firstOrFail();
            $this->assertSame('Talle XL - Negro', $shirt->technical_spec);
            $this->assertSame('Perchero B', $shirt->storage_location);
            $this->assertSame('docena', $shirt->unit_type);
            $this->assertFalse((bool) $shirt->is_sold_by_weight);

            $jean = Product::where('internal_code', '00802')->firstOrFail();
            $this->assertSame('Talle 42 - Azul', $jean->technical_spec);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_price_list_export_preserves_kg_for_retail_butcher_and_exports_un_for_hardware_fractional_in_mixed_store(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '["hardware_store"]']);

        $butcherRubro = Rubro::create([
            'name' => 'Carnicería y Verdulería',
            'vertical_type' => 'butcher',
            'is_system' => false,
        ]);
        $butcherCat = Category::create(['name' => 'Cortes Vacunos', 'rubro_id' => $butcherRubro->id]);

        $hwRubro = Rubro::create([
            'name' => 'Maderera',
            'vertical_type' => 'hardware_store',
            'is_system' => false,
        ]);
        $hwCat = Category::create(['name' => 'Maderas Nativas', 'rubro_id' => $hwRubro->id]);

        $costilla = Product::create([
            'name' => 'Costilla Novillo',
            'internal_code' => '81001',
            'category_id' => $butcherCat->id,
            'unit_type' => 'un',
            'is_sold_by_weight' => true,
            'cost_price' => 6000,
            'selling_price' => 9500,
            'stock' => 40,
            'active' => true,
        ]);

        $lapacho = Product::create([
            'name' => 'Madera Lapacho 1 x 6 x 4m',
            'internal_code' => '81002',
            'category_id' => $hwCat->id,
            'unit_type' => 'un',
            'is_sold_by_weight' => true,
            'calc_config' => ['type' => 'wood', 'inch_preset' => '6', 'length_m' => 4.0],
            'cost_price' => 24000,
            'selling_price' => 36930,
            'stock' => 15,
            'active' => true,
        ]);

        $export = new PriceListExport;
        $costillaRow = $export->map($costilla->load('category.rubro'));
        $lapachoRow = $export->map($lapacho->load('category.rubro'));

        $this->assertSame('kg', $costillaRow[10]);
        $this->assertSame('un', $lapachoRow[10]);
    }
}
