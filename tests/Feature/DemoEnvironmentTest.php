<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\LicenseSyncService;
use Carbon\Carbon;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class DemoEnvironmentTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 14:30:00', 'America/Argentina/Buenos_Aires'));
    }

    protected function tearDown(): void
    {
        $backupDir = storage_path('app/private/backups');
        if (is_dir($backupDir)) {
            foreach (glob($backupDir.DIRECTORY_SEPARATOR.'pre_demo_reset_*.sql') ?: [] as $file) {
                @unlink($file);
            }
        }
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public static function verticalProvider(): array
    {
        return [
            'retail' => ['retail'],
            'butcher' => ['butcher'],
            'hardware_store' => ['hardware_store'],
            'apparel' => ['apparel'],
            'tech' => ['tech'],
            'general' => ['general'],
        ];
    }

    private function seedActiveTrialLicense(string $businessType = 'retail'): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        CashRegister::firstOrCreate(
            ['id' => 1],
            ['name' => 'Caja Principal', 'is_active' => true]
        );
        (new PaymentMethodSeeder)->run();
        (new ExpenseCategorySeeder)->run();

        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'is_trial' => true,
            'business_type' => $businessType,
            'server_time' => now()->toIso8601String(),
            'expires_at' => now()->addDays(15)->endOfDay()->toIso8601String(),
            'features' => [
                'fast_pos' => true,
                'z_reports' => true,
                'multi_rubro' => true,
                'quotes_delivery_notes' => true,
                'hardware_calculator' => true,
            ],
        ]);

        app(LicenseSyncService::class)->activateManual('DEMO-KEY-12345');

        BusinessSetting::updateOrCreate(['key' => 'printer_paper_width'], ['value' => '80']);
        BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => 'testing']);
        BusinessSetting::updateOrCreate(['key' => 'afip_point_of_sale'], ['value' => '5']);

        return $admin;
    }

    public function test_status_detects_fresh_empty_trial_store_and_dismiss_onboarding(): void
    {
        $admin = $this->seedActiveTrialLicense('retail');

        $statusRes = $this->actingAsAdmin($admin)->getJson('/api/system/demo/status');
        $statusRes->assertStatus(200)
            ->assertJson([
                'demo_mode_active' => false,
                'is_trial' => true,
                'is_fresh_empty_store' => true,
                'demo_onboarding_dismissed' => false,
                'business_type' => 'retail',
            ]);

        $dismissRes = $this->actingAsAdmin($admin)->postJson('/api/system/demo/dismiss-onboarding');
        $dismissRes->assertStatus(200)
            ->assertJsonPath('status.demo_onboarding_dismissed', true)
            ->assertJsonPath('status.is_fresh_empty_store', true);
    }

    #[DataProvider('verticalProvider')]
    public function test_vertical_seeds_cleanly_with_dynamic_dates_not_in_future(string $vertical): void
    {
        $admin = $this->seedActiveTrialLicense($vertical);

        $response = $this->actingAsAdmin($admin)->postJson('/api/system/demo/seed');
        $response->assertStatus(200)
            ->assertJsonPath('status.demo_mode_active', true)
            ->assertJsonPath('status.business_type', $vertical);

        $productCount = DB::table('products')->count();
        $saleCount = DB::table('sales')->count();
        $closedShifts = DB::table('cash_shifts')->where('status', 'closed')->count();
        $openShifts = DB::table('cash_shifts')->where('status', 'open')->count();
        $maxSaleCreatedAt = DB::table('sales')->max('created_at');

        $this->assertGreaterThanOrEqual(8, $productCount, "Vertical {$vertical} must seed products");
        $this->assertGreaterThanOrEqual(10, $saleCount, "Vertical {$vertical} must seed sales");
        $this->assertGreaterThanOrEqual(5, $closedShifts, "Vertical {$vertical} must seed historical Z shifts");
        $this->assertSame(1, $openShifts, "Vertical {$vertical} must leave 1 open shift");
        $this->assertNotNull($maxSaleCreatedAt);
        $this->assertLessThanOrEqual(
            now()->timestamp,
            Carbon::parse($maxSaleCreatedAt)->timestamp,
            "Vertical {$vertical} sales must never be in the future"
        );
        $this->assertSame(0, DB::table('electronic_invoices')->count());
    }

    public function test_seed_guards_reject_non_trial_non_admin_double_seed_and_non_empty_store(): void
    {
        $admin = $this->seedActiveTrialLicense('retail');
        $cashier = User::factory()->create(['role' => 'cashier', 'pin' => Hash::make('5678')]);

        // Cashier cannot seed
        $this->actingAsAdmin($cashier)
            ->postJson('/api/system/demo/seed')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ADMIN_ONLY');

        // Non-trial cannot seed
        BusinessSetting::updateOrCreate(['key' => 'license_is_trial'], ['value' => '0']);
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/seed')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEMO_ONLY_FOR_TRIALS');

        // Store with real product cannot seed
        BusinessSetting::updateOrCreate(['key' => 'license_is_trial'], ['value' => '1']);
        Product::create([
            'name' => 'Producto Real Propio',
            'internal_code' => 'REAL-001',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 5,
            'min_stock' => 1,
            'is_active' => true,
        ]);
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/seed')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'STORE_NOT_EMPTY');

        // Clean product and seed legitimately -> second seed rejects with DEMO_ALREADY_ACTIVE
        DB::table('products')->delete();
        $this->actingAsAdmin($admin)->postJson('/api/system/demo/seed')->assertStatus(200);
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/seed')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DEMO_ALREADY_ACTIVE');
    }

    public function test_arca_invoicing_and_contingency_retry_are_blocked_in_both_testing_and_production_during_demo(): void
    {
        $admin = $this->seedActiveTrialLicense('retail');
        $this->actingAsAdmin($admin)->postJson('/api/system/demo/seed')->assertStatus(200);

        $sale = Sale::firstOrFail();

        foreach (['testing', 'production'] as $env) {
            BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => $env]);

            $res = $this->actingAsAdmin($admin)
                ->postJson("/api/sales/{$sale->id}/invoice", ['voucher_type' => 11]);

            $res->assertStatus(403)
                ->assertJsonPath('error_code', 'DEMO_MODE_ARCA_BLOCKED')
                ->assertJsonPath('success', false);
        }

        $this->assertSame(0, DB::table('electronic_invoices')->count());

        $exitCode = Artisan::call('afip:retry-contingency');
        $this->assertSame(0, $exitCode);
    }

    public function test_settings_update_cannot_forge_demo_flags(): void
    {
        $admin = $this->seedActiveTrialLicense('retail');

        $this->actingAsAdmin($admin)
            ->putJson('/api/settings', [
                'demo_mode_active' => '1',
                'demo_seeded_at' => '2026-01-01T00:00:00Z',
                'demo_onboarding_dismissed' => '1',
                'is_fresh_empty_store' => '0',
                'store_name' => 'Kiosco Mi Barrio',
            ])
            ->assertStatus(200);

        $this->assertSame('0', BusinessSetting::where('key', 'demo_mode_active')->value('value') ?? '0');
        $this->assertNull(BusinessSetting::where('key', 'demo_seeded_at')->value('value'));
        $this->assertSame('Kiosco Mi Barrio', BusinessSetting::where('key', 'store_name')->value('value'));
    }

    public function test_reset_locks_reject_real_store_wrong_pin_bad_text_non_admin_and_fiscal_rows(): void
    {
        $admin = $this->seedActiveTrialLicense('retail');
        $cashier = User::factory()->create(['role' => 'cashier', 'pin' => Hash::make('9999')]);

        // Candado 1: real store without demo_mode_active
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/reset', [
                'admin_pin' => '1234',
                'confirmation_text' => 'LIMPIAR',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEMO_MODE_NOT_ACTIVE');

        // Seed demo
        $this->actingAsAdmin($admin)->postJson('/api/system/demo/seed')->assertStatus(200);

        // Candado 2a: non-admin
        $this->actingAsAdmin($cashier)
            ->postJson('/api/system/demo/reset', [
                'admin_pin' => '9999',
                'confirmation_text' => 'LIMPIAR',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ADMIN_ONLY');

        // Candado 2b: wrong confirmation text
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/reset', [
                'admin_pin' => '1234',
                'confirmation_text' => 'BORRAR',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_CONFIRMATION_TEXT');

        // Candado 2c: wrong admin PIN
        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/reset', [
                'admin_pin' => '0000',
                'confirmation_text' => 'LIMPIAR',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');

        // Candado 3: fiscal record inserted manually blocks reset
        $sale = Sale::firstOrFail();
        DB::table('electronic_invoices')->insert([
            'sale_id' => $sale->id,
            'voucher_type' => 11,
            'voucher_letter' => 'C',
            'point_of_sale' => 5,
            'voucher_number' => 1,
            'cae' => '74123456789012',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'doc_type' => 99,
            'doc_number' => '0',
            'net_amount' => 100,
            'iva_amount' => 0,
            'total_amount' => 100,
            'status' => 'authorized',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($admin)
            ->postJson('/api/system/demo/reset', [
                'admin_pin' => '1234',
                'confirmation_text' => 'LIMPIAR',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DEMO_RESET_BLOCKED_FISCAL_DATA');
    }

    public function test_reset_succeeds_even_after_early_conversion_to_paid_lifetime_and_preserves_base_config(): void
    {
        $admin = $this->seedActiveTrialLicense('butcher');
        $cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Cajero Real']);

        $this->actingAsAdmin($admin)->postJson('/api/system/demo/seed')->assertStatus(200);

        $this->assertGreaterThan(0, DB::table('product_batches')->count());
        $this->assertGreaterThan(0, DB::table('sale_item_batch_allocations')->count());
        $this->assertGreaterThan(0, DB::table('third_party_checks')->count());

        // Simular conversión temprana del cliente a Lifetime pago antes de tocar "Limpiar"
        $observedBefore = BusinessSetting::where('key', 'license_max_observed_at')->value('value');
        BusinessSetting::updateOrCreate(['key' => 'license_is_trial'], ['value' => '0']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_type'], ['value' => 'lifetime']);

        $res = $this->actingAsAdmin($admin)->postJson('/api/system/demo/reset', [
            'admin_pin' => '1234',
            'confirmation_text' => 'limpiar',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('result.cleaned', true)
            ->assertJsonPath('status.demo_mode_active', false)
            ->assertJsonPath('status.demo_onboarding_dismissed', true);

        $snapshotFile = $res->json('result.snapshot_file');
        $this->assertNotEmpty($snapshotFile);
        $this->assertFileExists(storage_path('app/private/backups/'.$snapshotFile));

        // Tablas operacionales en 0
        foreach ([
            'products',
            'sales',
            'sale_items',
            'sale_payments',
            'cash_shifts',
            'cash_movements',
            'customers',
            'customer_transactions',
            'suppliers',
            'supplier_invoices',
            'supplier_invoice_items',
            'product_batches',
            'sale_item_batch_allocations',
            'third_party_checks',
            'stock_movements',
            'offers',
            'quotes',
            'delivery_notes',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Table {$table} should be empty after demo reset");
        }

        // Entidades base preservadas y categorías de gasto re-sembradas
        $this->assertSame(2, User::count());
        $this->assertNotNull(User::find($cashier->id));
        $this->assertNotNull(CashRegister::find(1));
        $this->assertGreaterThan(0, PaymentMethod::count());
        $this->assertGreaterThan(0, ExpenseCategory::count());
        $this->assertSame('80', BusinessSetting::where('key', 'printer_paper_width')->value('value'));
        $this->assertSame('lifetime', BusinessSetting::where('key', 'license_plan_type')->value('value'));
        $this->assertSame($observedBefore, BusinessSetting::where('key', 'license_max_observed_at')->value('value'));
        $this->assertSame('0', BusinessSetting::where('key', 'license_clock_anchor_sale_id')->value('value'));
        $this->assertSame('0', BusinessSetting::where('key', 'license_clock_anchor_shift_id')->value('value'));
    }
}
