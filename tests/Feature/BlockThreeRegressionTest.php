<?php

namespace Tests\Feature;

use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceSyncLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Ecommerce\EcommerceManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class BlockThreeRegressionTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'current_accounts' => true,
                'multi_caja' => true,
                'expenses' => true,
            ])]
        );

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
        $this->actingAsAdmin($this->admin);
    }

    /**
     * FINDING-STOCK-03: SyncProductStockToPlatformsJob preserva el stock_before real
     * en EcommerceSyncLog antes de mutar el mapping.
     */
    public function test_sync_product_stock_preserves_stock_before_in_sync_log(): void
    {
        $integration = EcommerceIntegration::create([
            'platform' => 'mercadolibre',
            'name' => 'ML Tienda Test',
            'is_active' => true,
            'sync_stock_enabled' => true,
            'credentials' => ['access_token' => 'test_token'],
        ]);

        $product = Product::create([
            'name' => 'Taladro Percutor 750W',
            'internal_code' => 'TAL-750',
            'cost_price' => 20000.0,
            'selling_price' => 35000.0,
            'stock' => 15.0, // Stock físico actual
            'active' => true,
        ]);

        $mapping = EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA_TAL_750',
            'sync_stock' => true,
            'is_paused' => false,
            'last_known_external_stock' => 25.0, // Stock previo conocido en la plataforma
            'last_known_local_stock' => 25.0,
        ]);

        $job = new SyncProductStockToPlatformsJob($product->id);
        $job->handle(app(EcommerceManager::class));

        // Verificar el log de sincronización registrado
        $log = EcommerceSyncLog::where('mapping_id', $mapping->id)->latest('id')->first();
        $this->assertNotNull($log, 'Debe haberse creado un registro en EcommerceSyncLog');

        // El stock_before debe ser 25.0 (el previo) y el stock_after debe ser 15.0 (el nuevo)
        $this->assertEquals(25.0, (float) $log->stock_before, 'stock_before debe registrar el stock previo antes de la sincronización');
        $this->assertEquals(15.0, (float) $log->stock_after, 'stock_after debe registrar el nuevo stock transmitido');
        $this->assertNotEquals($log->stock_before, $log->stock_after, 'stock_before y stock_after no deben ser idénticos si el stock cambió');
    }

    /**
     * FINDING-LIC-04: EnsureLicenseIsActive bloquea con 403 (LICENSE_BLOCKED)
     * peticiones en planes SaaS vencidos o con gracia > 72h aún sin cron activo.
     */
    public function test_ensure_license_is_active_blocks_expired_saas_offline(): void
    {
        $shift = $this->crearTurnoAbierto(1000.00, $this->admin);

        // Configurar licencia SaaS vencida hace 5 días
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'saas']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_expires_at'],
            ['value' => now()->subDays(5)->toIso8601String()]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'last_license_check'],
            ['value' => now()->subDays(5)->toIso8601String()]
        );

        $product = Product::create([
            'name' => 'Pintura Látex 20L',
            'internal_code' => 'PIN-20L',
            'cost_price' => 10000.0,
            'selling_price' => 18000.0,
            'stock' => 10.0,
            'active' => true,
        ]);

        // Intentar venta en mostrador -> Debe responder 403 LICENSE_BLOCKED
        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 18000.0,
                    'subtotal' => 18000.0,
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 18000.0],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertEquals('LICENSE_BLOCKED', $response->json('error_code'));
    }

    /**
     * FINDING-CASH-02 / FINDING-CASH-04: Cuando existen múltiples cajas abiertas,
     * movimientos y cobros de CC exigen especificar la caja y no hacen fallback ciego.
     */
    public function test_multicaja_requires_explicit_shift_or_register_when_multiple_open(): void
    {
        $reg1 = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $reg2 = CashRegister::create(['name' => 'Caja 2', 'is_active' => true]);

        $shift1 = CashShift::create([
            'cash_register_id' => $reg1->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 5000.0,
            'opened_at' => now(),
        ]);

        $shift2 = CashShift::create([
            'cash_register_id' => $reg2->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 5000.0,
            'opened_at' => now(),
        ]);

        $customer = Customer::create([
            'name' => 'Cliente Cuenta Corriente',
            'document_number' => '20300000001',
            'credit_limit' => 50000.0,
            'balance' => 10000.0,
            'is_active' => true,
        ]);

        // 1. Intento de movimiento de caja sin cash_shift_id ni cash_register_id -> 422
        $movResponse = $this->postJson('/api/cash-movements', [
            'type' => 'expense',
            'amount' => 500.0,
            'category' => 'Limpieza',
            'notes' => 'Compra lavandina',
        ]);
        $movResponse->assertStatus(422);
        $this->assertStringContainsString('múltiples cajas', $movResponse->json('message'));

        // 2. Intento de cobro en CC sin cash_shift_id ni cash_register_id -> 422
        $payResponse = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 2000.0,
            'payment_method' => 'cash',
        ]);
        $payResponse->assertStatus(422)
            ->assertJsonValidationErrors('cash_shift_id');
    }

    /**
     * Post-Audit Fix (FINDING-LIC-04): Si la terminal lleva > 72h sin ejecutar cron pero tiene internet
     * y clave activa, EnsureLicenseIsActive ejecuta syncHeartbeat() en vivo y permite operar sin falso bloqueo.
     */
    public function test_ensure_license_is_active_auto_revalidates_online_when_cron_missed_72h(): void
    {
        $shift = $this->crearTurnoAbierto(1000.00, $this->admin);
        $paymentMethod = $this->crearMetodoEfectivo();

        $this->fakeSignedLicenseServer([
            'status' => 'active',
            'plan' => 'pro',
            'plan_type' => 'saas',
            'business_type' => 'retail',
            'server_time' => now()->toIso8601String(),
            'expires_at' => now()->addMonths(6)->toIso8601String(),
            'features' => ['multi_caja' => true, 'current_accounts' => true],
        ]);

        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => 'POS-LIVE-SYNC-KEY']);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);
        BusinessSetting::updateOrCreate(['key' => 'license_plan_mode'], ['value' => 'saas']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_expires_at'],
            ['value' => now()->addMonths(6)->toIso8601String()]
        );
        // Simular que el cron de Windows no corrió por 80 horas (> 72h)
        BusinessSetting::updateOrCreate(
            ['key' => 'last_license_check'],
            ['value' => now()->subHours(80)->toIso8601String()]
        );

        $product = Product::create([
            'name' => 'Producto Test Renovación Live',
            'internal_code' => 'LIVE-01',
            'cost_price' => 500.0,
            'selling_price' => 1000.0,
            'stock' => 20.0,
            'active' => true,
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'total' => 1000.0,
            'total_surcharge' => 0.0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1000.0,
                    'subtotal' => 1000.0,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $paymentMethod->id,
                    'base_amount' => 1000.0,
                    'surcharge_amount' => 0.0,
                    'total_amount' => 1000.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $updatedCheck = Carbon::parse(BusinessSetting::where('key', 'last_license_check')->value('value'));
        $this->assertLessThan(2, $updatedCheck->diffInHours(now(), true));
    }
}
