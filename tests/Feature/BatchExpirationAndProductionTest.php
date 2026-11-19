<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\EcommerceWebhook;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItemBatchAllocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BatchExpirationAndProductionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CashShift $shift;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'session_token' => 'batch-test-admin-token',
        ]);

        $register = CashRegister::create([
            'name' => 'Caja Pastelería',
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
    }

    private function authHeaders(): array
    {
        return ['X-Session-Token' => $this->admin->session_token];
    }

    public function test_fefo_deduction_weighted_cogs_and_exact_void_restoration(): void
    {
        $product = Product::create([
            'name' => 'Crema de Leche 1L',
            'internal_code' => '80001',
            'cost_price' => 1000,
            'selling_price' => 3000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
            'lot_deduction_policy' => 'fefo',
        ]);

        $batchResA = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 2,
            'batch_code' => 'CREMA-VIEJA',
            'expiration_date' => now()->addDays(3)->format('Y-m-d'),
            'unit_cost' => 1000,
        ])->assertCreated();

        $batchResB = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 4,
            'batch_code' => 'CREMA-NUEVA',
            'expiration_date' => now()->addDays(10)->format('Y-m-d'),
            'unit_cost' => 1500,
        ])->assertCreated();

        $batchAId = (int) $batchResA->json('batch.id');
        $batchBId = (int) $batchResB->json('batch.id');

        $product->refresh();
        $this->assertEquals(6.0, (float) $product->stock);
        $this->assertEquals(now()->addDays(3)->format('Y-m-d'), $product->expiration_date?->format('Y-m-d'));

        // Vender 3 unidades: 2 de CREMA-VIEJA ($1000) + 1 de CREMA-NUEVA ($1500) -> costo ponderado $1166.67
        $saleRes = $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'total' => 9000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 3000,
                'subtotal' => 9000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 9000,
                'surcharge_amount' => 0,
                'total_amount' => 9000,
            ]],
        ])->assertCreated();

        $saleId = (int) $saleRes->json('sale.id');
        $product->refresh();
        $this->assertEquals(3.0, (float) $product->stock);
        $this->assertEquals(now()->addDays(10)->format('Y-m-d'), $product->expiration_date?->format('Y-m-d'));

        $batchA = ProductBatch::findOrFail($batchAId);
        $batchB = ProductBatch::findOrFail($batchBId);
        $this->assertEquals(ProductBatch::STATUS_EXHAUSTED, $batchA->status);
        $this->assertEquals(0.0, (float) $batchA->remaining_quantity);
        $this->assertEquals(ProductBatch::STATUS_ACTIVE, $batchB->status);
        $this->assertEquals(3.0, (float) $batchB->remaining_quantity);

        $saleItem = Sale::with('items')->findOrFail($saleId)->items->first();
        $this->assertEquals(1166.67, (float) $saleItem->unit_cost_price);

        // Anular venta: devuelve exactamente 1 al lote B y 2 al lote A, reactivándolo
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/sales/{$saleId}/void", ['reason' => 'Error de cobro', 'cash_shift_id' => $this->shift->id])
            ->assertOk();

        $product->refresh();
        $this->assertEquals(6.0, (float) $product->stock);
        $this->assertEquals(now()->addDays(3)->format('Y-m-d'), $product->expiration_date?->format('Y-m-d'));
        $this->assertEquals(ProductBatch::STATUS_ACTIVE, $batchA->fresh()->status);
        $this->assertEquals(2.0, (float) $batchA->fresh()->remaining_quantity);
        $this->assertEquals(4.0, (float) $batchB->fresh()->remaining_quantity);
    }

    public function test_periodic_sixth_portions_cross_batch_boundary_cleanly_and_restore(): void
    {
        $cake = Product::create([
            'name' => 'Torta Selva Negra',
            'internal_code' => '80002',
            'cost_price' => 12000,
            'selling_price' => 24000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        $lotARes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$cake->id}/batches", [
            'quantity' => 1,
            'batch_code' => 'TORTA-A',
            'expiration_date' => now()->addDays(2)->format('Y-m-d'),
        ])->assertCreated();

        $lotBRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$cake->id}/batches", [
            'quantity' => 1,
            'batch_code' => 'TORTA-B',
            'expiration_date' => now()->addDays(5)->format('Y-m-d'),
        ])->assertCreated();

        $lotAId = (int) $lotARes->json('batch.id');
        $lotBId = (int) $lotBRes->json('batch.id');

        $portion = Product::create([
            'name' => 'Porción Selva Negra (1/6)',
            'internal_code' => '80003',
            'cost_price' => 2004,
            'selling_price' => 4500,
            'stock' => 12,
            'is_combo' => true,
            'active' => true,
        ]);
        $portion->children()->attach($cake->id, ['quantity' => 0.167]);

        // Vender 6 porciones una por una -> Lote A llega a exacto 0.000, Lote B queda intacto en 1.000
        $saleIds = [];
        for ($i = 0; $i < 6; $i++) {
            $res = $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
                'cash_shift_id' => $this->shift->id,
                'total' => 4500,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $portion->id,
                    'quantity' => 1,
                    'unit_price' => 4500,
                    'subtotal' => 4500,
                ]],
                'payments' => [[
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 4500,
                    'surcharge_amount' => 0,
                    'total_amount' => 4500,
                ]],
            ])->assertCreated();
            $saleIds[] = (int) $res->json('sale.id');
        }

        $this->assertEquals(ProductBatch::STATUS_EXHAUSTED, ProductBatch::findOrFail($lotAId)->status);
        $this->assertEquals('0.000', ProductBatch::findOrFail($lotAId)->remaining_quantity);
        $this->assertEquals('1.000', ProductBatch::findOrFail($lotBId)->remaining_quantity);
        $this->assertSame('1.000', $cake->fresh()->stock);

        // Anular las 6 ventas en orden inverso o directo -> Lote A vuelve exactamente a 1.000
        foreach ($saleIds as $sid) {
            $this->withHeaders($this->authHeaders())
                ->postJson("/api/sales/{$sid}/void", ['reason' => 'Test void porciones', 'cash_shift_id' => $this->shift->id])
                ->assertOk();
        }

        $this->assertEquals('1.000', ProductBatch::findOrFail($lotAId)->remaining_quantity);
        $this->assertEquals(ProductBatch::STATUS_ACTIVE, ProductBatch::findOrFail($lotAId)->status);
        $this->assertEquals('1.000', ProductBatch::findOrFail($lotBId)->remaining_quantity);
        $this->assertSame('2.000', $cake->fresh()->stock);
    }

    public function test_deficit_auto_batch_is_created_on_zero_stock_and_reconciled_by_next_batch(): void
    {
        $product = Product::create([
            'name' => 'Tarta Lemon Pie',
            'internal_code' => '80004',
            'cost_price' => 5000,
            'selling_price' => 9500,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        // Mostrador vende 2 tortas aunque todavía no cargaron el horneado del día
        $saleRes = $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'total' => 19000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 9500,
                'subtotal' => 19000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 19000,
                'surcharge_amount' => 0,
                'total_amount' => 19000,
            ]],
        ])->assertCreated();

        $saleId = (int) $saleRes->json('sale.id');
        $this->assertEquals(-2.0, (float) $product->fresh()->stock);

        $deficitBatch = ProductBatch::where('product_id', $product->id)
            ->where('status', ProductBatch::STATUS_DEFICIT)
            ->first();
        $this->assertNotNull($deficitBatch);
        $this->assertEquals(-2.0, (float) $deficitBatch->remaining_quantity);

        // Campana debe alertar venta sin lote respaldatorio
        $alertsRes = $this->withHeaders($this->authHeaders())->getJson('/api/inventory/alerts')->assertOk();
        $deficitAlerts = collect($alertsRes->json('deficit_alerts'));
        $this->assertNotNull($deficitAlerts->firstWhere('product_id', $product->id));

        // Al cargar posteriormente 5 tortas reales, el déficit de -2 se absorbe automáticamente
        $inRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 5,
            'batch_code' => 'LEMON-MANANA',
            'expiration_date' => now()->addDays(3)->format('Y-m-d'),
            'unit_cost' => 5200,
        ])->assertCreated();

        $realBatchId = (int) $inRes->json('batch.id');
        $this->assertEquals(3.0, (float) ProductBatch::findOrFail($realBatchId)->remaining_quantity);
        $this->assertEquals(ProductBatch::STATUS_EXHAUSTED, $deficitBatch->fresh()->status);
        $this->assertEquals(0.0, (float) $deficitBatch->fresh()->remaining_quantity);
        $this->assertEquals(3.0, (float) $product->fresh()->stock);

        // La asignación de la venta ahora apunta al lote real y el Kardex cierra en +3
        $alloc = SaleItemBatchAllocation::where('sale_id', $saleId)->first();
        $this->assertEquals($realBatchId, $alloc->batch_id);
        $this->assertEquals(3.0, round((float) StockMovement::where('product_id', $product->id)->sum('quantity'), 3));
    }

    public function test_expired_batch_guard_and_qr_lote_scanner_block(): void
    {
        $product = Product::create([
            'name' => 'Budín de Limón',
            'internal_code' => '80005',
            'cost_price' => 1500,
            'selling_price' => 3200,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
            'allow_sell_expired' => false,
        ]);

        $expRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 3,
            'batch_code' => 'BUDIN-VENCIDO',
            'expiration_date' => now()->subDays(2)->format('Y-m-d'),
        ])->assertCreated();
        $expiredBatchId = (int) $expRes->json('batch.id');

        // 1. Escanear etiqueta L#<id> del lote vencido alerta y bloquea sin PIN
        $scanRes = $this->withHeaders($this->authHeaders())
            ->getJson('/api/pos/batches/scan?code='.urlencode("L#{$expiredBatchId}"))
            ->assertOk();
        $this->assertTrue((bool) $scanRes->json('is_expired'));
        $this->assertFalse((bool) $scanRes->json('can_sell'));
        $this->assertTrue((bool) $scanRes->json('requires_supervisor_pin'));

        // 2. Intentar cobrar sin PIN cuando solo hay lotes vencidos debe retornar 422
        $nonAdmin = User::factory()->create([
            'role' => 'cashier',
            'pin' => Hash::make('9999'),
            'session_token' => 'batch-cashier-token',
            'permissions' => ['pos'],
        ]);

        $this->withoutHeader('X-Admin-Pin')
            ->withHeader('X-Session-Token', $nonAdmin->session_token)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $this->shift->id,
                'total' => 3200,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'batch_id' => $expiredBatchId,
                    'quantity' => 1,
                    'unit_price' => 3200,
                    'subtotal' => 3200,
                ]],
                'payments' => [[
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 3200,
                    'surcharge_amount' => 0,
                    'total_amount' => 3200,
                ]],
            ])->assertStatus(422);

        // 3. Con PIN de supervisor permite la venta excepcional
        $this->withHeaders([
            'X-Session-Token' => $nonAdmin->session_token,
            'X-Admin-Pin' => '1234',
        ])->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'total' => 3200,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $product->id,
                'batch_id' => $expiredBatchId,
                'quantity' => 1,
                'unit_price' => 3200,
                'subtotal' => 3200,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 3200,
                'surcharge_amount' => 0,
                'total_amount' => 3200,
            ]],
        ])->assertCreated();

        $this->assertEquals(2.0, (float) ProductBatch::findOrFail($expiredBatchId)->remaining_quantity);

        // 4. Si luego ingresa un lote vigente y llega otro cliente, FEFO sin PIN saltea el vencido y usa el vigente
        $freshRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 5,
            'batch_code' => 'BUDIN-FRESCO',
            'expiration_date' => now()->addDays(7)->format('Y-m-d'),
        ])->assertCreated();
        $freshBatchId = (int) $freshRes->json('batch.id');

        $this->withoutHeader('X-Admin-Pin')
            ->withHeader('X-Session-Token', $nonAdmin->session_token)
            ->postJson('/api/pos/sales', [
                'cash_shift_id' => $this->shift->id,
                'total' => 3200,
                'total_surcharge' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 3200,
                    'subtotal' => 3200,
                ]],
                'payments' => [[
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 3200,
                    'surcharge_amount' => 0,
                    'total_amount' => 3200,
                ]],
            ])->assertCreated();

        $this->assertEquals(2.0, (float) ProductBatch::findOrFail($expiredBatchId)->remaining_quantity);
        $this->assertEquals(4.0, (float) ProductBatch::findOrFail($freshBatchId)->remaining_quantity);
    }

    public function test_supplier_invoice_blocks_void_when_batch_consumed_and_restores_intact_from_trash(): void
    {
        \App\Models\BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['suppliers' => true])]
        );

        $supplier = Supplier::create(['name' => 'Molino Central', 'cuit' => '30-55555555-5', 'balance' => 0]);
        $flour = Product::create([
            'name' => 'Harina 0000 25kg',
            'internal_code' => '80006',
            'cost_price' => 10000,
            'selling_price' => 16000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        // Factura 1 (será consumida parcialmente -> NO puede anularse)
        $inv1 = $this->withHeaders($this->authHeaders())->postJson("/api/suppliers/{$supplier->id}/invoices", [
            'type' => 'delivery_note',
            'invoice_number' => 'R-0001-00000100',
            'items' => [[
                'product_id' => $flour->id,
                'quantity' => 5,
                'unit_cost' => 10500,
                'subtotal' => 52500,
                'batch_code' => 'HAR-A',
                'expiration_date' => now()->addDays(45)->format('Y-m-d'),
            ]],
        ])->assertOk();
        $inv1Id = (int) $inv1->json('id');

        // Vender 1 bolsa
        $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'total' => 16000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $flour->id,
                'quantity' => 1,
                'unit_price' => 16000,
                'subtotal' => 16000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 16000,
                'surcharge_amount' => 0,
                'total_amount' => 16000,
            ]],
        ])->assertCreated();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/supplier-invoices/{$inv1Id}", ['reason' => 'Error'])
            ->assertStatus(422);

        // Factura 2 (intacta -> puede anularse y restaurarse desde papelera preservando vencimiento y código)
        $exp2 = now()->addDays(90)->format('Y-m-d');
        $inv2 = $this->withHeaders($this->authHeaders())->postJson("/api/suppliers/{$supplier->id}/invoices", [
            'type' => 'delivery_note',
            'invoice_number' => 'R-0001-00000101',
            'items' => [[
                'product_id' => $flour->id,
                'quantity' => 3,
                'unit_cost' => 11000,
                'subtotal' => 33000,
                'batch_code' => 'HAR-B',
                'expiration_date' => $exp2,
            ]],
        ])->assertOk();
        $inv2Id = (int) $inv2->json('id');
        $this->assertEquals(7.0, (float) $flour->fresh()->stock);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/supplier-invoices/{$inv2Id}", ['reason' => 'Cargada por duplicado'])
            ->assertOk();
        $this->assertEquals(4.0, (float) $flour->fresh()->stock);

        $restoreRes = $this->withHeaders($this->authHeaders())
            ->postJson("/api/trash/supplier_invoices/{$inv2Id}/restore")
            ->assertOk();

        $this->assertEquals(7.0, (float) $flour->fresh()->stock);
        $this->assertEquals('HAR-B', $restoreRes->json('data.items.0.batch_code'));
        $this->assertEquals($exp2, $restoreRes->json('data.items.0.expiration_date'));
    }

    public function test_write_off_waste_report_and_short_shelf_life_alerts(): void
    {
        $muffin = Product::create([
            'name' => 'Muffin Arándanos',
            'internal_code' => '80007',
            'cost_price' => 450,
            'selling_price' => 1200,
            'stock' => 0,
            'vencimiento_dias' => 3,
            'expiry_alert_days' => 15,
            'active' => true,
            'track_batches' => true,
        ]);

        // Lote recién horneado con 3 días de vida útil (daysUntil = 3): NO debe disparar alerta crítica ni warning aún
        $freshRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$muffin->id}/batches", [
            'quantity' => 10,
            'batch_code' => 'MUF-HOY',
            'manufactured_at' => now()->format('Y-m-d'),
            'expiration_date' => now()->addDays(3)->format('Y-m-d'),
            'unit_cost' => 450,
        ])->assertCreated();
        $freshBatchId = (int) $freshRes->json('batch.id');

        $alerts1 = collect($this->withHeaders($this->authHeaders())->getJson('/api/inventory/alerts')->assertOk()->json('expiration_alerts'));
        $matchToday = $alerts1->firstWhere('batch_id', $freshBatchId);
        $this->assertNotNull($matchToday);
        $this->assertEquals('pre_alert', $matchToday['stage_code']);

        // Otro lote que vence hoy (daysUntil = 0) -> expired crítico -> dar de baja 4 unidades como merma
        $expRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$muffin->id}/batches", [
            'quantity' => 4,
            'batch_code' => 'MUF-VENCE-HOY',
            'manufactured_at' => now()->subDays(3)->format('Y-m-d'),
            'expiration_date' => now()->format('Y-m-d'),
            'unit_cost' => 400,
        ])->assertCreated();
        $expBatchId = (int) $expRes->json('batch.id');

        $this->withHeaders($this->authHeaders())->postJson("/api/batches/{$expBatchId}/write-off", [
            'notes' => 'Bandeja reseca vencida al cierre',
        ])->assertOk();

        $this->assertEquals(ProductBatch::STATUS_WRITTEN_OFF, ProductBatch::findOrFail($expBatchId)->status);
        $this->assertEquals(10.0, (float) $muffin->fresh()->stock);

        $wasteReport = $this->withHeaders($this->authHeaders())->getJson('/api/reports/waste')->assertOk();
        $this->assertEquals(4.0, (float) $wasteReport->json('total_units_lost'));
        $this->assertEquals(1600.0, (float) $wasteReport->json('total_cost_lost'));
    }

    public function test_pending_order_recall_reconciles_batch_quantities_and_preserves_allocations(): void
    {
        $product = Product::create([
            'name' => 'Cheesecake Frutos Rojos',
            'internal_code' => '80008',
            'cost_price' => 8000,
            'selling_price' => 15000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        $bRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 5,
            'batch_code' => 'CHEESE-01',
            'expiration_date' => now()->addDays(4)->format('Y-m-d'),
            'unit_cost' => 8000,
        ])->assertCreated();
        $batchId = (int) $bRes->json('batch.id');

        // 1. Dejar orden pendiente por 3 tortas
        $pendingRes = $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'status' => 'pending',
            'total' => 45000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 15000,
                'subtotal' => 45000,
            ]],
        ])->assertCreated();
        $saleId = (int) $pendingRes->json('sale.id');
        $this->assertEquals(2.0, (float) ProductBatch::findOrFail($batchId)->remaining_quantity);

        // 2. Recuperar y cobrar reduciendo a 1 torta (debe devolver 2 al lote y preservar allocación incluso tras items()->delete())
        $this->withHeaders($this->authHeaders())->putJson("/api/sales/{$saleId}/pay", [
            'cash_shift_id' => $this->shift->id,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 15000,
                'subtotal' => 15000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 15000,
                'surcharge_amount' => 0,
                'total_amount' => 15000,
            ]],
        ])->assertOk();

        $this->assertEquals(4.0, (float) ProductBatch::findOrFail($batchId)->remaining_quantity);
        $this->assertEquals(1.0, (float) SaleItemBatchAllocation::where('sale_id', $saleId)
            ->selectRaw('SUM(quantity - restored_quantity) as net')
            ->value('net'));

        // 3. Anular venta final devuelve la última torta -> lote queda en 5.0
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/sales/{$saleId}/void", ['reason' => 'Cancelación final', 'cash_shift_id' => $this->shift->id])
            ->assertOk();
        $this->assertEquals(5.0, (float) ProductBatch::findOrFail($batchId)->remaining_quantity);
    }

    public function test_multilevel_pastry_production_orders_cycle_detection_and_void_guard(): void
    {
        $milk = Product::create([
            'name' => 'Leche Entera 1L',
            'internal_code' => '80009',
            'cost_price' => 1000,
            'selling_price' => 2000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);
        $sugar = Product::create([
            'name' => 'Azúcar 1kg',
            'internal_code' => '80010',
            'cost_price' => 800,
            'selling_price' => 1500,
            'stock' => 10,
            'active' => true,
            'track_batches' => false,
        ]);
        $strawberry = Product::create([
            'name' => 'Frutilla Fresca 1kg',
            'internal_code' => '80011',
            'cost_price' => 4000,
            'selling_price' => 7000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        // Ingreso de lotes de materia prima
        $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$milk->id}/batches", [
            'quantity' => 5,
            'batch_code' => 'LECHE-01',
            'expiration_date' => now()->addDays(6)->format('Y-m-d'),
            'unit_cost' => 1000,
        ])->assertCreated();

        $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$strawberry->id}/batches", [
            'quantity' => 4,
            'batch_code' => 'FRUT-01',
            'expiration_date' => now()->addDays(2)->format('Y-m-d'),
            'unit_cost' => 4000,
        ])->assertCreated();

        // Nivel 1: Semi-elaborado "Crema Pastelera" (rendimiento 2kg = 2L leche + 1kg azúcar)
        $cremaRes = $this->withHeaders($this->authHeaders())->postJson('/api/catalog/products', [
            'name' => 'Crema Pastelera Base',
            'selling_price' => 5000,
            'cost_price' => 100,
            'vencimiento_dias' => 4,
            'track_batches' => true,
            'has_production_recipe' => true,
            'recipe_yield_quantity' => 2,
            'recipe_ingredients' => [
                ['id' => $milk->id, 'quantity' => 2],
                ['id' => $sugar->id, 'quantity' => 1],
            ],
        ])->assertCreated();
        $cremaId = (int) $cremaRes->json('id');

        // Nivel 2: Producto Terminado "Tarta de Frutilla" (1 unidad = 0.5kg Crema Pastelera + 0.5kg Frutilla)
        $tartaRes = $this->withHeaders($this->authHeaders())->postJson('/api/catalog/products', [
            'name' => 'Tarta de Frutilla Entera',
            'selling_price' => 12000,
            'cost_price' => 100,
            'vencimiento_dias' => 3,
            'track_batches' => true,
            'has_production_recipe' => true,
            'recipe_yield_quantity' => 1,
            'recipe_ingredients' => [
                ['id' => $cremaId, 'quantity' => 0.5],
                ['id' => $strawberry->id, 'quantity' => 0.5],
            ],
        ])->assertCreated();
        $tartaId = (int) $tartaRes->json('id');

        // Bloquear dependencia circular: Crema Pastelera no puede usar Tarta de Frutilla como ingrediente
        $this->withHeaders($this->authHeaders())->putJson("/api/catalog/products/{$cremaId}", [
            'recipe_ingredients' => [
                ['id' => $tartaId, 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipe_ingredients']);

        // Producir 2 kg de Crema Pastelera (costo total = 2*1000 + 1*800 = $2800 -> $1400/kg)
        $opCrema = $this->withHeaders($this->authHeaders())->postJson('/api/production-orders', [
            'product_id' => $cremaId,
            'quantity_produced' => 2,
            'batch_code' => 'CREMA-OP1',
        ])->assertCreated();
        $this->assertEquals(1400.0, (float) $opCrema->json('order.unit_cost'));
        $this->assertEquals(3.0, (float) $milk->fresh()->stock);
        $this->assertEquals(9.0, (float) $sugar->fresh()->stock);
        $this->assertEquals(2.0, (float) Product::findOrFail($cremaId)->stock);
        $cremaOrderId = (int) $opCrema->json('order.id');

        // Producir 2 Tartas de Frutilla consumiendo 1kg Crema ($1400) + 1kg Frutilla ($4000) -> $2700/tarta
        $opTarta = $this->withHeaders($this->authHeaders())->postJson('/api/production-orders', [
            'product_id' => $tartaId,
            'quantity_produced' => 2,
            'batch_code' => 'TARTA-OP1',
        ])->assertCreated();
        $tartaOrderId = (int) $opTarta->json('order.id');
        $tartaBatchId = (int) $opTarta->json('order.produced_batch.id');

        $this->assertEquals(2700.0, (float) $opTarta->json('order.unit_cost'));
        $this->assertEquals(1.0, (float) Product::findOrFail($cremaId)->stock);
        $this->assertEquals(3.0, (float) $strawberry->fresh()->stock);
        $this->assertEquals(2.0, (float) Product::findOrFail($tartaId)->stock);

        // No se puede anular la OP de Crema porque su lote ya fue consumido por la OP de Tarta
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/production-orders/{$cremaOrderId}/void", ['reason' => 'Intento invalido'])
            ->assertStatus(422);

        // Escanear etiqueta dinámica de lote válido L#<id> antes de vender
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/pos/batches/scan?code='.urlencode("L#{$tartaBatchId}"))
            ->assertOk()
            ->assertJsonPath('can_sell', true)
            ->assertJsonPath('batch.batch_code', 'TARTA-OP1');

        // Anular OP de Tarta devuelve 1kg a Crema Pastelera y 1kg a Frutilla
        $this->withHeaders($this->authHeaders())
            ->postJson("/api/production-orders/{$tartaOrderId}/void", ['reason' => 'Error de carga'])
            ->assertOk();

        $this->assertEquals(0.0, (float) Product::findOrFail($tartaId)->stock);
        $this->assertEquals(2.0, (float) Product::findOrFail($cremaId)->stock);
        $this->assertEquals(4.0, (float) $strawberry->fresh()->stock);
    }

    public function test_inbound_ecommerce_webhook_deducts_fefo_batches(): void
    {
        $product = Product::create([
            'name' => 'Caja Bombones 250g',
            'internal_code' => '80012',
            'cost_price' => 3000,
            'selling_price' => 6500,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        $resBatch = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$product->id}/batches", [
            'quantity' => 4,
            'batch_code' => 'BOMB-WEB',
            'expiration_date' => now()->addDays(20)->format('Y-m-d'),
        ])->assertCreated();
        $batchId = (int) $resBatch->json('batch.id');

        $integration = EcommerceIntegration::create([
            'platform' => EcommerceIntegration::PLATFORM_TIENDANUBE,
            'name' => 'Tienda Nube Test',
            'store_url' => 'https://tienda.test',
            'is_active' => true,
            'sync_stock' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'platform' => EcommerceIntegration::PLATFORM_TIENDANUBE,
            'external_product_id' => 'EXT-80012',
            'external_variant_id' => '0',
            'sync_stock' => true,
            'last_known_local_stock' => 4,
        ]);

        $webhook = EcommerceWebhook::create([
            'integration_id' => $integration->id,
            'platform' => EcommerceIntegration::PLATFORM_TIENDANUBE,
            'event_type' => 'order/paid',
            'external_event_id' => 'EVT-BATCH-001',
            'payload' => [
                'order_id' => 'TN-9001',
                'products' => [[
                    'product_id' => 'EXT-80012',
                    'variant_id' => '0',
                    'quantity' => 2,
                ]],
            ],
            'status' => 'received',
        ]);

        (new ProcessInboundEcommerceWebhookJob($webhook->id))
            ->handle(app(\App\Services\Ecommerce\EcommerceManager::class), app(StockService::class));

        $this->assertEquals(2.0, (float) ProductBatch::findOrFail($batchId)->remaining_quantity);
        $this->assertEquals(2.0, (float) $product->fresh()->stock);
    }

    public function test_unmigrated_legacy_v185_schema_resilience_prevents_sql_42s02_errors(): void
    {
        // Simular base de datos legacy v1.8.5 (sin tablas product_recipe_items ni product_batches)
        $m2 = require base_path('database/migrations/2026_11_16_000002_create_production_orders_and_recipes_tables.php');
        $m1 = require base_path('database/migrations/2026_11_16_000001_create_product_batches_and_lot_tracking_tables.php');
        $m2->down();
        $m1->down();
        \App\Services\BatchStockService::flushSchemaCache();

        try {
            $this->assertFalse(\App\Services\BatchStockService::hasLotTrackingSchema());
            $this->assertFalse(\App\Services\BatchStockService::hasProductionRecipeSchema());

            // 1. Crear producto enviando flags nuevos desde el frontend -> debe guardarse sin error SQL
            $createRes = $this->withHeaders($this->authHeaders())->postJson('/api/catalog/products', [
                'name' => 'Producto Legacy v1.8.5',
                'internal_code' => '80999',
                'cost_price' => 1000,
                'selling_price' => 2000,
                'stock' => 10,
                'active' => true,
                'track_batches' => true,
                'has_production_recipe' => true,
                'expiration_date' => now()->addDays(5)->format('Y-m-d'),
            ])->assertCreated();
            $prodId = (int) $createRes->json('id');

            // 2. Listar catálogo (GET /api/catalog/products) -> no debe lanzar SQLSTATE[42S02]
            $this->withHeaders($this->authHeaders())
                ->getJson('/api/catalog/products')
                ->assertOk();

            // 3. Stock bulk polling (GET /api/catalog/products/stock)
            $this->withHeaders($this->authHeaders())
                ->getJson("/api/catalog/products/stock?ids={$prodId}")
                ->assertOk();

            // 4. Alertas de inventario (GET /api/inventory/alerts)
            $this->withHeaders($this->authHeaders())
                ->getJson('/api/inventory/alerts')
                ->assertOk();

            // 4b. Nuevos endpoints de agilidad cotidiana en esquema v1.8.5 sin migrar (nunca 500 SQLSTATE)
            $this->withHeaders($this->authHeaders())
                ->getJson('/api/batches/expired?include_today=1')
                ->assertOk()
                ->assertJsonPath('expired_count', 0);

            $this->withHeaders($this->authHeaders())
                ->getJson('/api/production-orders')
                ->assertOk();

            $this->withHeaders($this->authHeaders())
                ->postJson('/api/batches/write-off-expired', ['include_today' => true])
                ->assertStatus(503);

            // 5. Ajuste manual de stock y venta POS
            $this->withHeaders($this->authHeaders())
                ->postJson("/api/catalog/products/{$prodId}/adjust-stock", [
                    'type' => 'in',
                    'quantity' => 5,
                    'notes' => 'Ajuste en esquema legacy',
                ])
                ->assertOk();

            $this->withHeaders($this->authHeaders())
                ->postJson('/api/pos/sales', [
                    'cash_shift_id' => $this->shift->id,
                    'status' => 'completed',
                    'total' => 4000,
                    'total_surcharge' => 0,
                    'items' => [[
                        'product_id' => $prodId,
                        'quantity' => 2,
                        'unit_price' => 2000,
                        'subtotal' => 4000,
                    ]],
                    'payments' => [[
                        'payment_method_id' => $this->cashMethod->id,
                        'base_amount' => 4000,
                        'surcharge_amount' => 0,
                        'total_amount' => 4000,
                    ]],
                ])
                ->assertCreated();
        } finally {
            $m1->up();
            $m2->up();
            \App\Services\BatchStockService::flushSchemaCache();
        }
    }

    public function test_batch_production_orders_topological_order_and_shared_fefo_inputs(): void
    {
        $sugar = Product::create([
            'name' => 'Azúcar Refinada 1kg',
            'internal_code' => '81001',
            'cost_price' => 1000,
            'selling_price' => 1800,
            'stock' => 0,
            'active' => true,
            'unit_type' => 'kg',
            'is_sold_by_weight' => true,
            'track_batches' => true,
        ]);

        $cream = Product::create([
            'name' => 'Crema Pastelera Base',
            'internal_code' => '81002',
            'cost_price' => 2500,
            'selling_price' => 4500,
            'stock' => 0,
            'active' => true,
            'unit_type' => 'kg',
            'is_sold_by_weight' => true,
            'track_batches' => true,
            'vencimiento_dias' => 4,
            'has_production_recipe' => true,
            'recipe_yield_quantity' => 2.0,
        ]);

        $tart = Product::create([
            'name' => 'Tarta Frutal Matutina',
            'internal_code' => '81003',
            'cost_price' => 5000,
            'selling_price' => 12000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
            'vencimiento_dias' => 3,
            'has_production_recipe' => true,
            'recipe_yield_quantity' => 1.0,
        ]);

        \App\Models\ProductRecipeItem::create([
            'parent_product_id' => $cream->id,
            'ingredient_product_id' => $sugar->id,
            'quantity' => 0.500,
            'waste_pct' => 0,
        ]);
        \App\Models\ProductRecipeItem::create([
            'parent_product_id' => $tart->id,
            'ingredient_product_id' => $cream->id,
            'quantity' => 0.500,
            'waste_pct' => 0,
        ]);
        \App\Models\ProductRecipeItem::create([
            'parent_product_id' => $tart->id,
            'ingredient_product_id' => $sugar->id,
            'quantity' => 0.250,
            'waste_pct' => 0,
        ]);

        // Dos lotes de azúcar: lote 1 (1.0 kg a $900) vence antes que lote 2 (5.0 kg a $1200)
        $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$sugar->id}/batches", [
            'quantity' => 1.0,
            'batch_code' => 'AZ-VIEJA',
            'expiration_date' => now()->addDays(5)->format('Y-m-d'),
            'unit_cost' => 900,
        ])->assertCreated();
        $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$sugar->id}/batches", [
            'quantity' => 5.0,
            'batch_code' => 'AZ-NUEVA',
            'expiration_date' => now()->addDays(40)->format('Y-m-d'),
            'unit_cost' => 1200,
        ])->assertCreated();

        // Se envía Tarta ANTES que Crema Pastelera en el JSON:
        // El backend debe ordenar topológicamente Crema Pastelera primero para que Tarta consuma la Crema recién producida.
        $res = $this->withHeaders($this->authHeaders())->postJson('/api/production-orders/batch', [
            'notes' => 'Horneada matutina 06:30',
            'items' => [
                ['product_id' => $tart->id, 'quantity_produced' => 4],
                ['product_id' => $cream->id, 'quantity_produced' => 4],
            ],
        ])->assertCreated();

        $this->assertEquals(2, $res->json('total_orders'));
        // Crema produjo 4 kg, Tarta consumió 4 * 0.5 = 2 kg -> quedan 2 kg de Crema
        $this->assertEquals(2.0, (float) $cream->fresh()->stock);
        // Tartas terminadas = 4
        $this->assertEquals(4.0, (float) $tart->fresh()->stock);
        // Azúcar inicial = 6 kg; Crema consumió 1 kg (4/2 * 0.5) + Tarta consumió 1 kg (4 * 0.25) = 2 kg -> quedan 4 kg
        $this->assertEquals(4.0, (float) $sugar->fresh()->stock);
        $this->assertEquals('exhausted', ProductBatch::where('batch_code', 'AZ-VIEJA')->firstOrFail()->status);
    }

    public function test_split_batch_freezer_to_display_case_preserves_total_stock_and_prioritizes_fefo(): void
    {
        $marquise = Product::create([
            'name' => 'Marquise Chocolate',
            'internal_code' => '81004',
            'cost_price' => 7000,
            'selling_price' => 16000,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
            'vencimiento_dias' => 4,
        ]);

        // Lote grande en freezer con vencimiento a 60 días
        $freezerExp = now()->addDays(60)->format('Y-m-d');
        $freezerRes = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$marquise->id}/batches", [
            'quantity' => 10,
            'batch_code' => 'FRZ-01',
            'expiration_date' => $freezerExp,
            'unit_cost' => 6800,
        ])->assertCreated();
        $freezerBatchId = (int) $freezerRes->json('batch.id');

        // Retiramos 3 tortas a vitrina con vencimiento corto (hoy + 4 días automático)
        $displayExp = now()->addDays(4)->format('Y-m-d');
        $splitRes = $this->withHeaders($this->authHeaders())->postJson("/api/batches/{$freezerBatchId}/split", [
            'quantity' => 3,
        ])->assertCreated();

        $displayBatchId = (int) $splitRes->json('batch.id');
        $this->assertEquals('FRZ-01-V1', $splitRes->json('batch.batch_code'));
        $this->assertEquals($displayExp, $splitRes->json('batch.expiration_date'));
        $this->assertEquals(7.0, (float) ProductBatch::findOrFail($freezerBatchId)->remaining_quantity);
        $this->assertEquals(3.0, (float) ProductBatch::findOrFail($displayBatchId)->remaining_quantity);

        // Invariante: el stock total del producto no cambia (10), y products.expiration_date adopta el corto de vitrina
        $marquise->refresh();
        $this->assertEquals(10.0, (float) $marquise->stock);
        $this->assertEquals($displayExp, $marquise->expiration_date?->format('Y-m-d'));

        // Vender 2 tortas en caja POS -> FEFO debe descontar primero del sub-lote de vitrina, dejando freezer en 7
        $this->withHeaders($this->authHeaders())->postJson('/api/pos/sales', [
            'cash_shift_id' => $this->shift->id,
            'status' => 'completed',
            'total' => 32000,
            'total_surcharge' => 0,
            'items' => [[
                'product_id' => $marquise->id,
                'quantity' => 2,
                'unit_price' => 16000,
                'subtotal' => 32000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'base_amount' => 32000,
                'surcharge_amount' => 0,
                'total_amount' => 32000,
            ]],
        ])->assertCreated();

        $this->assertEquals(1.0, (float) ProductBatch::findOrFail($displayBatchId)->remaining_quantity);
        $this->assertEquals(7.0, (float) ProductBatch::findOrFail($freezerBatchId)->remaining_quantity);
    }

    public function test_bulk_expired_batches_sweep_writes_off_and_updates_waste_report(): void
    {
        $sandwich = Product::create([
            'name' => 'Sandwich de Miga Triple',
            'internal_code' => '81005',
            'cost_price' => 1200,
            'selling_price' => 2800,
            'stock' => 0,
            'active' => true,
            'track_batches' => true,
        ]);

        // Lote ya vencido ayer (4 u.) + lote que vence hoy (3 u.) + lote vigente (+5 días, 10 u.)
        $bOld = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$sandwich->id}/batches", [
            'quantity' => 4,
            'batch_code' => 'MIG-AYER',
            'expiration_date' => now()->subDay()->format('Y-m-d'),
            'unit_cost' => 1100,
        ])->assertCreated()->json('batch.id');

        $bToday = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$sandwich->id}/batches", [
            'quantity' => 3,
            'batch_code' => 'MIG-HOY',
            'expiration_date' => now()->format('Y-m-d'),
            'unit_cost' => 1200,
        ])->assertCreated()->json('batch.id');

        $bFresh = $this->withHeaders($this->authHeaders())->postJson("/api/catalog/products/{$sandwich->id}/batches", [
            'quantity' => 10,
            'batch_code' => 'MIG-FRESCO',
            'expiration_date' => now()->addDays(5)->format('Y-m-d'),
            'unit_cost' => 1300,
        ])->assertCreated()->json('batch.id');

        // Preview de vencidos (incluyendo los que vencen hoy al cierre)
        $preview = $this->withHeaders($this->authHeaders())
            ->getJson('/api/batches/expired?include_today=1')
            ->assertOk();
        $this->assertEquals(1, $preview->json('expired_count'));
        $this->assertEquals(1, $preview->json('expiring_today_count'));
        $this->assertEquals(7.0, (float) $preview->json('total_units'));

        // Barrido masivo en 1 clic
        $sweep = $this->withHeaders($this->authHeaders())
            ->postJson('/api/batches/write-off-expired', [
                'include_today' => true,
                'notes' => 'Cierre de vitrina nocturno',
            ])
            ->assertOk();

        $this->assertEquals(2, $sweep->json('written_off_count'));
        $this->assertEquals(7.0, (float) $sweep->json('total_units_lost'));
        $this->assertEquals(8000.0, (float) $sweep->json('total_cost_lost')); // 4*1100 + 3*1200

        $this->assertEquals(ProductBatch::STATUS_WRITTEN_OFF, ProductBatch::findOrFail($bOld)->status);
        $this->assertEquals(ProductBatch::STATUS_WRITTEN_OFF, ProductBatch::findOrFail($bToday)->status);
        $this->assertEquals(ProductBatch::STATUS_ACTIVE, ProductBatch::findOrFail($bFresh)->status);
        $this->assertEquals(10.0, (float) $sandwich->fresh()->stock);

        $waste = $this->withHeaders($this->authHeaders())->getJson('/api/reports/waste')->assertOk();
        $this->assertEquals(8000.0, (float) $waste->json('total_cost_lost'));
    }

    public function test_batch_production_orders_accept_and_persist_vencimiento_dias(): void
    {
        $cake = Product::create([
            'name' => 'Budín de Limón Artesanal',
            'internal_code' => '81099',
            'cost_price' => 1500,
            'selling_price' => 4200,
            'stock' => 0,
            'active' => true,
            'track_batches' => false,
            'vencimiento_dias' => null,
        ]);

        $res = $this->withHeaders($this->authHeaders())->postJson('/api/production-orders/batch', [
            'manufactured_at' => now()->format('Y-m-d'),
            'items' => [[
                'product_id' => $cake->id,
                'quantity_produced' => 5,
                'vencimiento_dias' => 6,
            ]],
        ])->assertCreated();

        $expectedExp = now()->addDays(6)->format('Y-m-d');
        $this->assertEquals($expectedExp, $res->json('orders.0.produced_batch.expiration_date'));
        $this->assertEquals(6, (int) $cake->fresh()->vencimiento_dias);
        $this->assertEquals($expectedExp, $cake->fresh()->expiration_date?->format('Y-m-d'));
    }
}
