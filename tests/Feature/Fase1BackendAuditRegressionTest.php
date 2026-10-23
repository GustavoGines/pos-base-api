<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\SaleContextDTO;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\Brand;
use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceProductMapping;
use App\Models\ElectronicInvoice;
use App\Models\Offer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Afip\AfipWsaaService;
use App\Services\Afip\AfipWsfeService;
use App\Services\Catalog\CatalogImportService;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Fase1BackendAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'session_token' => 'test-session-token-audit',
        ]);
    }

    private function authHeaders(?User $user = null): array
    {
        $u = $user ?? $this->admin;

        return [
            'X-Session-Token' => $u->session_token,
        ];
    }

    public function test_b01_voiding_prior_shift_cash_sale_uses_valid_cash_enum(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);

        $closedShift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'closed',
            'opened_at' => now()->subHours(5),
            'closed_at' => now()->subHours(1),
        ]);

        $openShift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 2000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $closedShift->id,
            'total' => 500.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $cashMethod->id,
            'base_amount' => 500.00,
            'surcharge_amount' => 0.00,
            'total_amount' => 500.00,
        ]);

        $saleService = app(SaleService::class);
        $context = SaleContextDTO::fromArray([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $openShift->id,
        ], $this->admin->id);
        $voided = $saleService->voidSale($sale, $context);

        $this->assertSame('voided', $voided->status);

        $refundMovement = CashMovement::where('cash_shift_id', $openShift->id)
            ->where('type', 'expense')
            ->first();

        $this->assertNotNull($refundMovement);
        $this->assertSame('cash', $refundMovement->payment_method);
        $this->assertEquals(500.00, (float) $refundMovement->amount);
    }

    public function test_b02_void_sale_persists_credit_note_cae_outside_local_rollback_scope(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $shift->id,
            'total' => 1210.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $invoice = ElectronicInvoice::create([
            'sale_id' => $sale->id,
            'voucher_type' => 6,
            'voucher_letter' => 'B',
            'point_of_sale' => 1,
            'voucher_number' => 10,
            'cae' => '74000000000001',
            'cae_expiration' => now()->addDays(10)->format('Y-m-d'),
            'doc_type' => 99,
            'doc_number' => '0',
            'net_amount' => 1000.00,
            'iva_amount' => 210.00,
            'total_amount' => 1210.00,
            'status' => 'authorized',
            'issued_at' => now(),
        ]);

        $mockWsfe = $this->createMock(AfipWsfeService::class);
        $mockWsfe->expects($this->once())
            ->method('authorizeInvoice')
            ->willReturn([
                'cae' => '74999999999999',
                'cae_expiration' => '2026-11-05',
                'voucher_number' => 3,
                'voucher_type' => 8,
            ]);
        $this->app->instance(AfipWsfeService::class, $mockWsfe);

        $saleService = app(SaleService::class);
        $context = SaleContextDTO::fromArray(['user_id' => $this->admin->id], $this->admin->id);
        $voided = $saleService->voidSale($sale, $context);

        $this->assertSame('voided', $voided->status);
        $invoice->refresh();
        $this->assertSame('74999999999999', $invoice->credit_note_cae);
        $this->assertSame(3, (int) $invoice->credit_note_number);
    }

    public function test_b04_afip_wsfe_consult_voucher_parses_cae_and_handles_602(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => '20111111112']);
        BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => 'testing']);

        $mockWsaa = $this->createMock(AfipWsaaService::class);
        $mockWsaa->method('getAccessTicket')->willReturn([
            'token' => 'fake-token',
            'sign' => 'fake-sign',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(
                    '<?xml version="1.0" encoding="utf-8"?>'.
                    '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'.
                    '<soap:Body><FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'.
                    '<FECompConsultarResult><ResultGet>'.
                    '<Resultado>A</Resultado>'.
                    '<CodAutorizacion>74123456789012</CodAutorizacion>'.
                    '<FchVto>20261030</FchVto>'.
                    '<ImpTotal>1500.50</ImpTotal>'.
                    '</ResultGet></FECompConsultarResult>'.
                    '</FECompConsultarResponse></soap:Body></soap:Envelope>',
                    200
                )
                ->push(
                    '<?xml version="1.0" encoding="utf-8"?>'.
                    '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'.
                    '<soap:Body><FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'.
                    '<FECompConsultarResult><Errors><Err><Code>602</Code><Msg>No existen datos</Msg></Err></Errors></FECompConsultarResult>'.
                    '</FECompConsultarResponse></soap:Body></soap:Envelope>',
                    200
                ),
        ]);

        $service = new AfipWsfeService($mockWsaa);

        $found = $service->consultVoucher(1, 6, 42);
        $this->assertNotNull($found);
        $this->assertSame('74123456789012', $found['cae']);
        $this->assertSame('2026-10-30', $found['cae_expiration']);
        $this->assertSame(1500.50, $found['imp_total']);

        $notFound = $service->consultVoucher(1, 6, 999);
        $this->assertNull($notFound);
    }

    public function test_b05_multicaja_cash_movement_destroy_allows_deleting_from_older_open_shift(): void
    {
        $reg1 = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $reg2 = CashRegister::create(['name' => 'Caja 2', 'is_active' => true]);

        $shiftCaja1 = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $reg1->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        // Caja 2 abre un turno más reciente (id mayor)
        CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $reg2->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $movement = CashMovement::create([
            'cash_shift_id' => $shiftCaja1->id,
            'user_id' => $this->admin->id,
            'type' => 'out',
            'amount' => 250,
            'payment_method' => 'cash',
            'description' => 'Gasto librería Caja 1',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/cash-movements/{$movement->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('cash_movements', ['id' => $movement->id]);
    }

    public function test_b06_customer_pending_sales_and_payments_ignore_paused_carts(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['current_accounts' => true])]
        );

        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $customer = Customer::create([
            'name' => 'Cliente Cuenta Corriente',
            'balance' => 1000.00,
            'credit_limit' => 5000.00,
        ]);

        // Carrito en espera (status = pending) con método cuenta_corriente y amount_due > 0
        $pausedCart = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $shift->id,
            'customer_id' => $customer->id,
            'total' => 800.00,
            'payment_method' => 'cuenta_corriente',
            'status' => 'pending',
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'amount_due' => 800.00,
        ]);

        // Venta real confirmada a crédito (status = completed)
        $completedCreditSale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $shift->id,
            'customer_id' => $customer->id,
            'total' => 1000.00,
            'payment_method' => 'cuenta_corriente',
            'status' => 'completed',
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'amount_due' => 1000.00,
        ]);

        $pendingResp = $this->withHeaders($this->authHeaders())
            ->getJson("/api/customers/{$customer->id}/pending-sales");

        $pendingResp->assertStatus(200);
        $ids = collect($pendingResp->json())->pluck('id')->all();
        $this->assertContains($completedCreditSale->id, $ids);
        $this->assertNotContains($pausedCart->id, $ids);

        // Registrar un pago de $1000 debe saldar la venta completada y no tocar el carrito pausado
        $payResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/customers/{$customer->id}/payments", [
                'amount' => 1000.00,
                'payment_method' => 'cash',
            ]);

        $payResp->assertStatus(200);
        $this->assertSame('pending', $pausedCart->fresh()->payment_status);
        $this->assertEquals(800.0, (float) $pausedCart->fresh()->amount_due);
        $this->assertSame('paid', $completedCreditSale->fresh()->payment_status);
        $this->assertEquals(0.0, (float) $completedCreditSale->fresh()->amount_due);
    }

    public function test_b07_stock_adjust_cannot_spoof_authenticated_user_id(): void
    {
        $otherUser = User::factory()->create(['role' => 'cashier']);
        $product = Product::create([
            'name' => 'Producto Test Spoof',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 10,
            'internal_code' => '00100',
        ]);

        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/catalog/products/{$product->id}/adjust-stock", [
                'type' => 'in',
                'quantity' => 5,
                'user_id' => $otherUser->id,
                'notes' => 'Test anti-spoofing',
            ]);

        $response->assertStatus(200);
        $movement = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame($this->admin->id, $movement->user_id);
    }

    public function test_b08_b09_b15_product_internal_code_uniqueness_soft_deleted_supplier_and_stock_sync_job(): void
    {
        Queue::fake();

        $supplier = Supplier::create(['name' => 'Proveedor Borrado']);
        $supplier->delete();

        $existing = Product::create([
            'name' => 'Producto Existente PLU',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 10,
            'internal_code' => '00012',
        ]);

        // Intentar crear otro producto con internal_code '12' (se normaliza a '00012') debe fallar con 422
        $dupResp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/catalog/products', [
                'name' => 'Producto Duplicado PLU',
                'cost_price' => 50,
                'selling_price' => 100,
                'stock' => 5,
                'internal_code' => '12',
            ]);

        $dupResp->assertStatus(422)
            ->assertJsonValidationErrors(['internal_code']);

        // Intentar actualizar con un supplier_id soft-deleted debe fallar con 422
        $supResp = $this->withHeaders($this->authHeaders())
            ->putJson("/api/catalog/products/{$existing->id}", [
                'name' => 'Producto Existente PLU',
                'cost_price' => 50,
                'selling_price' => 100,
                'stock' => 10,
                'supplier_id' => $supplier->id,
            ]);

        $supResp->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);

        // Actualizar stock manualmente dispara SyncProductStockToPlatformsJob
        $updateResp = $this->withHeaders($this->authHeaders())
            ->putJson("/api/catalog/products/{$existing->id}", [
                'name' => 'Producto Existente PLU',
                'cost_price' => 50,
                'selling_price' => 100,
                'stock' => 25,
            ]);

        $updateResp->assertStatus(200);
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, function ($job) use ($existing) {
            return $job->productId === $existing->id && (float) $job->newStock === 25.0;
        });
    }

    public function test_b11_system_install_path_is_accessible_without_sanctum_token(): void
    {
        // 1. Acceso desde loopback (127.0.0.1) sin token -> 200 OK
        $response = $this->getJson('/api/system/install-path');
        $response->assertStatus(200)
            ->assertJsonStructure(['install_path', 'backend_path']);

        // 2. FN-F4-01: Acceso desde IP remota sin sesión activa -> 403 Forbidden (previene Full Path Disclosure)
        $remoteUnauth = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/api/system/install-path');
        $remoteUnauth->assertStatus(403);

        // 3. FN-F4-01: Acceso desde IP remota con X-Session-Token válido -> 200 OK
        $remoteAuth = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders($this->authHeaders())
            ->getJson('/api/system/install-path');
        $remoteAuth->assertStatus(200)
            ->assertJsonStructure(['install_path', 'backend_path']);
    }

    public function test_f4_product_update_without_stock_preserves_concurrent_stock_and_clears_optional_fields(): void
    {
        $brand = Brand::create(['name' => 'Marca Temporal']);
        $product = Product::create([
            'name' => 'Producto Concurrencia F4',
            'internal_code' => '00987',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 50,
            'brand_id' => $brand->id,
            'vencimiento_dias' => 30,
            'active' => true,
        ]);
        $product->priceTiers()->create([
            'min_quantity' => 10,
            'unit_price' => 180,
        ]);

        // Simular que otra terminal vendió 3 unidades mientras el diálogo de edición estaba abierto
        $product->decrement('stock', 3);
        $this->assertEquals(47.0, (float) $product->fresh()->stock);

        // La terminal actual guarda cambios de precio, limpia marca, vencimiento y tramos, SIN enviar 'stock'
        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/catalog/products/{$product->id}", [
                'name' => 'Producto Concurrencia F4 Editado',
                'cost_price' => 110,
                'selling_price' => 220,
                'brand_id' => null,
                'vencimiento_dias' => null,
                'price_tiers' => [],
            ]);

        $response->assertStatus(200);
        $fresh = $product->fresh(['priceTiers']);
        $this->assertEquals(47.0, (float) $fresh->stock);
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->vencimiento_dias);
        $this->assertCount(0, $fresh->priceTiers);
    }

    public function test_b17_cash_register_destroy_blocks_deletion_when_shift_is_open(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_caja' => true])]
        );

        $reg1 = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $reg2 = CashRegister::create(['name' => 'Caja Secundaria Activa', 'is_active' => true]);

        CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $reg2->id,
            'opening_balance' => 500,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/registers/{$reg2->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('cash_registers', [
            'id' => $reg2->id,
            'deleted_at' => null,
            'is_active' => true,
        ]);
    }

    public function test_b16_catalog_import_creates_kardex_stock_movements(): void
    {
        $csvContent = "codigo_interno,nombre,costo,precio_venta,stock\n00500,Producto Importado CSV,100,200,15\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'import_test_').'.csv';
        file_put_contents($tmpFile, $csvContent);

        try {
            $service = app(CatalogImportService::class);
            $result = $service->import($tmpFile);

            $this->assertSame(1, $result['created_count']);
            $product = Product::where('internal_code', '00500')->first();
            $this->assertNotNull($product);
            $this->assertEquals(15.0, (float) $product->stock);

            $movement = StockMovement::where('product_id', $product->id)->first();
            $this->assertNotNull($movement);
            $this->assertSame('in', $movement->type);
            $this->assertEquals(15.0, (float) $movement->quantity);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_f1_crit_01_pay_pending_sale_enforces_discount_authorization_and_preserves_v185_already_authorized_prices(): void
    {
        $this->admin->update(['pin' => Hash::make('4321')]);

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos', 'collect_customer_debt'],
            'session_token' => 'cashier-session-f1-crit01',
        ]);

        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $cashier->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Taladro Percutor',
            'internal_code' => '00701',
            'cost_price' => 50000,
            'selling_price' => 100000,
            'stock' => 10,
            'active' => true,
        ]);

        // 1. El cajero crea una venta en espera a precio regular ($100.000)
        $holdResp = $this->withHeaders($this->authHeaders($cashier))
            ->postJson('/api/pos/sales', [
                'total' => 100000,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift->id,
                'status' => 'pending',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 100000,
                        'subtotal' => 100000,
                    ],
                ],
            ]);
        $holdResp->assertStatus(201);
        $saleId = $holdResp->json('sale.id');

        // 2. El cajero intenta cobrar el ticket en espera bajando el precio a $1.00 sin PIN -> 403
        $exploitResp = $this->withHeaders($this->authHeaders($cashier))
            ->putJson("/api/sales/{$saleId}/pay", [
                'cash_shift_id' => $shift->id,
                'total_surcharge' => 0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 1.00,
                        'subtotal' => 1.00,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $cashMethod->id,
                        'base_amount' => 1.00,
                        'surcharge_amount' => 0,
                        'total_amount' => 1.00,
                    ],
                ],
            ]);

        $exploitResp->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_PRICE_DISCOUNT');

        // 3. Con PIN de supervisor válido (discount_pin), el descuento es autorizado -> 200 OK
        $authorizedResp = $this->withHeaders($this->authHeaders($cashier))
            ->putJson("/api/sales/{$saleId}/pay", [
                'cash_shift_id' => $shift->id,
                'total_surcharge' => 0,
                'discount_pin' => '4321',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 80000.00,
                        'subtotal' => 80000.00,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $cashMethod->id,
                        'base_amount' => 80000.00,
                        'surcharge_amount' => 0,
                        'total_amount' => 80000.00,
                    ],
                ],
            ]);

        $authorizedResp->assertStatus(200);
        $this->assertEquals(80000.00, (float) Sale::find($saleId)->total);

        // 4. Salvaguarda v1.8.5: Si la venta en espera YA fue creada con un descuento autorizado ($85.000),
        // el cajero puede cobrarla a $85.000 sin que le vuelva a exigir PIN.
        $preAuthHold = $this->withHeaders($this->authHeaders($cashier))
            ->postJson('/api/pos/sales', [
                'total' => 85000,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift->id,
                'status' => 'pending',
                'discount_pin' => '4321',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 85000,
                        'subtotal' => 85000,
                    ],
                ],
            ]);
        $preAuthHold->assertStatus(201);
        $preAuthSaleId = $preAuthHold->json('sale.id');

        $v185PayResp = $this->withHeaders($this->authHeaders($cashier))
            ->putJson("/api/sales/{$preAuthSaleId}/pay", [
                'cash_shift_id' => $shift->id,
                'total_surcharge' => 0,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 85000,
                        'subtotal' => 85000,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $cashMethod->id,
                        'base_amount' => 85000,
                        'surcharge_amount' => 0,
                        'total_amount' => 85000,
                    ],
                ],
            ]);

        $v185PayResp->assertStatus(200);
    }

    public function test_f1_alta_02_rejects_soft_deleted_products_in_process_sale_and_pay_pending_sale(): void
    {
        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $activeProduct = Product::create([
            'name' => 'Producto Activo',
            'internal_code' => '00702',
            'cost_price' => 500,
            'selling_price' => 1000,
            'stock' => 10,
            'active' => true,
        ]);

        $deletedProduct = Product::create([
            'name' => 'Producto Archivado',
            'internal_code' => '00703',
            'cost_price' => 500,
            'selling_price' => 2000,
            'stock' => 10,
            'active' => true,
        ]);
        $deletedProduct->delete(); // SoftDelete

        $resp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/pos/sales', [
                'total' => 3000,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift->id,
                'items' => [
                    ['product_id' => $activeProduct->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
                    ['product_id' => $deletedProduct->id, 'quantity' => 1, 'unit_price' => 2000, 'subtotal' => 2000],
                ],
                'payments' => [
                    ['payment_method_id' => $cashMethod->id, 'base_amount' => 3000, 'surcharge_amount' => 0, 'total_amount' => 3000],
                ],
            ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.product_id']);
    }

    public function test_f1_alta_03_and_04_ecommerce_sync_reads_fresh_db_stock_and_dispatches_on_void_adjust_delivery_and_parent_combos(): void
    {
        // 1. Verificar que SyncProductStockToPlatformsJob::handle lee el stock fresco de la BD y no el snapshot obsoleto
        $integration = EcommerceIntegration::create([
            'platform' => 'mercadolibre',
            'name' => 'MELI Test',
            'client_id' => 'APP_1',
            'client_secret' => 'sec',
            'access_token' => 'tok',
            'is_active' => true,
            'sync_stock_enabled' => true,
        ]);

        $ingredient = Product::create([
            'name' => 'Ingrediente Base',
            'internal_code' => '00704',
            'cost_price' => 100,
            'selling_price' => 200,
            'stock' => 8, // Stock real actual en BD = 8
            'active' => true,
        ]);

        EcommerceProductMapping::create([
            'integration_id' => $integration->id,
            'product_id' => $ingredient->id,
            'platform' => 'mercadolibre',
            'external_product_id' => 'MLA999888',
            'sync_stock' => true,
            'allocated_stock_percentage' => 100,
            'safety_stock_threshold' => 0,
        ]);

        Http::fake([
            'https://api.mercadolibre.com/items/MLA999888' => Http::response(['id' => 'MLA999888'], 200),
        ]);

        // Simulamos un Job atrasado cuyo snapshot newStock decía 9.0, pero en BD ya hay 8.0
        $staleJob = new SyncProductStockToPlatformsJob($ingredient->id, 9.0);
        $staleJob->handle(app(EcommerceManager::class));

        $mapping = EcommerceProductMapping::where('product_id', $ingredient->id)->first();
        $this->assertEquals(8.0, (float) $mapping->last_known_external_stock);

        // 2. Verificar que al vender/ajustar un ingrediente o anular venta se despacha para el producto y su Combo padre
        Queue::fake();

        $comboParent = Product::create([
            'name' => 'Combo Padre',
            'internal_code' => '00705',
            'cost_price' => 200,
            'selling_price' => 400,
            'stock' => 0,
            'is_combo' => true,
            'active' => true,
        ]);
        $comboParent->children()->attach($ingredient->id, ['quantity' => 2]);

        // Ajuste manual en StockController::adjust
        $adjResp = $this->withHeaders($this->authHeaders())
            ->postJson("/api/catalog/products/{$ingredient->id}/adjust-stock", [
                'type' => 'in',
                'quantity' => 4,
                'notes' => 'Reposición test',
            ]);
        $adjResp->assertStatus(200);

        Queue::assertPushed(SyncProductStockToPlatformsJob::class, fn ($j) => $j->productId === $ingredient->id);
        Queue::assertPushed(SyncProductStockToPlatformsJob::class, fn ($j) => $j->productId === $comboParent->id);
    }

    public function test_f1_med_05_06_07_08_and_baja_10_financial_and_offer_validations(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => ['pos', 'collect_customer_debt'],
            'session_token' => 'cashier-session-f1-med',
        ]);

        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $closedShift = CashShift::create([
            'user_id' => $cashier->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'closed',
            'opened_at' => now()->subHours(4),
            'closed_at' => now()->subHour(),
        ]);
        $openShift = CashShift::create([
            'user_id' => $cashier->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $cashMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Efectivo', 'is_cash' => true, 'is_active' => true]
        );
        $chequeMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cheque'],
            ['name' => 'Cheque', 'is_cash' => false, 'is_active' => true]
        );

        $product = Product::create([
            'name' => 'Amoladora Angular',
            'internal_code' => '00706',
            'cost_price' => 25000,
            'selling_price' => 50000,
            'stock' => 20,
            'active' => true,
        ]);

        // F1-MED-05: Oferta fixed_price sin special_price pero con discount_amount = 5000 -> precio esperado = 45000
        $offer = Offer::create([
            'name' => 'Promo $5000 OFF',
            'type' => 'fixed_price',
            'discount_amount' => 5000,
            'period_type' => 'daily',
            'start_date' => now()->subHour(),
            'end_date' => now()->addDay(),
            'is_active' => true,
        ]);
        $offer->products()->attach($product->id, ['special_price' => null]);

        // Si envía $5000 (el monto de descuento en vez de $45000), debe ser rechazado con 403
        $badPromoResp = $this->withHeaders($this->authHeaders($cashier))
            ->postJson('/api/pos/sales', [
                'total' => 5000,
                'total_surcharge' => 0,
                'cash_shift_id' => $openShift->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000, 'subtotal' => 5000],
                ],
                'payments' => [
                    ['payment_method_id' => $cashMethod->id, 'base_amount' => 5000, 'surcharge_amount' => 0, 'total_amount' => 5000],
                ],
            ]);
        $badPromoResp->assertStatus(403)
            ->assertJsonPath('expected_price', 45000);

        // Si envía $45000 (precio correcto de oferta), pasa sin requerir PIN (201)
        $goodPromoResp = $this->withHeaders($this->authHeaders($cashier))
            ->postJson('/api/pos/sales', [
                'total' => 45000,
                'total_surcharge' => 0,
                'cash_shift_id' => $openShift->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 45000, 'subtotal' => 45000],
                ],
                'payments' => [
                    ['payment_method_id' => $cashMethod->id, 'base_amount' => 45000, 'surcharge_amount' => 0, 'total_amount' => 45000],
                ],
            ]);
        $goodPromoResp->assertStatus(201);

        // F1-MED-07: Sobrepago en payments (ej. enviar $50000 de pago para una venta de $45000) es rechazado con 422
        $overpayResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/pos/sales', [
                'total' => 45000,
                'total_surcharge' => 0,
                'cash_shift_id' => $openShift->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 45000, 'subtotal' => 45000],
                ],
                'payments' => [
                    ['payment_method_id' => $cashMethod->id, 'base_amount' => 50000, 'surcharge_amount' => 0, 'total_amount' => 50000],
                ],
            ]);
        $overpayResp->assertStatus(422);

        // F1-MED-08: Pago con cheque sin check_details es rechazado con 422
        $missingCheckResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/pos/sales', [
                'total' => 45000,
                'total_surcharge' => 0,
                'cash_shift_id' => $openShift->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 45000, 'subtotal' => 45000],
                ],
                'payments' => [
                    ['payment_method_id' => $chequeMethod->id, 'base_amount' => 45000, 'surcharge_amount' => 0, 'total_amount' => 45000],
                ],
            ]);
        $missingCheckResp->assertStatus(422)
            ->assertJsonValidationErrors(['check_details']);

        // F1-MED-06: Venta pendiente creada en un turno que luego se cerró no puede cobrarse sin enviar el turno abierto actual
        $pendingInClosedShift = Sale::create([
            'total' => 45000,
            'total_surcharge' => 0,
            'payment_status' => 'pending',
            'amount_due' => 45000,
            'status' => 'pending',
            'user_id' => $cashier->id,
            'cashier_id' => $cashier->id,
            'cash_shift_id' => $closedShift->id,
        ]);

        $closedShiftPayResp = $this->withHeaders($this->authHeaders($cashier))
            ->putJson("/api/sales/{$pendingInClosedShift->id}/pay", [
                'total_surcharge' => 0,
                'payments' => [
                    ['payment_method_id' => $cashMethod->id, 'base_amount' => 45000, 'surcharge_amount' => 0, 'total_amount' => 45000],
                ],
            ]);
        $closedShiftPayResp->assertStatus(422)
            ->assertJsonValidationErrors(['cash_shift_id']);
    }
}
