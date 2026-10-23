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
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
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
}
