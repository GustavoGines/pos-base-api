<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PermissionsV4SecurityChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable all licensed features for test environment
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'suppliers' => true,
                'expenses' => true,
                'quotes' => true,
                'multi_caja' => true,
                'checks' => true,
            ])]
        );
    }

    protected function actingAsCashierWithToken(?User $user = null, array $permissions = []): array
    {
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('1234'),
                'permissions' => $permissions,
            ]);
        }

        $token = 'test-token-' . uniqid();
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return [$user, $token];
    }

    private function createProduct(array $attributes = []): Product
    {
        static $code = 100;
        $code++;

        return Product::create(array_merge([
            'name' => 'Product ' . $code,
            'internal_code' => 'P' . $code,
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock' => 50,
            'active' => true,
        ], $attributes));
    }

    private function createSampleSale(Product $product, float $qty, User $user, CashShift $shift): Sale
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
    // T1: Unauthenticated & Unauthorized Void Attacks
    // =========================================================================

    public function test_t1_void_unauthenticated_request_is_rejected_401(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $product = $this->createProduct(['selling_price' => 100, 'cost_price' => 50, 'stock' => 10]);
        $sale = $this->createSampleSale($product, 1, $admin, $shift);

        // No session token -> intercepted by session.validate / EnsurePermissionOrPin
        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
        ]);

        $response->assertStatus(401);
        $this->assertTrue(in_array($response->json('error_code'), ['SESSION_MISSING', 'UNAUTHENTICATED']));
    }

    public function test_t1_void_without_permission_and_without_pin_is_rejected_403_pin_required(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);

        $product = $this->createProduct(['selling_price' => 100, 'cost_price' => 50, 'stock' => 10]);
        $sale = $this->createSampleSale($product, 1, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Testing unauthorized void',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    // =========================================================================
    // T2: Legitimate Void with Permission & Audit Spoofing Check
    // =========================================================================

    public function test_t2_cashier_with_void_sales_permission_voids_successfully(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: [Permissions::VOID_SALES]);

        $product = $this->createProduct(['selling_price' => 100, 'cost_price' => 50, 'stock' => 10]);
        $sale = $this->createSampleSale($product, 1, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Product returned by customer',
        ]);

        $response->assertStatus(200);
        $freshSale = $sale->fresh();
        $this->assertEquals('voided', $freshSale->status);
        $this->assertEquals($cashier->id, $freshSale->voided_by_user_id);
        $this->assertNull($freshSale->void_authorized_by_admin_id);
    }

    public function test_t2_adversarial_cashier_cannot_spoof_authorized_by_admin_id_in_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: [Permissions::VOID_SALES]);

        $product = $this->createProduct(['selling_price' => 100, 'cost_price' => 50, 'stock' => 10]);
        $sale = $this->createSampleSale($product, 1, $cashier, $shift);

        // Adversary cashier passes authorized_by_admin_id in body trying to frame the admin
        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'void_reason' => 'Spoofing test',
            'authorized_by_admin_id' => $admin->id,
        ]);

        $response->assertStatus(200);
        $freshSale = $sale->fresh();

        // REMEDIATED: In SalesController.php, $request->input('authorized_by_admin_id') was removed.
        // Therefore, client-supplied authorized_by_admin_id in payload is ignored and cannot spoof the authorizer.
        $this->assertNull(
            $freshSale->void_authorized_by_admin_id,
            'REMEDIATED: Cashier without PIN was unable to spoof authorized_by_admin_id via request body'
        );
    }

    // =========================================================================
    // T3: Void with Valid PIN & Exact Admin Attribution
    // =========================================================================

    public function test_t3_void_with_supervisor_pin_records_exact_authorizing_admin(): void
    {
        $admin1 = User::factory()->create(['name' => 'Admin One', 'role' => 'admin', 'pin' => Hash::make('1111')]);
        $admin2 = User::factory()->create(['name' => 'Admin Two', 'role' => 'admin', 'pin' => Hash::make('2222')]);
        $shift = $this->crearTurnoAbierto(user: $admin1);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);

        $product = $this->createProduct(['selling_price' => 200, 'cost_price' => 100, 'stock' => 5]);
        $sale = $this->createSampleSale($product, 1, $cashier, $shift);

        // Authorize with Admin Two's PIN
        $response = $this->withHeader('X-Admin-Pin', '2222')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
                'void_reason' => 'Authorized by Admin Two',
            ]);

        $response->assertStatus(200);
        $freshSale = $sale->fresh();
        $this->assertEquals('voided', $freshSale->status);
        $this->assertEquals($cashier->id, $freshSale->voided_by_user_id);
        $this->assertEquals($admin2->id, $freshSale->void_authorized_by_admin_id);
        $this->assertNotEquals($admin1->id, $freshSale->void_authorized_by_admin_id);
    }

    // =========================================================================
    // T4: Brute Force & Compound Rate Limiting Isolation
    // =========================================================================

    public function test_t4_rate_limiter_isolates_by_user_id_and_ip(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('5678')]);
        $shift = $this->crearTurnoAbierto(user: $admin);

        // Terminal 1: Cashier A
        [$cashierA] = $this->actingAsCashierWithToken(permissions: []);
        $product = $this->createProduct(['selling_price' => 50, 'cost_price' => 20, 'stock' => 10]);
        $saleA = $this->createSampleSale($product, 1, $cashierA, $shift);

        // Cashier A makes 5 wrong attempts
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->withHeader('X-Admin-Pin', '0000')
                ->postJson("/api/sales/{$saleA->id}/void", ['cash_shift_id' => $shift->id]);
            $resp->assertStatus(403)->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
        }

        // Cashier A 6th attempt -> 429 Locked
        $lockedA = $this->withHeader('X-Admin-Pin', '0000')
            ->postJson("/api/sales/{$saleA->id}/void", ['cash_shift_id' => $shift->id]);
        $lockedA->assertStatus(429)->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');

        // Terminal 2: Cashier B on the SAME server IP
        [$cashierB] = $this->actingAsCashierWithToken(permissions: []);
        $saleB = $this->createSampleSale($product, 1, $cashierB, $shift);

        // Cashier B attempts with wrong PIN -> should get 403, NOT 429
        $respB = $this->withHeader('X-Admin-Pin', '0000')
            ->postJson("/api/sales/{$saleB->id}/void", ['cash_shift_id' => $shift->id]);
        $respB->assertStatus(403)->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');

        // Cashier B attempts with valid PIN -> should succeed 200 OK!
        $successB = $this->withHeader('X-Admin-Pin', '5678')
            ->postJson("/api/sales/{$saleB->id}/void", ['cash_shift_id' => $shift->id]);
        $successB->assertStatus(200);
    }

    public function test_t4_successful_pin_resets_rate_limiter_attempts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('8888')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);

        $product = $this->createProduct(['selling_price' => 50, 'cost_price' => 20, 'stock' => 10]);
        $sale1 = $this->createSampleSale($product, 1, $cashier, $shift);

        // 4 wrong attempts (1 away from lockout)
        for ($i = 0; $i < 4; $i++) {
            $this->withHeader('X-Admin-Pin', '0000')
                ->postJson("/api/sales/{$sale1->id}/void", ['cash_shift_id' => $shift->id])
                ->assertStatus(403);
        }

        // 5th attempt is valid PIN -> succeeds and clears throttle
        $this->withHeader('X-Admin-Pin', '8888')
            ->postJson("/api/sales/{$sale1->id}/void", ['cash_shift_id' => $shift->id])
            ->assertStatus(200);

        // Now cashier creates another sale
        $sale2 = $this->createSampleSale($product, 1, $cashier, $shift);

        // 4 more wrong attempts should still return 403 (not 429) because throttle was cleared
        for ($i = 0; $i < 4; $i++) {
            $this->withHeader('X-Admin-Pin', '0000')
                ->postJson("/api/sales/{$sale2->id}/void", ['cash_shift_id' => $shift->id])
                ->assertStatus(403);
        }
    }

    // =========================================================================
    // T5: Support User (is_system = true) Scope Verification
    // =========================================================================

    public function test_t5_support_user_authorizes_pos_discount(): void
    {
        $supportAdmin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('3344'),
            'is_system' => true,
        ]);

        $shift = $this->crearTurnoAbierto();
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = $this->createProduct([
            'selling_price' => 500.00,
            'cost_price' => 200.00,
            'stock' => 20,
        ]);

        // Attempt discount using support user PIN in discount_pin body
        $responseBodyPin = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'discount_pin' => '3344',
            'total' => 400.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 400.00,
                    'subtotal' => 400.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 400.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 400.00,
                ],
            ],
        ]);

        $responseBodyPin->assertStatus(201);

        // Attempt discount using support user PIN in X-Admin-Pin header
        $responseHeaderPin = $this->withHeader('X-Admin-Pin', '3344')->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 400.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 400.00,
                    'subtotal' => 400.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 400.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 400.00,
                ],
            ],
        ]);

        $responseHeaderPin->assertStatus(201);
    }

    public function test_t4_adversarial_brute_force_on_pos_discount_pin_lacks_rate_limiting(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $shift = $this->crearTurnoAbierto();
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);
        $metodo = $this->crearMetodoEfectivo();
        $product = $this->createProduct(['selling_price' => 500.00, 'cost_price' => 200.00, 'stock' => 20]);

        // Attacker sends 10 consecutive wrong PIN attempts to /api/pos/sales
        $throttled = false;
        for ($i = 0; $i < 10; $i++) {
            $resp = $this->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'discount_pin' => sprintf('%04d', $i),
                'total' => 400.00,
                'total_surcharge' => 0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 400.00,
                        'subtotal' => 400.00,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $metodo->id,
                        'base_amount' => 400.00,
                        'surcharge_amount' => 0,
                        'total_amount' => 400.00,
                    ],
                ],
            ]);

            if ($resp->status() === 429) {
                $throttled = true;
                $resp->assertJsonPath('error_code', 'PIN_LOCKED_TEMPORARILY');
                break;
            }
        }

        // PosController implements RateLimiter, so attempts >= 5 are throttled with 429
        $this->assertTrue($throttled, 'REMEDIATED: /api/pos/sales discount_pin check enforces compound rate limiting!');
    }

    public function test_t5_support_user_pin_on_cash_withdrawal(): void
    {
        $supportAdmin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('6677'),
            'is_system' => true,
        ]);

        $shift = $this->crearTurnoAbierto();
        [$cashier] = $this->actingAsCashierWithToken(permissions: [Permissions::CREATE_EXPENSES]);
        $metodo = $this->crearMetodoEfectivo();

        // Testing cash movement withdrawal with support admin PIN
        $response = $this->withHeader('X-Admin-Pin', '6677')->postJson('/api/cash-movements', [
            'type' => 'withdrawal',
            'amount' => 100.00,
            'concept' => 'Cash withdrawal test for support user PIN',
            'payments' => [
                [
                    'amount' => 100.00,
                    'payment_method' => 'cash',
                ],
            ],
        ]);

        // REMEDIATED: CashMovementController line 130 uses withoutGlobalScope('visible').
        // Therefore, when a support user (is_system = true) enters their valid PIN,
        // it correctly authorizes the withdrawal and returns 201.
        $this->assertEquals(201, $response->status());
        $this->assertEquals(
            'Movimientos registrados exitosamente.',
            $response->json('message'),
            'REMEDIATED: CashMovementController line 130 recognizes support user PIN'
        );
    }

    // =========================================================================
    // T6: POS Wholesale Tiers & Price Alterations
    // =========================================================================

    public function test_t6_wholesale_tier_price_is_allowed_without_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = $this->createProduct([
            'selling_price' => 200.00,
            'cost_price' => 100.00,
            'stock' => 100,
        ]);

        // Price tier: 10+ units sold at $150 each
        ProductPriceTier::create([
            'product_id' => $product->id,
            'min_quantity' => 10,
            'unit_price' => 150.00,
        ]);

        // Cashier sells 10 units at 150.00 each -> matches tier, NO DISCOUNT PERMISSION OR PIN REQUIRED!
        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 1500.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_price' => 150.00,
                    'subtotal' => 1500.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 1500.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 1500.00,
                ],
            ],
        ]);

        $response->assertStatus(201);

        // Now cashier tries to sell 10 units at 140.00 each (< 150 expected) -> MUST BE REJECTED 403
        $responseBelowTier = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 1400.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_price' => 140.00,
                    'subtotal' => 1400.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 1400.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 1400.00,
                ],
            ],
        ]);

        $responseBelowTier->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT')
            ->assertJsonPath('expected_price', 150);

        // Now cashier tries to sell only 5 units at 150.00 (tier requires 10) -> MUST BE REJECTED 403 (expected 200)
        $responseBelowQty = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 750.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_price' => 150.00,
                    'subtotal' => 750.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 750.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 750.00,
                ],
            ],
        ]);

        $responseBelowQty->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT')
            ->assertJsonPath('expected_price', 200);
    }

    public function test_t6_price_markup_higher_than_expected_is_allowed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = $this->createProduct([
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock' => 20,
        ]);

        // Selling at 120.00 (markup above list price)
        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 120.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 120.00,
                    'subtotal' => 120.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 120.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 120.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    public function test_t6_float_precision_tolerance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = $this->createProduct([
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock' => 20,
        ]);

        // Difference is 0.01 ($99.99 vs $100.00) -> tolerated
        $responseTolerated = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 99.99,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 99.99,
                    'subtotal' => 99.99,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 99.99,
                    'surcharge_amount' => 0,
                    'total_amount' => 99.99,
                ],
            ],
        ]);

        $responseTolerated->assertStatus(201);

        // Difference is 0.02 ($99.98 vs $100.00) -> rejected
        $responseRejected = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 99.98,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 99.98,
                    'subtotal' => 99.98,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 99.98,
                    'surcharge_amount' => 0,
                    'total_amount' => 99.98,
                ],
            ],
        ]);

        $responseRejected->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT');
    }

    // =========================================================================
    // T7: 25 Canonical Permissions Whitelist in User Management
    // =========================================================================

    public function test_t7_full_25_permissions_saved_and_updated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $allPermissions = Permissions::all();
        $this->assertCount(25, $allPermissions);

        // 1. Create cashier with all 25 permissions
        $responseCreate = $this->actingAsAdmin($admin)->postJson('/api/users', [
            'name' => 'All Permissions Cashier',
            'role' => 'cashier',
            'pin' => '4455',
            'permissions' => $allPermissions,
        ]);

        $responseCreate->assertStatus(201);
        $userId = $responseCreate->json('id');
        $this->assertEquals($allPermissions, $responseCreate->json('permissions'));

        // 2. Update cashier permissions subset
        $subset = [Permissions::VIEW_REPORTS, Permissions::MANAGE_SETTINGS];
        $responseUpdate = $this->actingAsAdmin($admin)->putJson("/api/users/{$userId}", [
            'name' => 'Updated Permissions Cashier',
            'role' => 'cashier',
            'permissions' => $subset,
        ]);

        $responseUpdate->assertStatus(200);
        $this->assertEquals($subset, $responseUpdate->json('permissions'));
    }

    // =========================================================================
    // Edge Cases: Route Protection Perimeter Across Endpoints
    // =========================================================================

    public function test_perimeter_protected_routes_return_403_without_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: []);

        // 1. Settings update
        $this->putJson('/api/settings', ['company_name' => 'Hacked Inc'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 2. Reports
        $this->getJson('/api/reports/sales-by-category')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 3. Bulk delete products
        $this->postJson('/api/catalog/products/bulk-delete', ['ids' => [1, 2]])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 4. Adjust stock
        $product = $this->createProduct(['selling_price' => 10, 'cost_price' => 5, 'stock' => 10]);
        $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", ['quantity' => 5, 'type' => 'add'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 5. Supplier creation (requires manage_catalog, not view_suppliers)
        $this->postJson('/api/suppliers', ['name' => 'Supplier X', 'cuit' => '20123456789'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    public function test_cashier_with_view_suppliers_cannot_mutate_suppliers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$cashier] = $this->actingAsCashierWithToken(permissions: [Permissions::VIEW_SUPPLIERS]);

        // Can view suppliers
        $this->getJson('/api/suppliers')
            ->assertStatus(200);

        // CANNOT create supplier (requires manage_catalog)
        $this->postJson('/api/suppliers', [
            'name' => 'Forbidden Supplier',
            'cuit' => '20999999999',
        ])->assertStatus(403)->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    public function test_cashier_with_master_wildcard_all_passes_all_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        [$superCashier] = $this->actingAsCashierWithToken(permissions: ['all']);

        // Can access reports
        $this->getJson('/api/reports/sales-by-category')
            ->assertStatus(200);

        // Can access suppliers list
        $this->getJson('/api/suppliers')
            ->assertStatus(200);
    }
}
