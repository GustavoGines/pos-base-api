<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PaySaleDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use App\Services\Afip\AfipWsaaService;
use App\Services\Afip\AfipWsfeService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Fase3AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'session_token' => 'test-admin-token-f3',
        ]);

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'pin' => Hash::make('5678'),
            'permissions' => ['pos_access'],
            'session_token' => 'test-cashier-token-f3',
        ]);
    }

    private function authHeaders(?User $user = null): array
    {
        $u = $user ?? $this->admin;

        return [
            'X-Session-Token' => $u->session_token,
        ];
    }

    public function test_f3_afip_01_timeout_during_fecae_solicitar_recovers_cae_via_fe_comp_consultar(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => '20111111112']);
        BusinessSetting::updateOrCreate(['key' => 'afip_pto_vta'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => 'testing']);

        $wsaaMock = $this->createMock(AfipWsaaService::class);
        $wsaaMock->method('getAccessTicket')->willReturn([
            'token' => 'mock-token',
            'sign' => 'mock-sign',
        ]);

        Http::fake(function ($request) {
            $body = $request->body();
            if (str_contains($body, '<FECompUltimoAutorizado')) {
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompUltimoAutorizadoResult><CbteNro>10</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>',
                    200
                );
            }
            if (str_contains($body, '<FECAESolicitar')) {
                // Simular corte de red / HTTP 504 luego de que AFIP ya procesó el CAE internamente
                return Http::response('Gateway Timeout', 504);
            }
            if (str_contains($body, '<FECompConsultar')) {
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompConsultarResult><ResultGet><CodAutorizacion>74999888777666</CodAutorizacion><FchVto>20261105</FchVto><Resultado>A</Resultado><ImpTotal>1210.00</ImpTotal></ResultGet></FECompConsultarResult></FECompConsultarResponse></soap:Body></soap:Envelope>',
                    200
                );
            }

            return Http::response('', 500);
        });

        $product = Product::create(['name' => 'Producto Test 1', 'internal_code' => 'P001', 'cost_price' => 500.00, 'selling_price' => 1210.00, 'iva_rate' => 21.00, 'stock' => 50, 'active' => true]);
        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'total' => 1210.00,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'cashier_id' => $this->admin->id,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 1210.00,
            'subtotal' => 1210.00,
            'iva_rate' => 21.00,
        ]);

        $wsfeService = new AfipWsfeService($wsaaMock);
        $result = $wsfeService->authorizeInvoice($sale, ['voucher_letter' => 'B']);

        $this->assertSame('authorized', $result['status']);
        $this->assertSame('74999888777666', $result['cae']);
        $this->assertSame(11, $result['voucher_number']);
        $this->assertSame('2026-11-05', $result['cae_expiration']);
    }

    public function test_fn_f3_01_deferred_retry_recovers_cae_from_pending_cache_without_reinvoking_fecae_solicitar(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => '20111111112']);
        BusinessSetting::updateOrCreate(['key' => 'afip_pto_vta'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => 'testing']);

        $wsaaMock = $this->createMock(AfipWsaaService::class);
        $wsaaMock->method('getAccessTicket')->willReturn([
            'token' => 'mock-token',
            'sign' => 'mock-sign',
        ]);

        $fecaeSolicitarCount = 0;
        $attempt = 1;

        Http::fake(function ($request) use (&$fecaeSolicitarCount, &$attempt) {
            $body = $request->body();
            if (str_contains($body, '<FECompUltimoAutorizado')) {
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompUltimoAutorizadoResult><CbteNro>20</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>',
                    200
                );
            }
            if (str_contains($body, '<FECAESolicitar')) {
                $fecaeSolicitarCount++;

                return Http::response('Gateway Timeout', 504);
            }
            if (str_contains($body, '<FECompConsultar')) {
                if ($attempt === 1) {
                    // En el primer intento la red sigue caída también para FECompConsultar
                    return Http::response('Gateway Timeout', 504);
                }

                // En el segundo intento (reintento diferido del cajero), FECompConsultar recupera el CbteNro 21
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompConsultarResult><ResultGet><CodAutorizacion>74555444333222</CodAutorizacion><FchVto>20261108</FchVto><Resultado>A</Resultado><ImpTotal>500.00</ImpTotal></ResultGet></FECompConsultarResult></FECompConsultarResponse></soap:Body></soap:Envelope>',
                    200
                );
            }

            return Http::response('', 500);
        });

        $product = Product::create(['name' => 'Producto Test 2', 'internal_code' => 'P002', 'cost_price' => 200.00, 'selling_price' => 500.00, 'iva_rate' => 21.00, 'stock' => 50, 'active' => true]);
        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $sale = Sale::create([
            'total' => 500.00,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'cashier_id' => $this->admin->id,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 500.00,
            'subtotal' => 500.00,
            'iva_rate' => 21.00,
        ]);

        $wsfeService = new AfipWsfeService($wsaaMock);

        try {
            $wsfeService->authorizeInvoice($sale, ['voucher_letter' => 'B']);
            $this->fail('El primer intento debía fallar por timeout total');
        } catch (\RuntimeException) {
            // Esperado en el intento 1
        }

        $attempt = 2;
        $recovered = $wsfeService->authorizeInvoice($sale, ['voucher_letter' => 'B']);

        $this->assertSame(1, $fecaeSolicitarCount, 'No debe volver a invocar FECAESolicitar si FECompConsultar recupera el comprobante pendiente');
        $this->assertSame('74555444333222', $recovered['cae']);
        $this->assertSame(21, $recovered['voucher_number']);
    }

    public function test_f3_afip_02_multi_aliquot_rounding_adjustment_preserves_alic_iva_proportionality(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => '20111111112']);
        BusinessSetting::updateOrCreate(['key' => 'afip_pto_vta'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'afip_environment'], ['value' => 'testing']);

        $wsaaMock = $this->createMock(AfipWsaaService::class);
        $wsaaMock->method('getAccessTicket')->willReturn([
            'token' => 'mock-token',
            'sign' => 'mock-sign',
        ]);

        $capturedSoapXml = '';

        Http::fake(function ($request) use (&$capturedSoapXml) {
            $body = $request->body();
            if (str_contains($body, '<FECompUltimoAutorizado')) {
                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompUltimoAutorizadoResult><CbteNro>1</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>',
                    200
                );
            }
            if (str_contains($body, '<FECAESolicitar')) {
                $capturedSoapXml = $body;

                return Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECAESolicitarResult><FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Resultado>A</Resultado><CAE>74000111222333</CAE><CAEFchVto>20261110</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse></soap:Body></soap:Envelope>',
                    200
                );
            }

            return Http::response('', 500);
        });

        $p21 = Product::create(['name' => 'Item 21', 'internal_code' => 'P21', 'cost_price' => 50.00, 'selling_price' => 100.00, 'iva_rate' => 21.00, 'stock' => 50, 'active' => true]);
        $p105 = Product::create(['name' => 'Item 10.5', 'internal_code' => 'P105', 'cost_price' => 25.00, 'selling_price' => 50.00, 'iva_rate' => 10.50, 'stock' => 50, 'active' => true]);

        $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Forzar una diferencia de redondeo de $0.05 entre $sale->total (150.05) y la suma de subtotales de ítems (150.00)
        $sale = Sale::create([
            'total' => 150.05,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'payment_status' => 'paid',
            'amount_due' => 0,
            'status' => 'completed',
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'cashier_id' => $this->admin->id,
        ]);

        $sale->items()->create([
            'product_id' => $p21->id,
            'product_name' => $p21->name,
            'quantity' => 1,
            'unit_price' => 100.00,
            'subtotal' => 100.00,
            'iva_rate' => 21.00,
        ]);

        $sale->items()->create([
            'product_id' => $p105->id,
            'product_name' => $p105->name,
            'quantity' => 1,
            'unit_price' => 50.00,
            'subtotal' => 50.00,
            'iva_rate' => 10.50,
        ]);

        $wsfeService = new AfipWsfeService($wsaaMock);
        $result = $wsfeService->authorizeInvoice($sale, ['voucher_letter' => 'B']);

        $this->assertEqualsWithDelta(
            $result['total_amount'],
            round($result['net_amount'] + $result['iva_amount'], 2),
            0.001
        );

        foreach ($result['iva_breakdown'] as $aliquot) {
            $rate = $aliquot['Id'] === 5 ? 21.0 : 10.5;
            $expectedIva = round($aliquot['BaseImp'] * ($rate / 100), 2);
            $this->assertEqualsWithDelta(
                $expectedIva,
                $aliquot['Importe'],
                0.01,
                "El nodo AlicIva Id={$aliquot['Id']} debe mantener proporcionalidad entre BaseImp e Importe"
            );
        }
        $this->assertNotEmpty($capturedSoapXml);
    }

    public function test_f3_caja_01_and_fn_f3_02_multi_register_cash_movements_respect_explicit_cash_shift_id(): void
    {
        $reg1 = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
        $reg2 = CashRegister::create(['name' => 'Caja 2', 'is_active' => true]);

        $shift1 = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $reg1->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now()->subMinutes(10),
        ]);

        $shift2 = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $reg2->id,
            'opening_balance' => 2000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Registrar movimiento especificando explícitamente el turno de la Caja 1 ($shift1)
        $postResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/cash-movements', [
                'cash_shift_id' => $shift1->id,
                'type' => 'deposit',
                'category' => 'ingreso_cambio',
                'description' => 'Ingreso en Caja 1',
                'payments' => [
                    ['amount' => 350.00, 'payment_method' => 'cash'],
                ],
            ]);

        $postResp->assertCreated();

        $movement = CashMovement::first();
        $this->assertNotNull($movement);
        $this->assertSame($shift1->id, $movement->cash_shift_id, 'El movimiento debe imputarse a la Caja 1 (shift1), no al último turno abierto global (shift2)');

        // Consultar movimientos filtrando por Caja 1 vs Caja 2
        $listShift1 = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson("/api/cash-movements?cash_shift_id={$shift1->id}");
        $listShift1->assertOk();
        $this->assertCount(1, $listShift1->json('data'));

        $listShift2 = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson("/api/cash-movements?cash_shift_id={$shift2->id}");
        $listShift2->assertOk();
        $this->assertCount(0, $listShift2->json('data'));
    }

    public function test_f3_caja_02_and_fn_f3_05_blind_cash_count_hides_expected_balance_and_difference_from_cashier(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->cashier->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 5000.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. GET /api/shifts/current como cajero sin permiso manage_shifts/view_reports NO debe exponer expected_balance
        $currentCashierResp = $this->withHeaders($this->authHeaders($this->cashier))
            ->getJson('/api/shifts/current');
        $currentCashierResp->assertOk();
        $this->assertArrayNotHasKey('expected_balance', $currentCashierResp->json());
        $this->assertArrayNotHasKey('cash_sales', $currentCashierResp->json());

        // 2. GET /api/shifts/current como admin SÍ incluye expected_balance
        $currentAdminResp = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/shifts/current');
        $currentAdminResp->assertOk();
        $this->assertEquals(5000.00, (float) $currentAdminResp->json('expected_balance'));

        // 3. POST /api/shifts/{id}/close con diferencia sin PIN de supervisor devuelve 403 SIN expected_balance ni difference
        $closeResp = $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson("/api/shifts/{$shift->id}/close", [
                'actual_balance' => 4200.00,
                'closer_user_id' => $this->cashier->id,
                'pin' => '5678',
            ]);

        $closeResp->assertStatus(403)
            ->assertJsonPath('error_code', 'DIFFERENCE_REQUIRES_ADMIN')
            ->assertJsonPath('actual_balance', 4200);

        $this->assertArrayNotHasKey('expected_balance', $closeResp->json());
        $this->assertArrayNotHasKey('difference', $closeResp->json());
    }

    public function test_f3_caja_03_and_fn_f3_03_sale_service_locks_shift_and_rejects_closed_shift(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $closedShift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'closed',
            'opened_at' => now()->subHours(3),
            'closed_at' => now()->subMinute(),
        ]);

        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'cash',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Prod Caja Cerrada',
            'internal_code' => 'PCC01',
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'stock' => 20,
            'active' => true,
        ]);

        $saleService = app(SaleService::class);

        $dto = ProcessSaleDTO::fromArray([
            'total' => 100.00,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'status' => 'completed',
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => 100.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 100.00,
                ],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'subtotal' => 100.00,
                ],
            ],
        ]);

        $context = SaleContextDTO::fromArray([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $closedShift->id,
        ], $this->admin->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $saleService->executeSale($dto, $context);
    }

    public function test_f3_resil_01_and_fn_f3_04_idempotent_sale_creation_and_retry_after_void(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $openShift = CashShift::create([
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

        $product = Product::create([
            'name' => 'Prod Idempotente',
            'internal_code' => 'PID01',
            'cost_price' => 60.00,
            'selling_price' => 125.00,
            'stock' => 10,
            'active' => true,
        ]);

        $payload = [
            'client_transaction_id' => 'uuid-idemp-test-0001',
            'total' => 250.00,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'cash_shift_id' => $openShift->id,
            'status' => 'completed',
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => 250.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 250.00,
                ],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 125.00,
                    'subtotal' => 250.00,
                ],
            ],
        ];

        // 1. Primera petición
        $resp1 = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/pos/sales', $payload);
        $resp1->assertCreated();
        $saleId1 = $resp1->json('sale.id');

        // 2. Segunda petición idéntica (simulando reintento por corte de red)
        $resp2 = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/pos/sales', $payload);
        $resp2->assertCreated();
        $saleId2 = $resp2->json('sale.id');

        $this->assertSame($saleId1, $saleId2, 'El reintento con el mismo client_transaction_id debe devolver exactamente la misma venta');
        $this->assertSame(1, Sale::count(), 'No debe duplicarse el registro en la tabla sales');
        $this->assertEquals(8.0, (float) $product->fresh()->stock, 'El stock solo debe descontarse una vez');

        // 3. Si la venta se anula (ej. rollback por error fiscal AFIP), un nuevo intento debe crear una venta nueva activa
        $voidResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/sales/{$saleId1}/void", [
                'cash_shift_id' => $openShift->id,
                'reason' => 'Error al emitir Factura Electrónica AFIP',
            ]);
        $voidResp->assertOk();
        $this->assertEquals(10.0, (float) $product->fresh()->stock);

        $resp3 = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/pos/sales', $payload);
        $resp3->assertCreated();
        $saleId3 = $resp3->json('sale.id');

        $this->assertNotSame($saleId1, $saleId3, 'Tras anular la venta original, un reintento no debe devolver la venta anulada sino crear una nueva');
        $this->assertSame('completed', Sale::find($saleId3)->status);
        $this->assertEquals(8.0, (float) $product->fresh()->stock);
    }

    public function test_bloque1_col01_col03_col07_col08_legacy_compat_and_pending_sale_idempotency(): void
    {
        $register = CashRegister::create(['name' => 'Caja Unica', 'is_active' => true]);
        $openShift = CashShift::create([
            'user_id' => $this->cashier->id,
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

        $product = Product::create([
            'name' => 'Prod Bloque 1',
            'internal_code' => 'PB01',
            'cost_price' => 100.00,
            'selling_price' => 300.00,
            'stock' => 20,
            'active' => true,
        ]);

        // 1. Crear orden en espera
        $pendingResp = $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson('/api/pos/sales', [
                'total' => 300.00,
                'total_surcharge' => 0,
                'cash_shift_id' => $openShift->id,
                'status' => 'pending',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 300.00,
                        'subtotal' => 300.00,
                    ],
                ],
            ]);
        $pendingResp->assertCreated();
        $pendingSaleId = $pendingResp->json('sale.id');

        // 2. COL-07: Cobrar orden en espera y reintentar el mismo PUT /api/sales/{id}/pay (idempotente)
        $payPayload = [
            'cash_shift_id' => $openShift->id,
            'total_surcharge' => 0,
            'shipping_cost' => 0,
            'tendered_amount' => 300.00,
            'change_amount' => 0,
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => 300.00,
                    'surcharge_amount' => 0,
                    'total_amount' => 300.00,
                ],
            ],
        ];

        $payResp1 = $this->withHeaders($this->authHeaders($this->admin))
            ->putJson("/api/sales/{$pendingSaleId}/pay", $payPayload);
        $payResp1->assertOk();

        $payResp2 = $this->withHeaders($this->authHeaders($this->admin))
            ->putJson("/api/sales/{$pendingSaleId}/pay", $payPayload);
        $payResp2->assertOk()
            ->assertJsonPath('sale.id', $pendingSaleId)
            ->assertJsonPath('sale.status', 'completed');

        $this->assertEquals(1, Sale::find($pendingSaleId)->payments()->count(), 'El reintento idempotente no debe duplicar pagos');

        // 3. COL-08: En instalación Mono-Caja, GET /api/sales?period=shift sin shift_id resuelve el turno abierto
        $salesIndexResp = $this->withHeaders($this->authHeaders($this->cashier))
            ->getJson('/api/sales?period=shift');
        $salesIndexResp->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pendingSaleId);

        // 4. COL-03: Cliente legacy v1.8.5 anula venta enviando POST /api/sales/{id}/void sin cash_shift_id
        $voidLegacyResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/sales/{$pendingSaleId}/void", []);
        $voidLegacyResp->assertOk()
            ->assertJsonPath('sale.status', 'voided');

        // 5. COL-01: Cliente legacy v1.8.5 cierra turno enviando POST /api/shifts/{id}/close sin clave 'pin'
        $closeLegacyResp = $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson("/api/shifts/{$openShift->id}/close", [
                'actual_balance' => 2000.00,
                'closer_user_id' => $this->cashier->id,
            ]);
        $closeLegacyResp->assertOk()
            ->assertJsonPath('shift.status', 'closed');
    }

    public function test_bloque2_cc02_to_cc11_current_accounts_checks_and_legacy_methods(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['current_accounts' => true, 'checks' => true])]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'app_plan'],
            ['value' => 'pro']
        );

        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $openShift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. CC-06: Impedir eliminar cliente con saldo a favor (balance < 0)
        $creditCustomer = Customer::create([
            'name' => 'Cliente Saldo a Favor',
            'document_number' => '30111222',
            'balance' => -500.00,
            'credit_limit' => 0,
        ]);

        $delResp = $this->withHeaders($this->authHeaders($this->admin))
            ->deleteJson("/api/customers/{$creditCustomer->id}");
        $delResp->assertStatus(403);

        // 2. CC-03, CC-07, CC-10: Abono con excedente en sale_ids, métodos legacy ('efectivo') y balance_after progresivo
        $debtCustomer = Customer::create([
            'name' => 'Cliente Deudor',
            'document_number' => '30333444',
            'balance' => 1000.00,
            'credit_limit' => 0,
        ]);

        $sale1 = Sale::create([
            'user_id' => $this->admin->id,
            'customer_id' => $debtCustomer->id,
            'cash_shift_id' => $openShift->id,
            'total' => 200.00,
            'amount_due' => 200.00,
            'payment_status' => 'pending',
            'status' => 'completed',
            'created_at' => now()->subMinutes(10),
        ]);

        $sale2 = Sale::create([
            'user_id' => $this->admin->id,
            'customer_id' => $debtCustomer->id,
            'cash_shift_id' => $openShift->id,
            'total' => 800.00,
            'amount_due' => 800.00,
            'payment_status' => 'pending',
            'status' => 'completed',
            'created_at' => now()->subMinutes(5),
        ]);

        // CC-05: Pago con cheque sin check_details debe fallar con 422
        $badCheckResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/customers/{$debtCustomer->id}/payments", [
                'payments' => [
                    ['method' => 'cheque', 'amount' => 200.00],
                ],
                'cash_shift_id' => $openShift->id,
            ]);
        $badCheckResp->assertStatus(422);

        // Selecciona sale_ids = [$sale1->id] ($200) pero abona $500 combinando 'efectivo' ($200) y 'cheque' ($300)
        $payResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/customers/{$debtCustomer->id}/payments", [
                'sale_ids' => [$sale1->id],
                'cash_shift_id' => $openShift->id,
                'payments' => [
                    [
                        'method' => 'efectivo',
                        'amount' => 200.00,
                    ],
                    [
                        'method' => 'cheque',
                        'amount' => 300.00,
                        'check_details' => [
                            'bank_name' => 'Banco Galicia',
                            'check_number' => 'CHQ-9001',
                            'issuer_name' => 'Cliente Deudor',
                            'issuer_cuit' => '20303334449',
                        ],
                    ],
                ],
            ]);
        $payResp->assertOk();

        // Verificar CC-03: $sale1 quedó 'paid' (0.00) y el excedente de $300 se imputó FIFO a $sale2 (800 - 300 = 500)
        $this->assertEquals(0.0, (float) $sale1->fresh()->amount_due);
        $this->assertSame('paid', $sale1->fresh()->payment_status);
        $this->assertEquals(500.0, (float) $sale2->fresh()->amount_due);
        $this->assertSame('partial', $sale2->fresh()->payment_status);
        $this->assertEquals(500.0, (float) $debtCustomer->fresh()->balance);

        // Verificar CC-07 & CC-10: Transacciones guardadas con método normalizado ('cash') y balance_after progresivo (800 -> 500)
        $trxs = CustomerTransaction::where('customer_id', $debtCustomer->id)->orderBy('id', 'asc')->get();
        $this->assertCount(2, $trxs);
        $this->assertSame('cash', $trxs[0]->payment_method);
        $this->assertEquals(800.0, (float) $trxs[0]->balance_after);
        $this->assertSame('cheque', $trxs[1]->payment_method);
        $this->assertEquals(500.0, (float) $trxs[1]->balance_after);

        // 3. CC-11: Si el cheque CHQ-9001 ($300) es rechazado por el banco, la deuda del cliente sube de $500 a $800
        $check = ThirdPartyCheck::where('check_number', 'CHQ-9001')->firstOrFail();
        $rejectResp = $this->withHeaders($this->authHeaders($this->admin))
            ->patchJson("/api/third-party-checks/{$check->id}/status", [
                'status' => 'rejected',
            ]);
        $rejectResp->assertOk();
        $this->assertEquals(800.0, (float) $debtCustomer->fresh()->balance);

        // Y si fue rechazado por error y vuelve a 'in_wallet', revierte el cargo volviendo a $500
        $revertCheckResp = $this->withHeaders($this->authHeaders($this->admin))
            ->patchJson("/api/third-party-checks/{$check->id}/status", [
                'status' => 'in_wallet',
            ]);
        $revertCheckResp->assertOk();
        $this->assertEquals(500.0, (float) $debtCustomer->fresh()->balance);
    }

    /**
     * Bloque 3 (CP-01 a CP-12):
     * - CP-06: Factura estilo v1.8.5 sin 'type' funciona con default 'invoice'.
     * - CP-01, CP-04, CP-08: Compra de ingrediente actualiza stock de ingrediente y combo padre;
     *   Nota de Crédito genera StockMovement con quantity negativo y reduce stock/deuda.
     * - CP-10: Rechaza 422 si new_selling_price < unit_cost.
     * - CP-09: SupplierController::destroy bloquea eliminación con saldo pendiente.
     * - CP-07: UpdateProductRequest permite actualizar solo selling_price sin enviar cost_price.
     */
    public function test_bloque3_cp01_to_cp12_suppliers_invoices_kardex_and_v185_compat(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['suppliers' => true])]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'app_plan'],
            ['value' => 'pro']
        );

        $baseProduct = Product::create([
            'name' => 'Producto Base Proveedor',
            'internal_code' => '88001',
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'stock' => 50,
            'active' => true,
        ]);

        $supplier = Supplier::create([
            'name' => 'Proveedor Mayorista S.A.',
            'cuit' => '30711223344',
            'balance' => 0.00,
            'is_active' => true,
        ]);

        // 1. CP-06: Cliente v1.8.5 envía factura sin campo 'type' ni 'items'
        $legacyInvoiceResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'amount' => 1500.00,
                'invoice_number' => 'A-0001-00001001',
                'description' => 'Remito legacy v1.8.5',
            ]);
        $legacyInvoiceResp->assertOk();
        $this->assertEquals(1500.00, (float) $supplier->fresh()->balance);

        // 2. CP-09: No permite eliminar proveedor con saldo != 0
        $delSupplierResp = $this->withHeaders($this->authHeaders($this->admin))
            ->deleteJson("/api/suppliers/{$supplier->id}");
        $delSupplierResp->assertStatus(422);

        // 3. CP-10: Rechaza ítem con new_selling_price < unit_cost
        $badPriceResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'invoice',
                'invoice_number' => 'A-0001-00001002',
                'items' => [
                    [
                        'product_id' => $baseProduct->id,
                        'quantity' => 5,
                        'unit_cost' => 800.00,
                        'subtotal' => 4000.00,
                        'new_selling_price' => 500.00,
                    ],
                ],
            ]);
        $badPriceResp->assertStatus(422);

        // 4. CP-01 & CP-08: Crear un combo cuyo ingrediente sea $baseProduct (2 unidades por combo)
        $combo = Product::create([
            'name' => 'Pack x2 Producto Base',
            'internal_code' => '99001',
            'cost_price' => 100.00,
            'selling_price' => 300.00,
            'stock' => 25, // $baseProduct tiene stock inicial 50 => 50 / 2 = 25 packs
            'is_combo' => true,
            'active' => true,
        ]);
        $combo->children()->sync([$baseProduct->id => ['quantity' => 2]]);

        // Ingresar compra de 10 unidades de $baseProduct (stock pasa de 50 a 60; combo padre debe pasar de 25 a 30)
        $purchaseResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'invoice',
                'invoice_number' => 'A-0001-00001003',
                'items' => [
                    [
                        'product_id' => $baseProduct->id,
                        'quantity' => 10,
                        'unit_cost' => 60.00,
                        'subtotal' => 600.00,
                        'new_selling_price' => 150.00,
                    ],
                ],
            ]);
        $purchaseResp->assertOk();
        $this->assertEquals(60.0, (float) $baseProduct->fresh()->stock);
        $this->assertEquals(60.0, (float) $baseProduct->fresh()->cost_price);
        $this->assertEquals(150.0, (float) $baseProduct->fresh()->selling_price);
        $this->assertEquals(30.0, (float) $combo->fresh()->stock);
        $this->assertEquals(120.0, (float) $combo->fresh()->cost_price);
        $this->assertEquals(2100.0, (float) $supplier->fresh()->balance);

        // 5. CP-04: Nota de Crédito descuenta stock y registra StockMovement con quantity negativo
        $creditNoteResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson("/api/suppliers/{$supplier->id}/invoices", [
                'type' => 'credit_note',
                'invoice_number' => 'NC-0001-00000050',
                'items' => [
                    [
                        'product_id' => $baseProduct->id,
                        'quantity' => 4,
                        'unit_cost' => 60.00,
                        'subtotal' => 240.00,
                    ],
                ],
            ]);
        $creditNoteResp->assertOk();
        $this->assertEquals(56.0, (float) $baseProduct->fresh()->stock);
        $this->assertEquals(28.0, (float) $combo->fresh()->stock);
        $this->assertEquals(1860.0, (float) $supplier->fresh()->balance);

        $lastMovement = StockMovement::where('product_id', $baseProduct->id)
            ->where('type', 'out')
            ->latest('id')
            ->firstOrFail();
        $this->assertEquals(-4.0, (float) $lastMovement->quantity);

        // 6. CP-07: Update parcial enviando solo selling_price sin cost_price en el payload
        $partialUpdateResp = $this->withHeaders($this->authHeaders($this->admin))
            ->putJson("/api/catalog/products/{$baseProduct->id}", [
                'selling_price' => 180.00,
            ]);
        $partialUpdateResp->assertOk();
        $this->assertEquals(180.00, (float) $baseProduct->fresh()->selling_price);
    }

    /**
     * Bloque 4 (REP-01 a REP-11):
     * - REP-01: POST /api/cash-movements acepta payload plano legacy v1.8.5 sin array 'payments' y normaliza 'efectivo'.
     * - REP-02: CashShiftService mantiene cash_sales y expected_balance intactos para v1.8.5, pero no duplica cobros de CC en total_sales.
     * - REP-03: SalesAnalyticsRepository::getMonthlyBalance excluye devoluciones ('category' = 'refund') de los gastos operativos.
     * - REP-11: GET /api/reports/vat-sales devuelve desglose fiscal de Facturas y Notas de Crédito autorizadas.
     */
    public function test_bloque4_rep01_to_rep11_legacy_cash_movements_shift_collections_refund_exclusion_and_vat_sales(): void
    {
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 1000.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. REP-01: Cliente v1.8.5 envía estructura plana {"type": "expense", "amount": 250.00, "payment_method": "efectivo"}
        $legacyMovResp = $this->withHeaders($this->authHeaders($this->admin))
            ->postJson('/api/cash-movements', [
                'type' => 'expense',
                'amount' => 250.00,
                'payment_method' => 'efectivo',
                'description' => 'Gasto plano desde terminal v1.8.5',
            ]);
        $legacyMovResp->assertStatus(201);
        $this->assertDatabaseHas('cash_movements', [
            'cash_shift_id' => $shift->id,
            'type' => 'expense',
            'amount' => 250.00,
            'payment_method' => 'cash',
        ]);

        // 2. REP-02: Cobro de Cuenta Corriente en efectivo ($5000) entra al cajón (expected_balance y cash_sales para v1.8.5),
        // pero no infla total_sales si no hubo ventas en el turno
        $customer = Customer::create([
            'name' => 'Cliente Cobranza Turno',
            'document_number' => '29888777',
            'balance' => 5000.00,
        ]);
        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => $this->admin->id,
            'cash_shift_id' => $shift->id,
            'type' => 'payment',
            'amount' => 5000.00,
            'payment_method' => 'cash',
            'balance_after' => 0.00,
        ]);

        $shiftService = app(\App\Services\CashShiftService::class);
        $liveTotals = $shiftService->calculateLiveTotals($shift);
        // opening (1000) + cc_cash (5000) - expense (250) = 5750
        $this->assertEquals(5750.00, (float) $liveTotals['expected_balance']);
        $this->assertEquals(5000.00, (float) $liveTotals['cash_sales']);
        $this->assertEquals(5000.00, (float) $liveTotals['customer_collections_total']);
        $this->assertEquals(0.00, (float) $liveTotals['total_sales']);

        // 3. REP-03: Devolución automática ('category' = 'refund') no debe restarse como gasto operativo en getMonthlyBalance
        $prod = Product::create([
            'name' => 'Prod Balance Mensual',
            'internal_code' => '77001',
            'cost_price' => 400.00,
            'selling_price' => 1000.00,
            'stock' => 20,
            'active' => true,
        ]);
        $completedSale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $shift->id,
            'total' => 1000.00,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        $completedSale->items()->create([
            'product_id' => $prod->id,
            'product_name' => $prod->name,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'unit_cost_price' => 400.00,
            'subtotal' => 1000.00,
        ]);

        // Movimiento de devolución ('refund') de $800 por una anulación de otro ticket
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'refund',
            'amount' => 800.00,
            'description' => 'Devolución por anulación de Venta previa',
        ]);

        $currentMonth = now()->format('Y-m');
        $balanceData = app(\App\Repositories\SalesAnalyticsRepository::class)->getMonthlyBalance($currentMonth, $currentMonth);
        $monthRow = collect($balanceData['months'])->firstWhere('period', $currentMonth);
        $this->assertNotNull($monthRow);
        // Ganancia bruta = $600 (1000 - 400) menos el gasto operativo real ($250) = $350 (ignora el refund de $800)
        $this->assertEquals(350.00, (float) $monthRow['total_profit']);

        // 4. REP-11: Libro IVA Ventas (/api/reports/vat-sales)
        \App\Models\ElectronicInvoice::create([
            'sale_id' => $completedSale->id,
            'voucher_type' => 6,
            'voucher_letter' => 'B',
            'point_of_sale' => 1,
            'voucher_number' => 15,
            'cae' => '74111222333444',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'doc_type' => 99,
            'doc_number' => '0',
            'receiver_name' => 'Consumidor Final',
            'receiver_tax_condition' => 'Consumidor Final',
            'net_amount' => 826.45,
            'iva_amount' => 173.55,
            'tribute_amount' => 0.00,
            'exempt_amount' => 0.00,
            'untaxed_amount' => 0.00,
            'total_amount' => 1000.00,
            'status' => 'authorized',
            'issued_at' => now(),
        ]);

        $today = now()->toDateString();
        $vatResp = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson("/api/reports/vat-sales?start_date={$today}&end_date={$today}");
        $vatResp->assertOk()
            ->assertJsonPath('summary.total_net', 826.45)
            ->assertJsonPath('summary.total_iva', 173.55)
            ->assertJsonPath('summary.total_invoiced', 1000)
            ->assertJsonPath('summary.net_fiscal_total', 1000);
    }

    /**
     * FINDING-CC-01: Una venta en Cuenta Corriente que excede credit_limit se rechaza con 422 para un
     * cajero (sin crear venta ni descontar stock), salvo autorización con PIN de supervisor.
     * credit_limit = 0 significa "sin límite".
     */
    public function test_lote1_cc01_credit_limit_blocks_cashier_unless_supervisor_pin(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'license_features_dict'], ['value' => json_encode(['current_accounts' => true])]);
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'pro']);

        $shift = $this->crearTurnoAbierto(user: $this->cashier);
        $cc = $this->crearMetodoCuentaCorriente();
        $product = Product::create([
            'name' => 'Producto Fiado CC-01',
            'internal_code' => 'CC01-P',
            'selling_price' => 300.00,
            'cost_price' => 150.00,
            'stock' => 10,
            'active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Cliente Con Límite',
            'document_number' => '30999888',
            'balance' => 800.00,
            'credit_limit' => 1000.00,
        ]);

        $payload = [
            'total' => 300.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $shift->id,
            'user_id' => $this->cashier->id,
            'customer_id' => $customer->id,
            'payments' => [[
                'payment_method_id' => $cc->id,
                'base_amount' => 300.00,
                'surcharge_amount' => 0,
                'total_amount' => 300.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 300.00,
                'subtotal' => 300.00,
            ]],
        ];

        // 1. Cajero sin PIN: 800 + 300 > 1000 -> 422, sin efectos colaterales.
        $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson('/api/pos/sales', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'límite de crédito'));

        $this->assertEquals(800.00, (float) $customer->fresh()->balance);
        $this->assertEquals(10, (float) $product->fresh()->stock);
        $this->assertSame(0, Sale::where('customer_id', $customer->id)->count());

        // 2. PIN de supervisor incorrecto -> 403.
        $this->withHeaders($this->authHeaders($this->cashier) + ['X-Admin-Pin' => '0000'])
            ->postJson('/api/pos/sales', $payload)
            ->assertStatus(403);
        $this->assertEquals(800.00, (float) $customer->fresh()->balance);

        // 3. PIN de supervisor válido -> 201, deuda 1100 y venta auditada con authorized_by_admin_id.
        $okResp = $this->withHeaders($this->authHeaders($this->cashier) + ['X-Admin-Pin' => '1234'])
            ->postJson('/api/pos/sales', $payload + ['idempotency_key' => 'cc01-with-pin']);
        $okResp->assertStatus(201);
        $this->assertEquals(1100.00, (float) $customer->fresh()->balance);
        $this->assertEquals(9, (float) $product->fresh()->stock);

        // 4. Cliente sin límite (credit_limit = 0): el cajero vende sin PIN.
        $unlimited = Customer::create([
            'name' => 'Cliente Sin Límite',
            'document_number' => '30777666',
            'balance' => 50000.00,
            'credit_limit' => 0,
        ]);
        $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson('/api/pos/sales', ['customer_id' => $unlimited->id, 'idempotency_key' => 'cc01-unlimited'] + $payload)
            ->assertStatus(201);
        $this->assertEquals(50300.00, (float) $unlimited->fresh()->balance);
    }

    /**
     * FINDING-SALES-01: El cajero sin permiso void_sales puede revertir automáticamente su propia venta
     * recién creada cuando AFIP la rechazó (invoice_status = failed, sin CAE, <= 180s desde created_at),
     * pero no puede usar fiscal_rollback para anular ventas normales, antiguas, con CAE o ajenas.
     */
    public function test_lote1_sales01_fiscal_rollback_allowed_only_for_fresh_failed_own_sale(): void
    {
        $shift = $this->crearTurnoAbierto(user: $this->cashier);
        $cash = $this->crearMetodoEfectivo();

        $makeSale = function (array $overrides = []) use ($shift, $cash): Sale {
            $sale = Sale::create(array_merge([
                'total' => 100.00,
                'total_surcharge' => 0,
                'payment_status' => 'paid',
                'amount_due' => 0,
                'status' => 'completed',
                'invoice_status' => 'failed',
                'cash_shift_id' => $shift->id,
                'user_id' => $this->cashier->id,
                'cashier_id' => $this->cashier->id,
            ], $overrides));

            \App\Models\SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $cash->id,
                'base_amount' => 100.00,
                'surcharge_amount' => 0,
                'total_amount' => 100.00,
            ]);

            return $sale;
        };

        $rollback = fn (Sale $sale, bool $flag = true) => $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson("/api/sales/{$sale->id}/void", ['cash_shift_id' => $shift->id, 'fiscal_rollback' => $flag]);

        // 1. Venta normal (sin fallo fiscal): fiscal_rollback no otorga permiso -> 403 PIN_REQUIRED.
        $normal = $makeSale(['invoice_status' => 'none']);
        $rollback($normal)->assertStatus(403)->assertJsonPath('error_code', 'PIN_REQUIRED');

        // 2. Venta con fallo fiscal pero sin la bandera fiscal_rollback -> 403.
        $failedNoFlag = $makeSale();
        $rollback($failedNoFlag, false)->assertStatus(403);

        // 3. Venta fallida antigua (creada hace 10 min) aunque se haya "tocado" recién -> 403.
        $old = $makeSale();
        Sale::where('id', $old->id)->update(['created_at' => now()->subMinutes(10), 'updated_at' => now()]);
        $rollback($old)->assertStatus(403);

        // 4. Venta fallida con CAE ya asignado -> 403.
        $withCae = $makeSale();
        \App\Models\ElectronicInvoice::create([
            'sale_id' => $withCae->id,
            'voucher_type' => 6,
            'voucher_letter' => 'B',
            'point_of_sale' => 1,
            'voucher_number' => 99,
            'cae' => '74999888777666',
            'cae_expiration' => now()->addDays(10)->toDateString(),
            'doc_type' => 99,
            'doc_number' => '0',
            'receiver_name' => 'Consumidor Final',
            'receiver_tax_condition' => 'Consumidor Final',
            'net_amount' => 82.64,
            'iva_amount' => 17.36,
            'tribute_amount' => 0.00,
            'exempt_amount' => 0.00,
            'untaxed_amount' => 0.00,
            'total_amount' => 100.00,
            'status' => 'authorized',
            'issued_at' => now(),
        ]);
        $rollback($withCae)->assertStatus(403);

        // 5. Venta fallida de otro cajero en otro turno -> 403.
        $otherCashier = User::factory()->create(['role' => 'cashier', 'permissions' => ['pos_access']]);
        $otherShift = CashShift::create([
            'cash_register_id' => $shift->cash_register_id,
            'user_id' => $otherCashier->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
        $foreign = $makeSale([
            'user_id' => $otherCashier->id,
            'cashier_id' => $otherCashier->id,
            'cash_shift_id' => $otherShift->id,
        ]);
        $this->withHeaders($this->authHeaders($this->cashier))
            ->postJson("/api/sales/{$foreign->id}/void", ['fiscal_rollback' => true])
            ->assertStatus(403);

        foreach ([$normal, $failedNoFlag, $old, $withCae, $foreign] as $untouched) {
            $this->assertSame('completed', $untouched->fresh()->status);
        }

        // 6. Caso legítimo: venta propia recién rechazada por AFIP -> 200 y queda anulada.
        $fresh = $makeSale();
        $rollback($fresh)->assertStatus(200);
        $this->assertSame('voided', $fresh->fresh()->status);
    }
}
