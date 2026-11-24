<?php

namespace Tests\Feature;

use App\Exports\PriceListExport;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductRecipeItem;
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

    public function test_hardware_store_without_batch_addon_ignores_legacy_expired_batch_lock_and_preserves_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('1234')]);
        $this->actingAsAdmin($admin);

        $register = CashRegister::create(['name' => 'Caja Ferretería', 'is_active' => true]);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $admin->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
        $cash = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'surcharge_type' => 'none',
            'surcharge_value' => 0,
            'is_cash' => true,
            'is_active' => true,
        ]);

        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'hardware_store']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '[]']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['batch_expiration' => false, 'production' => false, 'logistics' => true])]
        );

        $ingredient = Product::create([
            'name' => 'Dulce de Leche',
            'internal_code' => '82000',
            'cost_price' => 1000,
            'selling_price' => 2500,
            'stock' => 20,
            'active' => true,
        ]);

        $legacyProduct = Product::create([
            'name' => 'Alfajores de Maicena x12',
            'internal_code' => '82001',
            'cost_price' => 3200,
            'selling_price' => 8500,
            'stock' => 15,
            'active' => true,
            'track_batches' => true,
            'expiration_date' => now()->subDays(5)->format('Y-m-d'),
            'has_production_recipe' => true,
        ]);

        $expiredBatch = ProductBatch::create([
            'product_id' => $legacyProduct->id,
            'batch_code' => 'LEGACY-EXP-01',
            'source_type' => ProductBatch::SOURCE_OPENING,
            'initial_quantity' => 15,
            'remaining_quantity' => 15,
            'expiration_date' => now()->subDays(5)->format('Y-m-d'),
            'status' => ProductBatch::STATUS_ACTIVE,
        ]);

        ProductRecipeItem::create([
            'parent_product_id' => $legacyProduct->id,
            'ingredient_product_id' => $ingredient->id,
            'quantity' => 0.5,
            'waste_pct' => 0,
        ]);

        $this->assertFalse(BusinessSetting::canUseBatchExpirationForProduct($legacyProduct));

        // 1. Vender en POS pasa 201 sin exigir PIN de lote vencido ni tocar el lote legado
        $saleRes = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'total' => 17000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $legacyProduct->id,
                'quantity' => 2,
                'unit_price' => 8500,
                'subtotal' => 17000,
            ]],
            'payments' => [[
                'payment_method_id' => $cash->id,
                'base_amount' => 17000,
                'surcharge_amount' => 0,
                'total_amount' => 17000,
            ]],
        ])->assertCreated();

        $this->assertEquals(13.0, (float) $legacyProduct->fresh()->stock);
        $this->assertEquals(15.0, (float) $expiredBatch->fresh()->remaining_quantity);

        // 2. Anular venta restituye products.stock exacto (13 -> 15)
        $this->postJson('/api/sales/'.$saleRes->json('sale.id').'/void', [
            'reason' => 'Prueba anulación sin lotes',
            'cash_shift_id' => $shift->id,
        ])->assertOk();
        $this->assertEquals(15.0, (float) $legacyProduct->fresh()->stock);

        // 3. Editar ficha en hardware_store actualiza stock/precio y preserva track_batches, batch y receta
        $this->putJson("/api/catalog/products/{$legacyProduct->id}", [
            'name' => 'Alfajores de Maicena x12',
            'cost_price' => 3500,
            'selling_price' => 9200,
            'stock' => 22,
            'track_batches' => false,
            'expiration_date' => null,
        ])->assertOk();

        $fresh = $legacyProduct->fresh();
        $this->assertEquals(22.0, (float) $fresh->stock);
        $this->assertTrue((bool) $fresh->track_batches);
        $this->assertSame($expiredBatch->expiration_date->format('Y-m-d'), $fresh->expiration_date->format('Y-m-d'));
        $this->assertDatabaseHas('product_batches', ['id' => $expiredBatch->id]);
        $this->assertDatabaseHas('product_recipe_items', ['parent_product_id' => $legacyProduct->id]);

        // 4. Ajustar stock en Ferretería ajusta stock directo sin tocar lotes históricos
        $this->postJson("/api/catalog/products/{$legacyProduct->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 3,
            'notes' => 'Ajuste en Ferretería',
        ])->assertOk();

        $this->assertEquals(25.0, (float) $legacyProduct->fresh()->stock);
        $this->assertEquals(15.0, (float) $expiredBatch->fresh()->remaining_quantity);
    }

    public function test_pintureria_with_explicit_batch_addon_and_polirrubro_product_scope_work_correctly(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('1234')]);
        $this->actingAsAdmin($admin);

        // A. Pinturería (hardware_store) CON addon batch_expiration explícito => respeta guardia de lote vencido
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'hardware_store']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '[]']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['batch_expiration' => true, 'production' => false])]
        );

        $pintura = Product::create([
            'name' => 'Esmalte Sintético 4L',
            'internal_code' => '83001',
            'cost_price' => 12000,
            'selling_price' => 19500,
            'stock' => 10,
            'active' => true,
            'track_batches' => true,
        ]);
        ProductBatch::create([
            'product_id' => $pintura->id,
            'batch_code' => 'PINT-EXP-1',
            'source_type' => ProductBatch::SOURCE_OPENING,
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'expiration_date' => now()->subDay()->format('Y-m-d'),
            'status' => ProductBatch::STATUS_ACTIVE,
        ]);
        $this->assertTrue(BusinessSetting::canUseBatchExpirationForProduct($pintura));

        // B. Polirrubro Mixto (retail + hardware_store) => Almacén SÍ usa lotes, Ferretería NO
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'retail']);
        BusinessSetting::updateOrCreate(['key' => 'license_secondary_business_types'], ['value' => '["hardware_store"]']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['batch_expiration' => true, 'production' => false, 'multi_rubro' => true])]
        );

        $rubroRetail = Rubro::create(['name' => 'Almacén y Pastelería', 'vertical_type' => 'retail', 'is_system' => false]);
        $rubroHw = Rubro::create(['name' => 'Ferretería y Maderas', 'vertical_type' => 'hardware_store', 'is_system' => false]);
        $catRetail = Category::create(['name' => 'Lácteos', 'rubro_id' => $rubroRetail->id]);
        $catHw = Category::create(['name' => 'Herramientas', 'rubro_id' => $rubroHw->id]);

        $yogur = Product::create([
            'name' => 'Yogur Entero',
            'internal_code' => '83002',
            'category_id' => $catRetail->id,
            'cost_price' => 800,
            'selling_price' => 1400,
            'stock' => 5,
            'track_batches' => true,
        ]);
        $martillo = Product::create([
            'name' => 'Martillo Galponero',
            'internal_code' => '83003',
            'category_id' => $catHw->id,
            'cost_price' => 5000,
            'selling_price' => 8900,
            'stock' => 5,
            'track_batches' => true,
        ]);

        $this->assertTrue(BusinessSetting::canUseBatchExpirationForProduct($yogur));
        $this->assertFalse(BusinessSetting::canUseBatchExpirationForProduct($martillo));
    }

    public function test_delivery_notes_expose_unit_type_technical_spec_calc_config_category_rubro_and_sale_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('1234')]);
        $this->actingAsAdmin($admin);

        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'hardware_store']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['logistics' => true, 'batch_expiration' => false])]
        );

        $register = CashRegister::create(['name' => 'Caja Corralón', 'is_active' => true]);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $admin->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
        $cash = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'surcharge_type' => 'none',
            'surcharge_value' => 0,
            'is_cash' => true,
            'is_active' => true,
        ]);

        $rubroHw = Rubro::create(['name' => 'Corralón', 'vertical_type' => 'hardware_store', 'is_system' => false]);
        $catAridos = Category::create(['name' => 'Áridos', 'rubro_id' => $rubroHw->id]);

        $arena = Product::create([
            'name' => 'Arena Fina del Río',
            'internal_code' => '84001',
            'category_id' => $catAridos->id,
            'unit_type' => 'm3',
            'is_sold_by_weight' => true,
            'technical_spec' => 'Lavada x m3',
            'calc_config' => ['type' => 'wood', 'inch_preset' => '4', 'length_m' => 3.0],
            'cost_price' => 15000,
            'selling_price' => 24000,
            'stock' => 20,
            'active' => true,
        ]);

        $saleRes = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'total' => 84000,
            'total_surcharge' => 0,
            'requires_dispatch' => true,
            'fulfillment_status' => 'pending',
            'items' => [[
                'product_id' => $arena->id,
                'quantity' => 3.5,
                'unit_price' => 24000,
                'subtotal' => 84000,
                'custom_details' => 'Volcar frente a obra',
            ]],
            'payments' => [[
                'payment_method_id' => $cash->id,
                'base_amount' => 84000,
                'surcharge_amount' => 0,
                'total_amount' => 84000,
            ]],
        ])->assertCreated();

        $noteId = $saleRes->json('sale.delivery_note.id');
        $this->assertNotNull($noteId);
        $saleRes->assertJsonPath('sale.delivery_note.items.0.product.unit_type', 'm3');
        $saleRes->assertJsonPath('sale.delivery_note.items.0.product.category.rubro.vertical_type', 'hardware_store');

        $indexRes = $this->getJson('/api/delivery-notes')->assertOk();
        $noteData = collect($indexRes->json('data'))->firstWhere('id', $noteId);
        $this->assertNotNull($noteData);
        $this->assertSame('m3', $noteData['items'][0]['product']['unit_type']);
        $this->assertSame('Lavada x m3', $noteData['items'][0]['product']['technical_spec']);
        $this->assertSame('hardware_store', $noteData['items'][0]['product']['category']['rubro']['vertical_type']);
        $this->assertSame('Volcar frente a obra', $noteData['sale']['items'][0]['custom_details']);

        $itemId = $noteData['items'][0]['id'];
        $deliverRes = $this->putJson("/api/delivery-notes/{$noteId}/deliver", [
            'items' => [['id' => $itemId, 'delivered_now' => 1.5]],
        ])->assertOk();

        $deliverRes->assertJsonPath('status', 'partial');
        $deliverRes->assertJsonPath('items.0.product.unit_type', 'm3');
        $deliverRes->assertJsonPath('items.0.product.category.rubro.vertical_type', 'hardware_store');
        $deliverRes->assertJsonPath('sale.items.0.custom_details', 'Volcar frente a obra');
    }
}
