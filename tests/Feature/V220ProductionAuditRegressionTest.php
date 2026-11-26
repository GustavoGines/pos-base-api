<?php

namespace Tests\Feature;

use App\Models\BulkPriceHistory;
use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class V220ProductionAuditRegressionTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    /**
     * #1a: Una instalación v1.8.5 con licencia Vitalicia (`lifetime`) sin `license_integrity_hash`
     * recibe el sello HMAC durante la migración y puede operar 100% offline sin recibir 403.
     */
    public function test_finding1_migration_backfills_integrity_seal_for_legacy_v185_lifetime_offline(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        // Estado heredado de una BD v1.8.5 con licencia Pago Único / Vitalicia
        BusinessSetting::where('key', 'license_integrity_hash')->delete();
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'POS-V185-LIFETIME-001']);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan'], ['value' => 'basic']); // Clave obsoleta desincronizada
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'lifetime']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => json_encode([
            'fast_pos' => true,
            'suppliers' => true,
            'multi_caja' => true,
        ])]);

        $this->assertFalse(LicenseSyncService::verifyLocalIntegrity());

        // Ejecutar la migración de backfill idempotente
        $migration = require database_path('migrations/2026_11_25_000001_backfill_license_integrity_hash_for_legacy_installations.php');
        $migration->up();

        $this->assertNull(BusinessSetting::where('key', 'license_plan')->value('value'));
        $this->assertNotEmpty(BusinessSetting::where('key', 'license_integrity_hash')->value('value'));
        $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());

        // Operación 100% offline: prohibimos peticiones HTTP externas
        Http::preventStrayRequests();

        $this->actingAsAdmin($admin)
            ->postJson('/api/shifts/open', [
                'user_id' => $admin->id,
                'opening_balance' => 1500,
            ])
            ->assertSuccessful();

        $this->actingAsAdmin($admin)
            ->getJson('/api/sales')
            ->assertOk();
    }

    /**
     * #1b: Si falta `license_integrity_hash` pero hay `license_key` y conexión válida con el servidor,
     * EnsureLicenseIsActive recupera el sello vía syncHeartbeat; si es adulteración offline, mantiene 403.
     */
    public function test_finding1_middleware_recovers_missing_seal_when_online_and_blocks_offline_tampering(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Caso A: Instalación v1.8.5 sin sello pero con servidor alcanzable y firma Ed25519 válida
        BusinessSetting::where('key', 'license_integrity_hash')->delete();
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'POS-V185-ONLINE-LIFETIME']);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'lifetime']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '1']);

        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'lifetime',
            'business_type' => 'retail',
            'server_time' => now()->toIso8601String(),
            'expires_at' => null,
            'features' => ['fast_pos' => true, 'multi_caja' => true],
        ]);

        $this->actingAsAdmin($admin)
            ->getJson('/api/sales')
            ->assertOk();

        $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());

        // Caso B: Borrado malicioso de `license_integrity_hash` + `lifetime` sin validación del servidor -> 403
        BusinessSetting::where('key', 'license_integrity_hash')->delete();
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'lifetime']);
        $this->setLicenseServerHandler(fn () => Http::response('', 500));

        $this->actingAsAdmin($admin)
            ->getJson('/api/sales')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'LICENSE_INTEGRITY_VIOLATION');
    }

    /**
     * #3: En Plan Básico con Add-on individual `suppliers` (`expenses = false`),
     * el comercio puede abonar y anular pagos a proveedores, pero NO registrar gastos operativos sueltos.
     */
    public function test_finding3_basic_plan_with_suppliers_addon_can_pay_supplier_and_void_payment_without_expenses_feature(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $shift = $this->crearTurnoAbierto(10000.00, $admin);

        $featuresJson = json_encode([
            'fast_pos' => true,
            'suppliers' => true,
            'expenses' => false,
        ]);
        $seal = LicenseSyncService::computeLocalIntegritySeal('basic', 'saas', '0', $featuresJson);

        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'basic']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'saas']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '0']);
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => $featuresJson]);
        BusinessSetting::updateOrCreate(['key' => 'last_license_check'], ['value' => now()->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'license_integrity_hash'], ['value' => $seal]);

        $supplier = Supplier::create([
            'name' => 'Distribuidora Norte',
            'balance' => 5000.00,
        ]);

        // 1. Pago a proveedor permitido (201)
        $payResponse = $this->actingAsAdmin($admin)->postJson('/api/cash-movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'supplier_payment',
            'supplier_id' => $supplier->id,
            'category' => 'Pago a Proveedor',
            'description' => 'Abono parcial factura compra',
            'payments' => [
                [
                    'payment_method' => 'cash',
                    'funding_source' => 'cash_register',
                    'amount' => 2000.00,
                ],
            ],
        ]);

        $payResponse->assertCreated();
        $this->assertEquals(3000.00, (float) $supplier->fresh()->balance);

        $movementId = $payResponse->json('movements.0.id');
        $this->assertNotNull($movementId);

        // 2. Anulación del pago a proveedor permitida (200)
        $this->actingAsAdmin($admin)
            ->deleteJson("/api/cash-movements/{$movementId}")
            ->assertOk();
        $this->assertEquals(5000.00, (float) $supplier->fresh()->balance);

        // 3. Gasto común sin supplier_id sigue bloqueado con 403 FEATURE_NOT_LICENSED (expenses)
        $this->actingAsAdmin($admin)->postJson('/api/cash-movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'expense',
            'category' => 'Gasto',
            'description' => 'Artículos de limpieza',
            'payments' => [
                [
                    'payment_method' => 'cash',
                    'funding_source' => 'cash_register',
                    'amount' => 500.00,
                ],
            ],
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'expenses');

        // 4. Listado general de gastos operativos sigue bloqueado sin `expenses`
        $this->actingAsAdmin($admin)
            ->getJson('/api/cash-movements')
            ->assertStatus(403)
            ->assertJsonPath('required', 'expenses');
    }

    /**
     * #5: `bulkPriceUpdate` actualiza costos de combos padres en transacción y `bulkPriceRevert`
     * protege productos cuyos precios fueron modificados con posterioridad al lote.
     */
    public function test_finding5_bulk_price_update_and_revert_protect_subsequent_edits_and_recalculate_parent_combos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $prodA = Product::create([
            'name' => 'Insumo A',
            'internal_code' => 'BULK-A',
            'cost_price' => 100.00,
            'selling_price' => 200.00,
            'stock' => 20,
            'active' => true,
        ]);

        $prodB = Product::create([
            'name' => 'Insumo B',
            'internal_code' => 'BULK-B',
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'stock' => 20,
            'active' => true,
        ]);

        $combo = Product::create([
            'name' => 'Combo A+B',
            'internal_code' => 'COMBO-AB',
            'cost_price' => 250.00, // 2 * 100 + 1 * 50
            'selling_price' => 450.00,
            'stock' => 10,
            'is_combo' => true,
            'active' => true,
        ]);

        DB::table('product_combos')->insert([
            ['parent_product_id' => $combo->id, 'child_product_id' => $prodA->id, 'quantity' => 2],
            ['parent_product_id' => $combo->id, 'child_product_id' => $prodB->id, 'quantity' => 1],
        ]);

        // 1. Aumento masivo +10% en costo y precio de venta sobre prodA y prodB
        $this->actingAsAdmin($admin)->putJson('/api/catalog/products/bulk-price-update', [
            'product_ids' => [$prodA->id, $prodB->id],
            'percentage' => 10,
            'rounding_rule' => 'none',
            'target_field' => 'cost_and_selling_price',
        ])->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertEquals(110.00, (float) $prodA->fresh()->cost_price);
        $this->assertEquals(220.00, (float) $prodA->fresh()->selling_price);
        $this->assertEquals(55.00, (float) $prodB->fresh()->cost_price);
        $this->assertEquals(110.00, (float) $prodB->fresh()->selling_price);
        // Combo padre actualizado: 2 * 110 + 1 * 55 = 275.00
        $this->assertEquals(275.00, (float) $combo->fresh()->cost_price);

        $history = BulkPriceHistory::latest('id')->firstOrFail();

        // 2. Modificación posterior manual / por compra sobre prodA (nuevo precio venta 300.00)
        $prodA->update(['selling_price' => 300.00]);

        // 3. Revertir lote: prodB vuelve a 50/100, prodA conserva 110/300 y el combo pasa a 2*110 + 50 = 270.00
        $revertRes = $this->actingAsAdmin($admin)
            ->postJson("/api/catalog/bulk-price-history/{$history->id}/revert");

        $revertRes->assertOk()
            ->assertJsonPath('reverted_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $this->assertEquals(110.00, (float) $prodA->fresh()->cost_price);
        $this->assertEquals(300.00, (float) $prodA->fresh()->selling_price);
        $this->assertEquals(50.00, (float) $prodB->fresh()->cost_price);
        $this->assertEquals(100.00, (float) $prodB->fresh()->selling_price);
        $this->assertEquals(270.00, (float) $combo->fresh()->cost_price);
        $this->assertTrue((bool) $history->fresh()->reverted);
    }

    /**
     * #6: Restaurar un backup antiguo que contenía `license_expires_at` y `license_plan`
     * sobre una instalación actual `lifetime` (`license_expires_at = null`) limpia esas claves
     * y no dispara 403 LICENSE_INTEGRITY_VIOLATION.
     */
    public function test_finding6_restore_backup_clears_null_license_fields_and_legacy_license_plan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Generar un backup cuando la instalación tenía licencia SaaS Básica y clave vieja `license_plan`
        $saasFeatures = json_encode(['fast_pos' => true]);
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'OLD-SAAS-KEY']);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'basic']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan'], ['value' => 'basic']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'saas']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '0']);
        BusinessSetting::updateOrCreate(['key' => 'license_expires_at'], ['value' => now()->addMonth()->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'last_license_check'], ['value' => now()->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => $saasFeatures]);
        BusinessSetting::updateOrCreate(['key' => 'license_integrity_hash'], [
            'value' => LicenseSyncService::computeLocalIntegritySeal('basic', 'saas', '0', $saasFeatures),
        ]);

        $backupResponse = $this->actingAsAdmin($admin)->get('/api/system/backup?include_db=1');
        $backupResponse->assertOk();
        $zipPath = $backupResponse->baseResponse->getFile()->getPathname();
        $copiedZip = storage_path('app/private/backups/test_f6_copy.zip');
        copy($zipPath, $copiedZip);

        try {
            // 2. El comercio pasa a licencia Vitalicia PRO (sin `license_expires_at` ni `license_plan`)
            $proFeatures = json_encode(['fast_pos' => true, 'multi_caja' => true, 'suppliers' => true]);
            BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'CURRENT-LIFETIME-PRO-KEY']);
            BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
            BusinessSetting::whereIn('key', ['license_plan', 'license_expires_at'])->delete();
            BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'lifetime']);
            BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '1']);
            BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => $proFeatures]);
            BusinessSetting::updateOrCreate(['key' => 'license_integrity_hash'], [
                'value' => LicenseSyncService::computeLocalIntegritySeal('pro', 'lifetime', '1', $proFeatures),
            ]);

            // 3. Restaurar el backup viejo
            $uploadedZip = new UploadedFile($copiedZip, 'backup_old.zip', 'application/zip', null, true);
            $this->actingAsAdmin($admin)->post('/api/system/restore', [
                'backup_file' => $uploadedZip,
            ])->assertOk();

            // 4. Las claves ausentes en la licencia actual (`license_expires_at`) y `license_plan` no deben quedar vivas
            $this->assertNull(BusinessSetting::where('key', 'license_expires_at')->value('value'));
            $this->assertNull(BusinessSetting::where('key', 'license_plan')->value('value'));
            $this->assertSame('pro', BusinessSetting::where('key', 'app_plan')->value('value'));
            $this->assertSame('lifetime', BusinessSetting::where('key', 'license_plan_mode')->value('value'));
            $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());

            // 5. Siguiente petición protegida responde 200 OK (sin 403 LICENSE_INTEGRITY_VIOLATION)
            $this->actingAsAdmin($admin)
                ->getJson('/api/sales')
                ->assertOk();
        } finally {
            @unlink($copiedZip);
        }
    }

    /**
     * B-03: Restaurar un backup generado en Modo Demo (`demo_mode_active = '1'`) sobre un comercio
     * con licencia paga (`license_is_trial = '0'`) fuerza `demo_mode_active = '0'` y limpia metadatos demo,
     * evitando que ARCA quede bloqueado o que el banner ofrezca purgar datos reales.
     */
    public function test_b03_restore_backup_sanitizes_demo_mode_when_local_license_is_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 1. Generar un backup mientras el sistema tenía demo_mode_active = '1' (en Trial)
        $trialFeatures = json_encode(['fast_pos' => true, 'arca_afip' => true]);
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'TRIAL-DEMO-KEY']);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'saas']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_lifetime'], ['value' => '0']);
        BusinessSetting::updateOrCreate(['key' => 'license_is_trial'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'last_license_check'], ['value' => now()->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => $trialFeatures]);
        BusinessSetting::updateOrCreate(['key' => 'license_integrity_hash'], [
            'value' => LicenseSyncService::computeLocalIntegritySeal('pro', 'saas', '0', $trialFeatures),
        ]);
        BusinessSetting::updateOrCreate(['key' => 'demo_mode_active'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'demo_seeded_at'], ['value' => now()->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'demo_max_seeded_product_id'], ['value' => '150']);
        BusinessSetting::updateOrCreate(['key' => 'demo_prev_enable_advanced_price_tiers'], ['value' => '0']);

        $backupResponse = $this->actingAsAdmin($admin)->get('/api/system/backup?include_db=1');
        $backupResponse->assertOk();
        $zipPath = $backupResponse->baseResponse->getFile()->getPathname();
        $copiedZip = storage_path('app/private/backups/test_b03_demo_contaminate.zip');
        copy($zipPath, $copiedZip);

        try {
            // 2. El comercio ahora tiene licencia Paga activa (`license_is_trial = '0'`) y NO está en modo demo
            BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'PAID-PRO-KEY-2026']);
            BusinessSetting::updateOrCreate(['key' => 'license_api_key'], ['value' => 'PAID-PRO-KEY-2026']);
            BusinessSetting::updateOrCreate(['key' => 'license_is_trial'], ['value' => '0']);
            BusinessSetting::updateOrCreate(['key' => 'demo_mode_active'], ['value' => '0']);
            BusinessSetting::whereIn('key', [
                'demo_seeded_at',
                'demo_max_seeded_product_id',
                'demo_prev_enable_advanced_price_tiers',
            ])->delete();

            // 3. Restaurar el backup que contenía demo_mode_active = '1'
            $uploadedZip = new UploadedFile($copiedZip, 'backup_demo.zip', 'application/zip', null, true);
            $this->actingAsAdmin($admin)->post('/api/system/restore', [
                'backup_file' => $uploadedZip,
            ])->assertOk();

            // 4. La licencia paga no debe quedar atrapada en demo_mode_active = '1'
            $this->assertSame('0', BusinessSetting::where('key', 'license_is_trial')->value('value'));
            $this->assertSame('PAID-PRO-KEY-2026', BusinessSetting::where('key', 'license_api_key')->value('value'));
            $this->assertFalse(BusinessSetting::isDemoModeActive());
            $this->assertSame('0', BusinessSetting::where('key', 'demo_mode_active')->value('value'));
            $this->assertNull(BusinessSetting::where('key', 'demo_seeded_at')->value('value'));
            $this->assertNull(BusinessSetting::where('key', 'demo_max_seeded_product_id')->value('value'));
            $this->assertNull(BusinessSetting::where('key', 'demo_prev_enable_advanced_price_tiers')->value('value'));
        } finally {
            @unlink($copiedZip);
        }
    }

    /**
     * M-03, M-04 y C-02:
     * - AfipWsfeService::authorizeInvoice bloquea directamente en defensa en profundidad si demo_mode_active = '1'.
     * - SettingController::update / syncLicense no filtran hashes internos de licencia y exponen demo_mode_active.
     * - TrashController::index permite buscar clientes eliminados sin DNI por número de teléfono.
     */
    public function test_m03_m04_c02_wsfe_demo_guard_settings_public_keys_and_trash_phone_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // C-02: Cliente sin DNI eliminado buscable por teléfono en papelera
        $customer = \App\Models\Customer::create([
            'name' => 'Cliente Sin Documento',
            'document_type' => 99,
            'document_number' => null,
            'phone' => '1155667788',
            'tax_condition' => 'consumidor_final',
        ]);
        $customer->delete();

        $this->actingAsAdmin($admin)
            ->getJson('/api/trash/customers?search=556677')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id);

        // M-04: PUT /api/settings no filtra license_integrity_hash ni license_clock_anchor_sale_id
        BusinessSetting::updateOrCreate(['key' => 'license_clock_anchor_sale_id'], ['value' => '42']);
        $updateRes = $this->actingAsAdmin($admin)->putJson('/api/settings', [
            'company_name' => 'Comercio Auditado',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('settings.company_name', 'Comercio Auditado')
            ->assertJsonPath('settings.demo_mode_active', '0')
            ->assertJsonMissingPath('settings.license_integrity_hash')
            ->assertJsonMissingPath('settings.license_clock_anchor_sale_id');

        // M-03: Guardia directa en AfipWsfeService::authorizeInvoice cuando demo_mode_active = '1'
        BusinessSetting::updateOrCreate(['key' => 'demo_mode_active'], ['value' => '1']);
        $shift = $this->crearTurnoAbierto(5000.00, $admin);
        $sale = \App\Models\Sale::create([
            'user_id' => $admin->id,
            'cash_shift_id' => $shift->id,
            'subtotal' => 1000.00,
            'total' => 1000.00,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modo Datos de Ejemplo');
        app(\App\Services\Afip\AfipWsfeService::class)->authorizeInvoice($sale);
    }
}
