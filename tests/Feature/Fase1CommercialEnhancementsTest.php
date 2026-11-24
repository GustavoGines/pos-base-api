<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Fase1CommercialEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CashShift $shift;

    private PaymentMethod $cashMethod;

    private PaymentMethod $transferMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'session_token' => 'test-commercial-phase1-token',
        ]);

        $register = CashRegister::create([
            'name' => 'Caja Principal',
            'is_active' => true,
        ]);

        $this->shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'opened_at' => now(),
            'opening_balance' => 10000,
            'status' => 'open',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'surcharge_type' => 'none',
            'surcharge_value' => 0,
            'is_cash' => true,
            'is_active' => true,
        ]);

        $this->transferMethod = PaymentMethod::create([
            'name' => 'Transferencia',
            'code' => 'transfer',
            'surcharge_type' => 'none',
            'surcharge_value' => 0,
            'is_cash' => false,
            'is_active' => true,
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'X-Session-Token' => $this->admin->session_token,
        ];
    }

    public function test_temporary_product_sale_omits_stock_deduction_and_persists_in_sale_items(): void
    {
        $catalogProduct = Product::create([
            'name' => 'Martillo Galponero',
            'internal_code' => '00101',
            'cost_price' => 5000,
            'selling_price' => 8000,
            'stock' => 10,
            'active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 11500,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => $catalogProduct->id,
                        'quantity' => 1,
                        'unit_price' => 8000,
                        'subtotal' => 8000,
                    ],
                    [
                        'product_id' => null,
                        'is_temporary' => true,
                        'save_to_catalog' => false,
                        'name' => 'Bolsa de Tornillos Especiales',
                        'custom_details' => 'Rosca madera 2 pulgadas',
                        'quantity' => 1,
                        'unit_price' => 3500,
                        'cost_price' => 2000,
                        'iva_rate' => 21,
                        'subtotal' => 3500,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 11500,
                        'surcharge_amount' => 0,
                        'total_amount' => 11500,
                    ],
                ],
            ]);

        $response->assertCreated();
        $saleData = $response->json('sale');
        $this->assertCount(2, $saleData['items']);

        // El producto de catálogo descontó 1 unidad
        $this->assertEquals(9.0, (float) $catalogProduct->fresh()->stock);
        $this->assertEquals(1, StockMovement::where('sale_id', $saleData['id'])->count());

        // El ítem temporal quedó persistido con product_id = null e is_temporary = true
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleData['id'],
            'product_id' => null,
            'is_temporary' => true,
            'product_name' => 'Bolsa de Tornillos Especiales',
            'custom_details' => 'Rosca madera 2 pulgadas',
            'unit_price' => 3500.00,
            'unit_cost_price' => 2000.00,
        ]);

        \App\Models\BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['logistics' => true])]
        );

        // Generar remito desde Historial de Ventas para una venta mixta (catálogo + ítem temporal)
        // debe filtrar el ítem temporal y no fallar con SQL 23000 por product_id = null
        $remitoFromSaleResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/delivery-notes/from-sale/{$saleData['id']}", [
                'status' => 'pending',
            ]);
        $remitoFromSaleResp->assertCreated();
        $this->assertCount(1, $remitoFromSaleResp->json('items'));
        $this->assertEquals($catalogProduct->id, $remitoFromSaleResp->json('items.0.product_id'));
    }

    public function test_quick_product_with_save_to_catalog_creates_product_with_needs_review_and_zero_stock(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 9000,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => null,
                        'is_temporary' => true,
                        'save_to_catalog' => true,
                        'name' => 'Combo Oferta Mostrador',
                        'cost_price' => 3000,
                        'iva_rate' => 21,
                        'quantity' => 2,
                        'unit_price' => 4500,
                        'subtotal' => 9000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 9000,
                        'surcharge_amount' => 0,
                        'total_amount' => 9000,
                    ],
                ],
            ]);

        $response->assertCreated();
        $saleData = $response->json('sale');

        $createdProduct = Product::where('name', 'Combo Oferta Mostrador')->first();
        $this->assertNotNull($createdProduct);
        $this->assertTrue($createdProduct->needs_catalog_review);
        $this->assertEquals(3000.00, (float) $createdProduct->cost_price);
        $this->assertEquals(4500.00, (float) $createdProduct->selling_price);
        $this->assertEquals(0.0, (float) $createdProduct->stock);
        $this->assertNotEmpty($createdProduct->internal_code);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleData['id'],
            'product_id' => $createdProduct->id,
            'is_temporary' => false,
            'product_name' => 'Combo Oferta Mostrador',
            'quantity' => 2.000,
            'unit_price' => 4500.00,
            'subtotal' => 9000.00,
        ]);
    }

    public function test_quick_product_with_save_to_catalog_preserves_scanned_barcode_and_is_searchable_in_pos(): void
    {
        $scannedEan = '7798765432109';

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 3600,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => null,
                        'is_temporary' => false,
                        'save_to_catalog' => true,
                        'barcode' => $scannedEan,
                        'name' => 'Alfajor Triple Artesanal',
                        'cost_price' => 1100,
                        'iva_rate' => 21,
                        'quantity' => 2,
                        'unit_price' => 1800,
                        'subtotal' => 3600,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 3600,
                        'surcharge_amount' => 0,
                        'total_amount' => 3600,
                    ],
                ],
            ]);

        $response->assertCreated();

        $createdProduct = Product::where('name', 'Alfajor Triple Artesanal')->first();
        $this->assertNotNull($createdProduct);
        $this->assertSame($scannedEan, $createdProduct->barcode);
        $this->assertTrue($createdProduct->needs_catalog_review);

        // Debe aparecer inmediatamente en el buscador del POS con el código escaneado
        $searchResp = $this->withHeaders($this->authHeaders())
            ->getJson("/api/pos/products/search?query={$scannedEan}");
        $searchResp->assertOk();
        $this->assertCount(1, $searchResp->json());
        $this->assertSame($createdProduct->id, $searchResp->json('0.id'));

        // Si otra venta posterior intenta crear un producto rápido con ese mismo barcode ya ocupado,
        // no debe abortar el cobro ni duplicar el EAN activo: hace fallback a EAN-13 interno.
        $secondResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 2000,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => null,
                        'is_temporary' => false,
                        'save_to_catalog' => true,
                        'barcode' => $scannedEan,
                        'name' => 'Otro Producto con EAN Colisionado',
                        'cost_price' => 1000,
                        'iva_rate' => 21,
                        'quantity' => 1,
                        'unit_price' => 2000,
                        'subtotal' => 2000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 2000,
                        'surcharge_amount' => 0,
                        'total_amount' => 2000,
                    ],
                ],
            ]);

        $secondResp->assertCreated();
        $collisionProduct = Product::where('name', 'Otro Producto con EAN Colisionado')->first();
        $this->assertNotNull($collisionProduct);
        $this->assertNotSame($scannedEan, $collisionProduct->barcode);
        $this->assertStringStartsWith('2000000', (string) $collisionProduct->barcode);
    }

    public function test_custom_description_and_details_override_ticket_pdf_while_keeping_product_traceability(): void
    {
        $product = Product::create([
            'name' => 'Pintura Látex Interior 20L',
            'internal_code' => '00202',
            'cost_price' => 25000,
            'selling_price' => 40000,
            'stock' => 5,
            'active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 40000,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'custom_description' => 'Insumos de Pintura y Mantenimiento Edilicio',
                        'custom_details' => 'Según orden de compra #4412',
                        'quantity' => 1,
                        'unit_price' => 40000,
                        'subtotal' => 40000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 40000,
                        'surcharge_amount' => 0,
                        'total_amount' => 40000,
                    ],
                ],
            ]);

        $response->assertCreated();
        $saleId = $response->json('sale.id');

        // El nombre original del catálogo permanece intacto y su stock se descontó a 4
        $this->assertEquals('Pintura Látex Interior 20L', $product->fresh()->name);
        $this->assertEquals(4.0, (float) $product->fresh()->stock);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'product_name' => 'Pintura Látex Interior 20L',
            'custom_description' => 'Insumos de Pintura y Mantenimiento Edilicio',
            'custom_details' => 'Según orden de compra #4412',
        ]);

        // Verificar que la vista de PDF A4 contiene la descripción personalizada
        $saleModel = \App\Models\Sale::with('items.product', 'user', 'cashier', 'customer', 'payments.paymentMethod')->findOrFail($saleId);
        $renderedHtml = view('pdf.ticket_a4', [
            'sale' => $saleModel,
            'settings' => [],
        ])->render();

        $this->assertStringContainsString('Insumos de Pintura y Mantenimiento Edilicio', $renderedHtml);
        $this->assertStringContainsString('Según orden de compra #4412', $renderedHtml);
    }

    public function test_transfer_payment_persists_alias_and_aggregates_breakdown_in_cash_shift(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'bank_transfer_accounts'],
            ['value' => json_encode([
                ['id' => '1', 'bank_name' => 'Banco Galicia', 'alias' => 'FERRETERIA.GALICIA', 'is_default' => true],
                ['id' => '2', 'bank_name' => 'Mercado Pago', 'alias' => 'FERRETERIA.MP', 'is_default' => false],
            ])]
        );

        // Verificar que bank_transfer_accounts se expone en /api/settings
        $settingsResp = $this->withHeaders($this->authHeaders())->getJson('/api/settings');
        $settingsResp->assertOk();
        $this->assertNotEmpty($settingsResp->json('bank_transfer_accounts'));

        $product = Product::create([
            'name' => 'Taladro Percutor',
            'internal_code' => '00303',
            'cost_price' => 30000,
            'selling_price' => 50000,
            'stock' => 10,
            'active' => true,
        ]);

        // Venta con Pago Mixto: $20.000 Efectivo + $30.000 Transferencia a FERRETERIA.GALICIA
        $saleResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 50000,
                'total_surcharge' => 0,
                'cash_shift_id' => $this->shift->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 50000,
                        'subtotal' => 50000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 20000,
                        'surcharge_amount' => 0,
                        'total_amount' => 20000,
                    ],
                    [
                        'payment_method_id' => $this->transferMethod->id,
                        'base_amount' => 30000,
                        'surcharge_amount' => 0,
                        'total_amount' => 30000,
                        'reference_id' => 'OP-998877',
                        'transfer_account_alias' => 'FERRETERIA.GALICIA',
                        'transfer_sender_name' => 'Carlos Gómez',
                    ],
                ],
            ]);

        $saleResp->assertCreated();

        $this->assertDatabaseHas('sale_payments', [
            'payment_method_id' => $this->transferMethod->id,
            'total_amount' => 30000.00,
            'reference_id' => 'OP-998877',
            'transfer_account_alias' => 'FERRETERIA.GALICIA',
            'transfer_sender_name' => 'Carlos Gómez',
        ]);

        // Verificar resumen en vivo del turno (calculateLiveTotals)
        $shiftResp = $this->withHeaders($this->authHeaders())
            ->getJson('/api/shifts/current');

        $shiftResp->assertOk();
        $this->assertEquals(30000.0, (float) $shiftResp->json('transfer_sales'));
        $transferByAlias = $shiftResp->json('transfer_by_alias');
        $this->assertCount(1, $transferByAlias);
        $this->assertEquals('FERRETERIA.GALICIA', $transferByAlias[0]['alias']);
        $this->assertEquals(30000.0, (float) $transferByAlias[0]['total_amount']);
        $this->assertEquals(1, $transferByAlias[0]['count']);
    }

    public function test_optional_cost_price_in_products_and_supplier_delivery_note_traceability(): void
    {
        // 1. Crear producto sin enviar cost_price (debe guardarse con cost_price = 0.00)
        $storeResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/catalog/products', [
                'name' => 'Producto Sin Costo Inicial',
                'selling_price' => 1500,
                'stock' => 20,
                'active' => true,
            ]);

        $storeResp->assertCreated();
        $productId = $storeResp->json('id');
        $this->assertEquals(0.0, (float) $storeResp->json('cost_price'));
        $this->assertEquals(1500.0, (float) $storeResp->json('selling_price'));

        // 2. Si se envía cost_price > 0 y selling_price < cost_price, debe rechazar con 422
        $invalidResp = $this->withHeaders($this->authHeaders())
            ->putJson("/api/catalog/products/{$productId}", [
                'cost_price' => 2000,
                'selling_price' => 1500,
            ]);
        $invalidResp->assertStatus(422)
            ->assertJsonValidationErrors(['selling_price']);

        // 3. Cargar comprobante de proveedor tipo 'delivery_note' (Remito) y verificar previous_cost y new_selling_price
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['suppliers' => true])]
        );

        $supplier = Supplier::create([
            'name' => 'Distribuidora Norte S.A.',
            'cuit' => '30-71122334-9',
            'balance' => 0,
        ]);

        $remitoResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'delivery_note',
                'status' => 'pending',
                'invoice_number' => 'R-0001-00004512',
                'description' => 'Remito de mercadería',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 10,
                        'unit_cost' => 1200,
                        'subtotal' => 12000,
                        'new_selling_price' => 2200,
                    ],
                ],
            ]);

        $remitoResp->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'supplier_id' => $supplier->id,
            'type' => 'delivery_note',
            'invoice_number' => 'R-0001-00004512',
            'amount' => 12000.00,
        ]);

        $this->assertDatabaseHas('supplier_invoice_items', [
            'product_id' => $productId,
            'quantity' => 10.000,
            'previous_cost' => 0.00,
            'unit_cost' => 1200.00,
            'new_selling_price' => 2200.00,
            'subtotal' => 12000.00,
        ]);

        // 4. Consultar historial global de comprobantes de proveedor (Módulo 3)
        $historyResp = $this->withHeaders($this->authHeaders())
            ->getJson('/api/supplier-invoices?type=delivery_note&search=R-0001');

        $historyResp->assertOk();
        $docs = $historyResp->json('data');
        $this->assertCount(1, $docs);
        $this->assertEquals('R-0001-00004512', $docs[0]['invoice_number']);
        $this->assertEquals('delivery_note', $docs[0]['type']);
        $this->assertCount(1, $docs[0]['items']);
        $this->assertEquals(0.0, (float) $docs[0]['items'][0]['previous_cost']);
        $this->assertEquals(1200.0, (float) $docs[0]['items'][0]['unit_cost']);
        $this->assertEquals(2200.0, (float) $docs[0]['items'][0]['new_selling_price']);
        $this->assertEquals(12000.0, (float) $docs[0]['total_amount']);

        // 5. Consultar cuenta corriente del proveedor (/api/suppliers/{id}/current-account)
        $ccResp = $this->withHeaders($this->authHeaders())
            ->getJson("/api/suppliers/{$supplier->id}/current-account");
        $ccResp->assertOk();
        $ccHistory = $ccResp->json('history');
        $this->assertCount(1, $ccHistory);
        $this->assertEquals(12000.0, (float) $ccHistory[0]['amount']);
        $this->assertEquals(12000.0, (float) $ccHistory[0]['running_balance']);

        // 6. Aumento Masivo por Proveedor (activo con Addon suppliers o Plan Premium)
        Product::where('id', $productId)->update(['supplier_id' => $supplier->id]);
        $bulkResp = $this->withHeaders($this->authHeaders())
            ->putJson('/api/catalog/products/bulk-price-update', [
                'percentage' => 10,
                'rounding_rule' => 'none',
                'target_field' => 'selling_price',
                'supplier_id' => $supplier->id,
            ]);
        $bulkResp->assertOk();
        $this->assertEquals(1, $bulkResp->json('updated_count'));
        // 2200 + 10% = 2420
        $this->assertEquals(2420.0, (float) Product::findOrFail($productId)->selling_price);

        // 7. Remito sin valorizar (unit_cost = null) NO debe pisar el cost_price existente (1200.00) a 0.00
        $unpricedRemitoResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'delivery_note',
                'status' => 'pending',
                'invoice_number' => 'R-0001-00004513',
                'description' => 'Remito sin precios',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 5,
                        'unit_cost' => null,
                        'subtotal' => null,
                    ],
                ],
            ]);
        $unpricedRemitoResp->assertOk();
        $this->assertEquals(1200.0, (float) Product::findOrFail($productId)->cost_price);
        $this->assertEquals(35.0, (float) Product::findOrFail($productId)->stock);
    }

    public function test_cascading_expiration_alerts_and_smart_snooze_reactivation(): void
    {
        $expiringProduct = Product::create([
            'name' => 'Yogur Entero 1L',
            'internal_code' => '00901',
            'cost_price' => 900,
            'selling_price' => 1500,
            'stock' => 12,
            'active' => true,
            'expiration_date' => now()->addDays(10)->format('Y-m-d'),
            'expiry_alert_days' => 15,
        ]);

        // 1. Al faltar 10 días (<= 15), aparece en stage 15 ('pre_alert')
        $alertsResp1 = $this->withHeaders($this->authHeaders())
            ->getJson('/api/inventory/alerts');
        $alertsResp1->assertOk();
        $expAlerts1 = collect($alertsResp1->json('expiration_alerts'));
        $match1 = $expAlerts1->firstWhere('product_id', $expiringProduct->id);
        $this->assertNotNull($match1);
        $this->assertEquals(15, $match1['stage']);
        $this->assertEquals('pre_alert', $match1['stage_code']);

        // 2. El usuario pospone (snooze) la alerta hasta el próximo aviso en cascada
        $snoozeResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/inventory/alerts/{$expiringProduct->id}/snooze", [
                'action' => 'snooze_next_stage',
            ]);
        $snoozeResp->assertOk();

        // Mientras siga en stage 15 (faltan 10 días), la alerta está silenciada
        $alertsResp2 = $this->withHeaders($this->authHeaders())
            ->getJson('/api/inventory/alerts');
        $expAlerts2 = collect($alertsResp2->json('expiration_alerts'));
        $this->assertNull($expAlerts2->firstWhere('product_id', $expiringProduct->id));

        // 3. Cuando el producto entra al umbral de <= 7 días (stage 7 < last_stage 15),
        // la alerta se reactiva automáticamente en cascada aunque el snooze siga vigente
        $expiringProduct->update([
            'expiration_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $alertsResp3 = $this->withHeaders($this->authHeaders())
            ->getJson('/api/inventory/alerts');
        $expAlerts3 = collect($alertsResp3->json('expiration_alerts'));
        $match3 = $expAlerts3->firstWhere('product_id', $expiringProduct->id);
        $this->assertNotNull($match3);
        $this->assertEquals(7, $match3['stage']);
        $this->assertEquals('warning_7d', $match3['stage_code']);
    }

    public function test_reports_fiscal_and_non_fiscal_filter_includes_temporary_items(): void
    {
        $catalogProduct = Product::create([
            'name' => 'Taladro Percutor 750W',
            'internal_code' => '00950',
            'cost_price' => 6000,
            'selling_price' => 10000,
            'stock' => 10,
            'active' => true,
        ]);

        // 1. Venta No Fiscal con ítem temporal ($5000)
        $nonFiscalResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $this->shift->id,
                'total' => 5000,
                'total_surcharge' => 0,
                'items' => [
                    [
                        'product_id' => null,
                        'is_temporary' => true,
                        'save_to_catalog' => false,
                        'name' => 'Combo Mostrador No Fiscal',
                        'quantity' => 1,
                        'unit_price' => 5000,
                        'cost_price' => 3000,
                        'subtotal' => 5000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 5000,
                        'surcharge_amount' => 0,
                        'total_amount' => 5000,
                    ],
                ],
            ]);
        $nonFiscalResp->assertCreated();

        // 2. Venta Fiscal ($10000)
        $fiscalResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $this->shift->id,
                'total' => 10000,
                'total_surcharge' => 0,
                'items' => [
                    [
                        'product_id' => $catalogProduct->id,
                        'quantity' => 1,
                        'unit_price' => 10000,
                        'subtotal' => 10000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 10000,
                        'surcharge_amount' => 0,
                        'total_amount' => 10000,
                    ],
                ],
            ]);
        $fiscalResp->assertCreated();
        $fiscalSaleId = $fiscalResp->json('sale.id');
        \App\Models\Sale::where('id', $fiscalSaleId)->update(['invoice_status' => 'invoiced']);

        $today = now()->format('Y-m-d');

        // Reporte Todas ('all') -> 15000
        $allReport = $this->withHeaders($this->authHeaders())
            ->getJson("/api/reports/sales-by-category?start_date={$today}&end_date={$today}&fiscal_filter=all");
        $allReport->assertOk();
        $totalAll = collect($allReport->json('data'))->sum('total_revenue');
        $this->assertEquals(15000.0, (float) $totalAll);

        // Reporte Fiscales ('fiscal') -> 10000
        $fiscalReport = $this->withHeaders($this->authHeaders())
            ->getJson("/api/reports/sales-by-category?start_date={$today}&end_date={$today}&fiscal_filter=fiscal");
        $fiscalReport->assertOk();
        $totalFiscal = collect($fiscalReport->json('data'))->sum('total_revenue');
        $this->assertEquals(10000.0, (float) $totalFiscal);

        // Reporte No Fiscales ('non_fiscal') -> 5000
        $nonFiscalReport = $this->withHeaders($this->authHeaders())
            ->getJson("/api/reports/sales-by-category?start_date={$today}&end_date={$today}&fiscal_filter=non_fiscal");
        $nonFiscalReport->assertOk();
        $totalNonFiscal = collect($nonFiscalReport->json('data'))->sum('total_revenue');
        $this->assertEquals(5000.0, (float) $totalNonFiscal);
    }
}
