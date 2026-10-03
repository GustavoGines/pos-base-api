<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\BusinessSetting;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdversarialInSituReviewerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsCashier(?User $user = null, array $permissions = []): User
    {
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('1234'),
                'permissions' => $permissions,
            ]);
        }

        $token = 'cashier-session-' . uniqid();
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $user;
    }

    private function crearVentaCompletada(Product $product, float $qty, User $user, CashShift $shift): Sale
    {
        $metodo = $this->crearMetodoEfectivo();

        $sale = Sale::create([
            'total' => $product->selling_price * $qty,
            'total_surcharge' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $shift->id,
            'user_id' => $user->id,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $qty,
            'unit_price' => $product->selling_price,
            'unit_cost_price' => $product->cost_price,
            'subtotal' => $product->selling_price * $qty,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $metodo->id,
            'base_amount' => $product->selling_price * $qty,
            'surcharge_amount' => 0,
            'total_amount' => $product->selling_price * $qty,
        ]);

        return $sale;
    }

    /**
     * ADVERSARIAL TEST 1:
     * Si un cajero y un admin poseen el mismo PIN ('4321'), y el cajero fue creado
     * primero, authorize-pin DEBE autorizar con éxito identificando al administrador.
     */
    public function test_authorize_pin_succeeds_when_cashier_and_admin_share_pin(): void
    {
        // Cajero creado primero (id menor)
        $cashier = User::factory()->create([
            'name' => 'Cajero Uno',
            'role' => 'cashier',
            'pin' => Hash::make('4321'),
        ]);

        // Administrador creado después (id mayor)
        $admin = User::factory()->create([
            'name' => 'Supervisor Jefe',
            'role' => 'admin',
            'pin' => Hash::make('4321'),
        ]);

        $response = $this->postJson('/api/auth/authorize-pin', [
            'pin' => '4321',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.id', $admin->id);
    }

    /**
     * ADVERSARIAL TEST 2:
     * Array injection en admin_pin no debe causar TypeError 500 en EnsurePermissionOrPin.
     */
    public function test_array_injection_in_admin_pin_in_void_sale_does_not_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('7777')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Producto Prueba',
            'internal_code' => 'PP01',
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        // Envío malicioso de admin_pin como array
        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'admin_pin' => ['7777', 'injected'],
        ]);

        $this->assertNotEquals(500, $response->status(), 'Endpoint should not crash with 500 on array injection in admin_pin');
        $this->assertContains($response->status(), [403, 422]);
    }

    /**
     * ADVERSARIAL TEST 3:
     * Array injection en admin_pin en POS sale no debe causar TypeError 500 en validateItemDiscounts.
     */
    public function test_array_injection_in_pos_sales_does_not_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('8888')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Taladro',
            'internal_code' => 'TL01',
            'selling_price' => 5000.00,
            'cost_price' => 3000.00,
            'stock' => 10,
            'active' => true,
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'admin_pin' => ['8888'],
            'total' => 4500.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 4500.00,
                    'subtotal' => 4500.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 4500.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 4500.00,
                ],
            ],
        ]);

        $this->assertNotEquals(500, $response->status(), 'POS sales should not crash with 500 on array admin_pin');
        $this->assertContains($response->status(), [403, 422]);
    }

    /**
     * ADVERSARIAL TEST 4:
     * Si la cabecera X-Admin-Pin se envía vacía (""), debe caer correctamente al parámetro body admin_pin.
     */
    public function test_empty_header_pin_falls_back_to_body_admin_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('6666')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Clavos 2 Pulgadas',
            'internal_code' => 'CL01',
            'selling_price' => 200.00,
            'cost_price' => 100.00,
            'stock' => 50,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->withHeader('X-Admin-Pin', '')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
                'admin_pin' => '6666',
                'void_reason' => 'Anulación con fallback a body',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('voided', $sale->fresh()->status);
        $this->assertEquals($admin->id, $sale->fresh()->void_authorized_by_admin_id);
    }

    /**
     * ADVERSARIAL TEST 5:
     * Un cajero no puede falsificar authorized_by_admin_id en ajustes de stock manuales.
     */
    public function test_cashier_cannot_spoof_authorized_by_admin_id_in_stock_adjustment(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $cashier = $this->actingAsCashier(permissions: [Permissions::ADJUST_STOCK]);

        $product = Product::create([
            'name' => 'Lija al agua 120',
            'internal_code' => 'LJ120',
            'selling_price' => 50.00,
            'cost_price' => 20.00,
            'stock' => 10,
            'active' => true,
        ]);

        $response = $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'type' => 'in',
            'quantity' => 5,
            'notes' => 'Intento de falsificación de auditoría',
            'authorized_by_admin_id' => $admin->id,
        ]);

        $response->assertStatus(200);
        $movement = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertNull(
            $movement->authorized_by_admin_id,
            'Cashier was unable to spoof authorized_by_admin_id in stock movement'
        );
    }

    /**
     * ADVERSARIAL TEST 6:
     * Un cajero no puede falsificar status_authorized_by_admin_id en cartera de cheques.
     */
    public function test_cashier_cannot_spoof_status_authorized_by_admin_id_in_third_party_check(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['checks' => true])]
        );

        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $cashier = $this->actingAsCashier(permissions: [Permissions::ENDORSE_CHECKS]);

        $customer = Customer::create([
            'name' => 'Constructora Austral',
            'cuit' => '30-77665544-9',
            'phone' => '11223344',
            'active' => true,
        ]);

        $check = ThirdPartyCheck::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Banco Galicia',
            'check_number' => 'CHK-998811',
            'issuer_name' => 'Constructora Austral',
            'issuer_cuit' => '30-77665544-9',
            'amount' => 50000.00,
            'issue_date' => now(),
            'payment_date' => now()->addDays(30),
            'status' => 'in_wallet',
        ]);

        $response = $this->patchJson("/api/third-party-checks/{$check->id}/status", [
            'status' => 'deposited',
            'authorized_by_admin_id' => $admin->id,
        ]);

        $response->assertStatus(200);
        $freshCheck = $check->fresh();
        $this->assertEquals('deposited', $freshCheck->status);
        $this->assertNull(
            $freshCheck->status_authorized_by_admin_id,
            'Cashier was unable to spoof status_authorized_by_admin_id in third party checks'
        );
    }

    /**
     * ADVERSARIAL TEST 7:
     * Retiro de dinero exige PIN_REQUIRED y permite autorización in situ con X-Admin-Pin.
     */
    public function test_cash_withdrawal_requires_pin_with_correct_error_code_and_authorizes_in_situ(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('8888')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: [Permissions::CREATE_EXPENSES]);

        // 1. Petición sin PIN -> 403 con error_code PIN_REQUIRED
        $deniedResponse = $this->postJson('/api/cash-movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'withdrawal',
            'description' => 'Retiro para cambio menor',
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 500.00],
            ],
        ]);

        $deniedResponse->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 2. Reintento in situ adjuntando X-Admin-Pin válido -> 201 Creado
        $authorizedResponse = $this->withHeader('X-Admin-Pin', '8888')
            ->postJson('/api/cash-movements', [
                'cash_shift_id' => $shift->id,
                'type' => 'withdrawal',
                'description' => 'Retiro para cambio menor autorizado',
                'payments' => [
                    ['payment_method' => 'cash', 'amount' => 500.00],
                ],
            ]);

        $authorizedResponse->assertStatus(201);
    }

    /**
     * ADVERSARIAL TEST 8:
     * Retiro de dinero bloquea con 429 PIN_LOCKED_TEMPORARILY tras 5 intentos fallidos.
     */
    public function test_cash_withdrawal_rate_limits_after_5_failed_attempts(): void
    {
        RateLimiter::clear('pin_attempts:guest:127.0.0.1');
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('7777')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: [Permissions::CREATE_EXPENSES]);
        RateLimiter::clear("pin_attempts:{$cashier->id}:127.0.0.1");

        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->withHeader('X-Admin-Pin', '0000')
                ->postJson('/api/cash-movements', [
                    'cash_shift_id' => $shift->id,
                    'type' => 'withdrawal',
                    'payments' => [
                        ['payment_method' => 'cash', 'amount' => 100.00],
                    ],
                ]);
            $resp->assertStatus(403)->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }

        // 6to intento -> 429
        $blockedResp = $this->withHeader('X-Admin-Pin', '0000')
            ->postJson('/api/cash-movements', [
                'cash_shift_id' => $shift->id,
                'type' => 'withdrawal',
                'payments' => [
                    ['payment_method' => 'cash', 'amount' => 100.00],
                ],
            ]);

        $blockedResp->assertStatus(429)->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');
    }

    /**
     * ADVERSARIAL TEST 9:
     * Endpoint /api/auth/authorize-pin bloquea con 429 PIN_LOCKED_TEMPORARILY tras 5 intentos erróneos.
     */
    public function test_authorize_pin_endpoint_rate_limits_after_5_failed_attempts(): void
    {
        $cashier = $this->actingAsCashier(permissions: []);
        RateLimiter::clear("pin_attempts:{$cashier->id}:127.0.0.1");

        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->postJson('/api/auth/authorize-pin', ['pin' => '0000']);
            $resp->assertStatus(401)->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }

        $blocked = $this->postJson('/api/auth/authorize-pin', ['pin' => '0000']);
        $blocked->assertStatus(429)->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');
    }

    /**
     * ADVERSARIAL TEST 10:
     * Un cajero con permiso void_sales no puede falsificar void_authorized_by_admin_id en la anulación.
     */
    public function test_cashier_cannot_spoof_void_authorized_by_admin_id_in_sale_void(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: [Permissions::VOID_SALES]);

        $product = Product::create([
            'name' => 'Tornillos Drywall',
            'internal_code' => 'TD01',
            'selling_price' => 150.00,
            'cost_price' => 80.00,
            'stock' => 100,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 2, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Prueba de ataque de auditoría',
            'void_authorized_by_admin_id' => $admin->id,
            'authorized_by_admin_id' => $admin->id,
        ]);

        $response->assertStatus(200);
        $freshSale = $sale->fresh();
        $this->assertEquals('voided', $freshSale->status);
        $this->assertNull(
            $freshSale->void_authorized_by_admin_id,
            'Cashier was unable to spoof void_authorized_by_admin_id'
        );
    }

    /**
     * ADVERSARIAL TEST 11:
     * Al anular una venta con PIN de admin, el movimiento de restitución de stock
     * debe registrar authorized_by_admin_id en la auditoría del kardex.
     */
    public function test_void_sale_with_admin_pin_records_authorizer_in_restored_stock_movement(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('7777')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Cable Unipolar 2.5mm',
            'internal_code' => 'CB25',
            'selling_price' => 500.00,
            'cost_price' => 300.00,
            'stock' => 50,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 3, $cashier, $shift);

        $response = $this->withHeader('X-Admin-Pin', '7777')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
                'void_reason' => 'Anulación autorizada por supervisor',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('voided', $sale->fresh()->status);
        $this->assertEquals($admin->id, $sale->fresh()->void_authorized_by_admin_id);

        $reversalMovement = StockMovement::where('sale_id', $sale->id)
            ->where('type', 'in')
            ->latest('id')
            ->first();

        $this->assertNotNull($reversalMovement);
        $this->assertEquals($admin->id, $reversalMovement->authorized_by_admin_id);
    }
}
