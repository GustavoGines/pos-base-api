<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PermissionsV4Test extends TestCase
{
    use RefreshDatabase;

    protected function actingAsCashier(?User $user = null, array $permissions = []): User
    {
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('1111'),
                'permissions' => $permissions,
            ]);
        }

        $token = 'cashier-token-' . uniqid();
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

    // =========================================================================
    // 1. User Model Permission Helpers
    // =========================================================================

    public function test_user_model_permission_methods(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => []]);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasPermission(Permissions::VOID_SALES));
        $this->assertTrue($admin->hasAnyPermission([Permissions::VOID_SALES, 'non_existent']));

        $userWithAll = User::factory()->create(['role' => 'cashier', 'permissions' => ['all']]);
        $this->assertFalse($userWithAll->isAdmin());
        $this->assertTrue($userWithAll->hasPermission(Permissions::MANAGE_USERS));

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => [Permissions::VIEW_REPORTS, Permissions::APPLY_DISCOUNTS],
        ]);
        $this->assertTrue($cashier->hasPermission(Permissions::VIEW_REPORTS));
        $this->assertTrue($cashier->hasPermission(Permissions::APPLY_DISCOUNTS));
        $this->assertFalse($cashier->hasPermission(Permissions::VOID_SALES));
        $this->assertTrue($cashier->hasAnyPermission([Permissions::VOID_SALES, Permissions::VIEW_REPORTS]));
        $this->assertFalse($cashier->hasAnyPermission([Permissions::VOID_SALES, Permissions::DELETE_CASH_MOVEMENTS]));
    }

    // =========================================================================
    // 2. Void Sale & PIN Authorization Flow
    // =========================================================================

    public function test_void_sale_without_permission_returns_403_pin_required(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();

        $product = Product::create([
            'name' => 'Item 1',
            'internal_code' => 'IT01',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Cliente devolvió mercadería',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    public function test_void_sale_with_invalid_pin_returns_403_invalid_admin_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('4321')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();

        $product = Product::create([
            'name' => 'Item 2',
            'internal_code' => 'IT02',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->withHeader('X-Admin-Pin', '9999')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
    }

    public function test_void_sale_with_valid_admin_pin_authorizes_and_records_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('4321')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();

        $product = Product::create([
            'name' => 'Item 3',
            'internal_code' => 'IT03',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->withHeader('X-Admin-Pin', '4321')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
                'void_reason' => 'Error de cajero',
            ]);

        $response->assertStatus(200);

        $freshSale = $sale->fresh();
        $this->assertEquals('voided', $freshSale->status);
        $this->assertEquals($cashier->id, $freshSale->voided_by_user_id);
        $this->assertEquals($admin->id, $freshSale->void_authorized_by_admin_id);
        $this->assertEquals('Error de cajero', $freshSale->void_reason);
        $this->assertNotNull($freshSale->voided_at);
    }

    public function test_void_sale_with_support_user_pin_bypasses_visible_global_scope(): void
    {
        $supportAdmin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('7890'),
            'is_system' => true, // hidden by global scope 'visible'
        ]);

        $shift = $this->crearTurnoAbierto();
        $cashier = $this->actingAsCashier();

        $product = Product::create([
            'name' => 'Item 4',
            'internal_code' => 'IT04',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->withHeader('X-Admin-Pin', '7890')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals($supportAdmin->id, $sale->fresh()->void_authorized_by_admin_id);
    }

    public function test_rate_limiter_blocks_pin_attempts_after_5_failures(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('4321')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();

        $product = Product::create([
            'name' => 'Item 5',
            'internal_code' => 'IT05',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        // 5 wrong attempts
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->withHeader('X-Admin-Pin', '0000')
                ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shift->id]);
            $resp->assertStatus(403)
                ->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }

        // 6th attempt is throttled
        $throttled = $this->withHeader('X-Admin-Pin', '0000')
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shift->id]);

        $throttled->assertStatus(429)
            ->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');
    }

    public function test_cashier_with_direct_void_permission_needs_no_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: [Permissions::VOID_SALES]);

        $product = Product::create([
            'name' => 'Item 6',
            'internal_code' => 'IT06',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 10,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Direct void without PIN',
        ]);

        $response->assertStatus(200);
        $this->assertEquals($cashier->id, $sale->fresh()->voided_by_user_id);
        $this->assertNull($sale->fresh()->void_authorized_by_admin_id);
    }

    // =========================================================================
    // 3. POS Wholesale Discount Protection
    // =========================================================================

    public function test_pos_sale_rejects_unauthorized_price_discount(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Item Discount',
            'internal_code' => 'DISC01',
            'selling_price' => 1000.00,
            'cost_price' => 500.00,
            'stock' => 50,
            'active' => true,
        ]);

        // Trying to sell at 800 instead of 1000 without permission or pin
        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 800.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 800.00,
                    'subtotal' => 800.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 800.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 800.00,
                ],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT');
    }

    public function test_pos_sale_with_admin_discount_pin_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('5555')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier();
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Item Discount 2',
            'internal_code' => 'DISC02',
            'selling_price' => 1000.00,
            'cost_price' => 500.00,
            'stock' => 50,
            'active' => true,
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'discount_pin' => '5555',
            'total' => 800.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 800.00,
                    'subtotal' => 800.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 800.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 800.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    public function test_pos_sale_with_apply_discounts_permission_succeeds_without_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: [Permissions::APPLY_DISCOUNTS]);
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Item Discount 3',
            'internal_code' => 'DISC03',
            'selling_price' => 1000.00,
            'cost_price' => 500.00,
            'stock' => 50,
            'active' => true,
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 850.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 850.00,
                    'subtotal' => 850.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 850.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 850.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    // =========================================================================
    // 4. User Management Permissions Validation
    // =========================================================================

    public function test_user_creation_validates_permissions_dictionary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Invalid permission
        $responseInvalid = $this->actingAsAdmin($admin)->postJson('/api/users', [
            'name' => 'Test Operator',
            'role' => 'cashier',
            'pin' => '9876',
            'permissions' => ['invalid_fake_permission'],
        ]);

        $responseInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);

        // Valid permissions
        $responseValid = $this->actingAsAdmin($admin)->postJson('/api/users', [
            'name' => 'Test Operator 2',
            'role' => 'cashier',
            'pin' => '9875',
            'permissions' => [Permissions::MANAGE_CUSTOMERS, Permissions::VIEW_REPORTS],
        ]);

        $responseValid->assertStatus(201)
            ->assertJsonPath('permissions', [Permissions::MANAGE_CUSTOMERS, Permissions::VIEW_REPORTS]);
    }
}
