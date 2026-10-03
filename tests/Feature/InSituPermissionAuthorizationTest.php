<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InSituPermissionAuthorizationTest extends TestCase
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
     * Venta con descuento no autorizado falla (403 UNAUTHORIZED_PRICE_DISCOUNT)
     * y tiene éxito in situ cuando se adjunta X-Admin-Pin válido en el header.
     */
    public function test_sale_with_discount_denied_without_permission_and_authorized_in_situ_via_header(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('9999')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Taladro Percutor',
            'internal_code' => 'TL01',
            'selling_price' => 5000.00,
            'cost_price' => 3000.00,
            'stock' => 10,
            'active' => true,
        ]);

        // 1. Intento sin PIN ni permisos -> Denegado 403
        $responseDenied = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
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

        $responseDenied->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT');

        // 2. Reintento in situ con X-Admin-Pin válido -> Autorizado 201
        $responseAuthorized = $this->withHeader('X-Admin-Pin', '9999')
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $shift->id,
                'user_id' => $cashier->id,
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

        $responseAuthorized->assertStatus(201)
            ->assertJsonPath('message', 'Venta registrada correctamente');
    }

    /**
     * Venta con descuento tiene éxito in situ cuando se adjunta admin_pin en el body.
     */
    public function test_sale_with_discount_authorized_in_situ_via_body_admin_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('8888')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);
        $metodo = $this->crearMetodoEfectivo();

        $product = Product::create([
            'name' => 'Amoladora Angular',
            'internal_code' => 'AM01',
            'selling_price' => 3000.00,
            'cost_price' => 1500.00,
            'stock' => 5,
            'active' => true,
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'admin_pin' => '8888',
            'total' => 2500.00,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 2500.00,
                    'subtotal' => 2500.00,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $metodo->id,
                    'base_amount' => 2500.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 2500.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * Anulación de venta denegada para cajero normal y autorizada in situ con PIN.
     */
    public function test_void_sale_denied_without_permission_and_authorized_in_situ(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('7777')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Caja de Tornillos',
            'internal_code' => 'TR01',
            'selling_price' => 500.00,
            'cost_price' => 200.00,
            'stock' => 20,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 2, $cashier, $shift);

        // 1. Intento sin PIN -> 403 PIN_REQUIRED
        $denied = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
        ]);
        $denied->assertStatus(403)
            ->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 2. Reintento con PIN incorrecto -> 403 INVALID_ADMIN_PIN
        $invalid = $this->withHeader('X-Admin-Pin', '0000')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
            ]);
        $invalid->assertStatus(403)
            ->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');

        // 3. Reintento con PIN de administrador válido -> 200 OK y auditoría registrada
        $success = $this->withHeader('X-Admin-Pin', '7777')
            ->postJson("/api/sales/{$sale->id}/void", [
                'cash_shift_id' => $shift->id,
                'void_reason' => 'Autorización in situ en caja',
            ]);

        $success->assertStatus(200);
        $this->assertEquals('voided', $sale->fresh()->status);
        $this->assertEquals($admin->id, $sale->fresh()->void_authorized_by_admin_id);
    }

    /**
     * Anulación de venta autorizada in situ enviando admin_pin en el cuerpo JSON.
     */
    public function test_void_sale_authorized_in_situ_via_body_admin_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('6666')]);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashier = $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Pintura Látex 20L',
            'internal_code' => 'PT01',
            'selling_price' => 8000.00,
            'cost_price' => 4500.00,
            'stock' => 15,
            'active' => true,
        ]);

        $sale = $this->crearVentaCompletada($product, 1, $cashier, $shift);

        $response = $this->postJson("/api/sales/{$sale->id}/void", [
            'cash_shift_id' => $shift->id,
            'admin_pin' => '6666',
            'void_reason' => 'Autorización por body PIN',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('voided', $sale->fresh()->status);
        $this->assertEquals($admin->id, $sale->fresh()->void_authorized_by_admin_id);
    }

    /**
     * Ajuste de stock restringido autorizado in situ con X-Admin-Pin.
     */
    public function test_stock_adjustment_authorized_in_situ_with_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'pin' => Hash::make('5555')]);
        $this->actingAsCashier(permissions: []);

        $product = Product::create([
            'name' => 'Disco de Corte',
            'internal_code' => 'DC01',
            'selling_price' => 200.00,
            'cost_price' => 100.00,
            'stock' => 10,
            'active' => true,
        ]);

        // Sin PIN -> 403
        $this->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
            'quantity' => 5,
            'type' => 'in',
        ])->assertStatus(403)->assertJsonPath('error_code', 'PIN_REQUIRED');

        // Con PIN -> 200 OK
        $this->withHeader('X-Admin-Pin', '5555')
            ->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
                'quantity' => 5,
                'type' => 'in',
            ])->assertStatus(200);

        $this->assertEquals(15, (int) $product->fresh()->stock);
    }
}
