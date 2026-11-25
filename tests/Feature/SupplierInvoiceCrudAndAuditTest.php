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

    public function test_purchase_assistant_returns_low_stock_frequent_catalog_and_last_invoice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $supA = Supplier::create(['name' => 'Ferretería Industrial Sudamericana', 'balance' => 0]);
        $supB = Supplier::create(['name' => 'Otro Proveedor', 'balance' => 0]);

        $lowZero = Product::create([
            'name' => 'Disco Corte 115mm',
            'internal_code' => 'DC-115',
            'supplier_id' => $supA->id,
            'cost_price' => 1200,
            'selling_price' => 2000,
            'stock' => 0,
            'min_stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $lowBelowMin = Product::create([
            'name' => 'Tornillo Autoperforante',
            'internal_code' => 'TA-001',
            'supplier_id' => $supA->id,
            'purchase_package_name' => 'Caja',
            'conversion_factor' => 200,
            'cost_price' => 32,
            'selling_price' => 55,
            'stock' => 15,
            'min_stock' => 30,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $catalogOnly = Product::create([
            'name' => 'Llave Combinada 13mm',
            'internal_code' => 'LL-013',
            'supplier_id' => $supA->id,
            'cost_price' => 4500,
            'selling_price' => 7500,
            'stock' => 50,
            'min_stock' => 5,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $otherSupplierProd = Product::create([
            'name' => 'Producto Proveedor B',
            'internal_code' => 'PB-01',
            'supplier_id' => $supB->id,
            'cost_price' => 900,
            'selling_price' => 1500,
            'stock' => 0,
            'min_stock' => 5,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        // Crear dos compras vigentes para supA + una Nota de Crédito (excluida de frecuentes/last_invoice)
        $this->actingAsAdmin($admin)->postJson("/api/suppliers/{$supA->id}/invoices", [
            'type' => 'invoice',
            'invoice_number' => 'A-0001-00000010',
            'amount' => 12000,
            'issue_date' => '2026-11-10',
            'items' => [
                [
                    'product_id' => $lowBelowMin->id,
                    'quantity' => 200,
                    'unit_cost' => 30,
                    'subtotal' => 6000,
                ],
                [
                    'product_id' => $lowZero->id,
                    'quantity' => 5,
                    'unit_cost' => 1200,
                    'subtotal' => 6000,
                ],
            ],
        ])->assertSuccessful();

        $this->actingAsAdmin($admin)->postJson("/api/suppliers/{$supA->id}/invoices", [
            'type' => 'delivery_note',
            'invoice_number' => 'R-0001-00000011',
            'amount' => 3500,
            'issue_date' => '2026-11-20',
            'items' => [
                [
                    'product_id' => $lowBelowMin->id,
                    'quantity' => 100,
                    'unit_cost' => 35,
                    'subtotal' => 3500,
                ],
            ],
        ])->assertSuccessful();

        // Nota de crédito no debe aparecer como last_invoice ni contar en times_bought
        $this->actingAsAdmin($admin)->postJson("/api/suppliers/{$supA->id}/invoices", [
            'type' => 'credit_note',
            'invoice_number' => 'NC-0001-00000012',
            'amount' => 1000,
            'issue_date' => '2026-11-22',
            'items' => [
                [
                    'product_id' => $lowZero->id,
                    'quantity' => 1,
                    'unit_cost' => 1000,
                    'subtotal' => 1000,
                ],
            ],
        ])->assertSuccessful();

        // Ajustar stocks después de las compras para verificar low_stock
        $lowZero->refresh()->update(['stock' => 0]);
        $lowBelowMin->refresh()->update(['stock' => 15]);

        $res = $this->actingAsAdmin($admin)->getJson("/api/suppliers/{$supA->id}/purchase-assistant");
        $res->assertStatus(200);

        $lowIds = collect($res->json('low_stock_products'))->pluck('id')->all();
        $this->assertContains($lowZero->id, $lowIds);
        $this->assertContains($lowBelowMin->id, $lowIds);
        $this->assertNotContains($catalogOnly->id, $lowIds);
        $this->assertNotContains($otherSupplierProd->id, $lowIds);

        $frequent = collect($res->json('frequent_products'));
        $tornilloFreq = $frequent->firstWhere('id', $lowBelowMin->id);
        $this->assertNotNull($tornilloFreq);
        $this->assertEquals(2, $tornilloFreq['times_bought']);
        $this->assertEquals(35.0, (float) $tornilloFreq['last_unit_cost']);
        $this->assertEquals(100.0, (float) $tornilloFreq['last_quantity']);
        $this->assertEquals('Caja', $tornilloFreq['purchase_package_name']);

        $catalogIds = collect($res->json('catalog_products'))->pluck('id')->all();
        $this->assertContains($catalogOnly->id, $catalogIds);
        $this->assertNotContains($otherSupplierProd->id, $catalogIds);

        $lastInvoice = $res->json('last_invoice');
        $this->assertNotNull($lastInvoice);
        $this->assertEquals('R-0001-00000011', $lastInvoice['invoice_number']);
        $this->assertCount(1, $lastInvoice['items']);
        $this->assertEquals($lowBelowMin->id, $lastInvoice['items'][0]['product_id']);
    }

    public function test_controlled_link_supplier_respects_v185_default_and_explicit_overrides(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supMain = Supplier::create(['name' => 'Proveedor Principal', 'balance' => 0]);
        $supOther = Supplier::create(['name' => 'Proveedor Secundario', 'balance' => 0]);

        $orphanDefault = Product::create([
            'name' => 'Huérfano Default',
            'internal_code' => 'HD-1',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $orphanOptOut = Product::create([
            'name' => 'Huérfano Opt-Out',
            'internal_code' => 'HO-1',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $ownedKeep = Product::create([
            'name' => 'De Otro Keep',
            'internal_code' => 'OK-1',
            'supplier_id' => $supOther->id,
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $ownedReassign = Product::create([
            'name' => 'De Otro Reassign',
            'internal_code' => 'OR-1',
            'supplier_id' => $supOther->id,
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10,
            'active' => true,
            'is_sold_by_weight' => false,
        ]);

        $res = $this->actingAsAdmin($admin)->postJson("/api/suppliers/{$supMain->id}/invoices", [
            'type' => 'delivery_note',
            'amount' => 400,
            'items' => [
                // 1) v1.8.5 legacy (omitido) -> vincula huérfano
                ['product_id' => $orphanDefault->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100],
                // 2) link_supplier: false -> NO vincula huérfano
                ['product_id' => $orphanOptOut->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100, 'link_supplier' => false],
                // 3) omitido en producto de otro -> NO pisa proveedor existente
                ['product_id' => $ownedKeep->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100],
                // 4) link_supplier: true en producto de otro -> reasigna explícitamente
                ['product_id' => $ownedReassign->id, 'quantity' => 1, 'unit_cost' => 100, 'subtotal' => 100, 'link_supplier' => true],
            ],
        ]);

        $res->assertSuccessful();
        $this->assertEquals($supMain->id, $orphanDefault->fresh()->supplier_id);
        $this->assertNull($orphanOptOut->fresh()->supplier_id);
        $this->assertEquals($supOther->id, $ownedKeep->fresh()->supplier_id);
        $this->assertEquals($supMain->id, $ownedReassign->fresh()->supplier_id);
    }
}
