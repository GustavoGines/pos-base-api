<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupplierInvoiceCrudAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'app_plan'],
            ['value' => 'premium']
        );
    }

    private function actingAsSessionUser(User $user): static
    {
        $token = 'test-session-token-' . uniqid();
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);
        return $this;
    }

    public function test_full_supplier_invoice_crud_recalculates_debt_stock_and_audits(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Test',
            'role' => 'admin',
            'pin' => Hash::make('9999'),
        ]);

        $supplier = Supplier::create([
            'name' => 'Proveedor Mayorista SA',
            'balance' => 0,
        ]);

        $productA = Product::create([
            'name' => 'Coca Cola 2.25L',
            'internal_code' => 'P-001',
            'cost_price' => 1000,
            'selling_price' => 1500,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $productB = Product::create([
            'name' => 'Sprite 2.25L',
            'internal_code' => 'P-002',
            'cost_price' => 900,
            'selling_price' => 1400,
            'stock' => 5,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        // 1. Crear factura: 10 un de Producto A a $1200 + $500 flete = $12.500
        $createRes = $this->actingAsAdmin($admin)->postJson("/api/suppliers/{$supplier->id}/invoices", [
            'type' => 'invoice',
            'invoice_number' => 'A-0001-00001234',
            'description' => 'Compra inicial bebidas',
            'amount' => 12500,
            'freight_amount' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'issue_date' => '2026-11-05',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 10,
                    'unit_cost' => 1200,
                    'subtotal' => 12000,
                    'update_price' => true,
                    'new_selling_price' => 1800,
                ],
            ],
        ]);

        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        $this->assertEquals(12500.0, (float) $supplier->fresh()->balance);
        $this->assertEquals(20.0, (float) $productA->fresh()->stock);
        $this->assertEquals(1200.0, (float) $productA->fresh()->cost_price);
        $this->assertEquals(1800.0, (float) $productA->fresh()->selling_price);

        // 2. Editar factura: cambiar Producto A a 6 unidades ($1300 c/u = $7800) y agregar Producto B (4 un a $1000 = $4000), sin flete = $11.800
        $updateRes = $this->actingAsAdmin($admin)->putJson("/api/supplier-invoices/{$invoiceId}", [
            'type' => 'invoice',
            'invoice_number' => 'A-0001-00001234-CORR',
            'description' => 'Compra corregida',
            'amount' => 11800,
            'freight_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'issue_date' => '2026-11-05',
            'edit_reason' => 'Error de carga en cantidades y flete bonificado',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 6,
                    'unit_cost' => 1300,
                    'subtotal' => 7800,
                    'update_price' => false,
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 4,
                    'unit_cost' => 1000,
                    'subtotal' => 4000,
                    'update_price' => false,
                ],
            ],
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.is_edited', true)
            ->assertJsonPath('data.edit_count', 1);

        // Saldo proveedor: 0 + 12500 - 12500 + 11800 = 11800
        $this->assertEquals(11800.0, (float) $supplier->fresh()->balance);
        // Stock Producto A: 10 + 10 (creación) - 10 (reversión) + 6 (nueva) = 16
        $this->assertEquals(16.0, (float) $productA->fresh()->stock);
        $this->assertEquals(1300.0, (float) $productA->fresh()->cost_price);
        // Stock Producto B: 5 + 4 = 9
        $this->assertEquals(9.0, (float) $productB->fresh()->stock);
        $this->assertEquals(1000.0, (float) $productB->fresh()->cost_price);

        // 3. Anular (soft-delete) el comprobante
        $deleteRes = $this->actingAsAdmin($admin)->deleteJson("/api/supplier-invoices/{$invoiceId}", [
            'reason' => 'Factura anulada por proveedor',
        ]);
        $deleteRes->assertStatus(200);

        $this->assertSoftDeleted('supplier_invoices', ['id' => $invoiceId]);
        $this->assertEquals(0.0, (float) $supplier->fresh()->balance);
        $this->assertEquals(10.0, (float) $productA->fresh()->stock);
        $this->assertEquals(5.0, (float) $productB->fresh()->stock);

        // 4. Restaurar comprobante desde Papelera / Historial
        $restoreRes = $this->actingAsAdmin($admin)->postJson("/api/supplier-invoices/{$invoiceId}/restore");
        $restoreRes->assertStatus(200)
            ->assertJsonPath('data.is_deleted', false);

        $this->assertEquals(11800.0, (float) $supplier->fresh()->balance);
        $this->assertEquals(16.0, (float) $productA->fresh()->stock);
        $this->assertEquals(9.0, (float) $productB->fresh()->stock);

        // Verificar que existan los 4 eventos en auditoría (created, updated, deleted, restored)
        $invoice = SupplierInvoice::with('audits')->findOrFail($invoiceId);
        $this->assertCount(4, $invoice->audits);
    }

    public function test_cashier_permission_and_admin_pin_override_for_supplier_invoice_edit(): void
    {
        $admin = User::factory()->create([
            'name' => 'Supervisor General',
            'role' => 'admin',
            'pin' => Hash::make('4321'),
        ]);

        $cashierWithoutPerm = User::factory()->create([
            'name' => 'Cajero Sin Permiso',
            'role' => 'cashier',
            'permissions' => [Permissions::CREATE_SUPPLIER_INVOICE],
        ]);

        $cashierWithPerm = User::factory()->create([
            'name' => 'Cajero Con Permiso',
            'role' => 'cashier',
            'permissions' => [
                Permissions::CREATE_SUPPLIER_INVOICE,
                Permissions::MANAGE_SUPPLIER_INVOICES,
            ],
        ]);

        $supplier = Supplier::create(['name' => 'Proveedor Test', 'balance' => 0]);
        $product = Product::create([
            'name' => 'Producto Test',
            'internal_code' => 'PT-1',
            'cost_price' => 500,
            'selling_price' => 800,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $createRes = $this->actingAsSessionUser($cashierWithoutPerm)
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'invoice',
                'amount' => 1000,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                        'unit_cost' => 500,
                        'subtotal' => 1000,
                    ],
                ],
            ]);
        $createRes->assertSuccessful();
        $invoiceId = $createRes->json('id');

        // Cajero sin manage_supplier_invoices intentando editar sin PIN -> 403
        $forbiddenRes = $this->actingAsSessionUser($cashierWithoutPerm)
            ->putJson("/api/supplier-invoices/{$invoiceId}", [
                'type' => 'invoice',
                'amount' => 1500,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 3,
                        'unit_cost' => 500,
                        'subtotal' => 1500,
                    ],
                ],
            ]);
        $forbiddenRes->assertStatus(403);

        // Cajero sin permiso pero con X-Admin-Pin válido -> 200 y guarda authorized_by_admin_id
        $pinRes = $this->actingAsSessionUser($cashierWithoutPerm)
            ->withHeader('X-Admin-Pin', '4321')
            ->putJson("/api/supplier-invoices/{$invoiceId}", [
                'type' => 'invoice',
                'amount' => 1500,
                'edit_reason' => 'Autorizado por supervisor',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 3,
                        'unit_cost' => 500,
                        'subtotal' => 1500,
                    ],
                ],
            ]);
        $pinRes->assertStatus(200);
        $this->assertDatabaseHas('supplier_invoice_audits', [
            'supplier_invoice_id' => $invoiceId,
            'action' => 'updated',
            'user_id' => $cashierWithoutPerm->id,
            'authorized_by_id' => $admin->id,
        ]);

        // Cajero con manage_supplier_invoices habilitado puede anular directamente sin PIN
        $delRes = $this->actingAsSessionUser($cashierWithPerm)
            ->deleteJson("/api/supplier-invoices/{$invoiceId}", [
                'reason' => 'Anulado por encargado de compras',
            ]);
        $delRes->assertStatus(200);
    }
}
