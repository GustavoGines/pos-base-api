<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiCajaAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected CashRegister $reg1;

    protected CashRegister $reg2;

    protected CashShift $shift1;

    protected CashShift $shift2;

    protected PaymentMethod $cashMethod;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true, 'multi_caja' => true, 'current_accounts' => true])]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'app_plan'],
            ['value' => 'pro']
        );

        $this->admin = User::factory()->create([
            'name' => 'Admin MultiCaja',
            'role' => 'admin',
            'pin' => Hash::make('1234'),
            'permissions' => ['all'],
            'session_token' => 'test-multicaja-admin-token',
        ]);

        $this->withHeaders([
            'X-Session-Token' => $this->admin->session_token,
        ]);

        $this->reg1 = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $this->reg2 = CashRegister::create(['name' => 'Caja Secundaria', 'is_active' => true]);

        $this->shift1 = CashShift::create([
            'cash_register_id' => $this->reg1->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 10000,
            'opened_at' => now(),
        ]);

        $this->shift2 = CashShift::create([
            'cash_register_id' => $this->reg2->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 5000,
            'opened_at' => now(),
        ]);

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            [
                'name' => 'Efectivo',
                'is_cash' => true,
                'is_active' => true,
                'surcharge_type' => 'none',
                'surcharge_value' => 0,
                'sort_order' => 1,
            ]
        );

        $cat = Category::create(['name' => 'General']);
        $this->product = Product::create([
            'name' => 'Producto Test',
            'internal_code' => 'PT001',
            'cost_price' => 500,
            'selling_price' => 1000,
            'stock' => 50,
            'active' => true,
            'is_sold_by_weight' => false,
            'category_id' => $cat->id,
        ]);
    }

    public function test_mc02_cash_movements_index_includes_cash_register_and_filters_by_register_in_all_mode(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        // Retiro en Caja 2 ($1500)
        CashMovement::create([
            'cash_shift_id' => $this->shift2->id,
            'user_id' => $this->admin->id,
            'amount' => 1500,
            'payment_method' => 'cash',
            'type' => 'withdrawal',
            'category' => 'Retiro',
            'description' => 'Retiro Caja 2',
        ]);

        // Gasto en Caja 1 ($800)
        CashMovement::create([
            'cash_shift_id' => $this->shift1->id,
            'user_id' => $this->admin->id,
            'amount' => 800,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'Limpieza',
            'description' => 'Gasto Caja 1',
        ]);

        // 1. Historial completo sin filtro trae ambos con relación cash_shift.cash_register
        $respAll = $this->getJson('/api/cash-movements?all=1');
        $respAll->assertStatus(200)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('kpi_total_out', 2300);

        $firstItem = $respAll->json('data.0');
        $this->assertNotEmpty($firstItem['cash_shift']['cash_register']['name'] ?? null);

        // 2. Historial completo filtrado por Caja 2
        $respReg2 = $this->getJson("/api/cash-movements?all=1&cash_register_id={$this->reg2->id}");
        $respReg2->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('kpi_total_out', 1500)
            ->assertJsonPath('data.0.cash_shift.cash_register.name', 'Caja Secundaria');

        // 3. Filtro inteligente por categoría "Retiro de Caja" debe coincidir con category="Retiro" / type="withdrawal"
        $respCat = $this->getJson('/api/cash-movements?all=1&category=' . urlencode('Retiro de Caja'));
        $respCat->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_mc04_expenses_analysis_includes_withdrawals_and_register_info_and_supports_register_filter(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        CashMovement::create([
            'cash_shift_id' => $this->shift2->id,
            'user_id' => $this->admin->id,
            'amount' => 1500,
            'payment_method' => 'cash',
            'type' => 'withdrawal',
            'category' => 'Retiro',
            'description' => 'Alivio Caja 2',
        ]);

        // Un movimiento automático de refund (por anulación de venta cerrada) NO debe contaminar el reporte de gastos
        $refundMov = CashMovement::create([
            'cash_shift_id' => $this->shift1->id,
            'user_id' => $this->admin->id,
            'amount' => 800,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'refund',
            'description' => 'Devolución por anulación de Venta #99',
        ]);

        // Tampoco debe poder borrarse manualmente desde Movimientos de Caja (HTTP 403)
        $delRefund = $this->deleteJson("/api/cash-movements/{$refundMov->id}");
        $delRefund->assertStatus(403);

        $today = now()->toDateString();

        // Por defecto incluye withdrawals y normaliza 'Retiro' -> 'Retiro de Caja', excluyendo 'refund'
        $resp = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}");
        $resp->assertStatus(200)
            ->assertJsonPath('total_expenses', 1500);

        $byCat = collect($resp->json('by_category'));
        $retiroCat = $byCat->firstWhere('category', 'Retiro de Caja');
        $this->assertNotNull($retiroCat);
        $this->assertEquals('Caja Secundaria', $retiroCat['movements'][0]['cash_register_name']);
        $this->assertNull($byCat->firstWhere('category', 'refund'));

        // Filtrado por Caja 1 da 0
        $respReg1 = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&cash_register_id={$this->reg1->id}");
        $respReg1->assertStatus(200)
            ->assertJsonPath('total_expenses', 0);

        // Con include_withdrawals=false da 0
        $respNoWith = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&include_withdrawals=false");
        $respNoWith->assertStatus(200)
            ->assertJsonPath('total_expenses', 0);
    }

    public function test_mc05_voiding_sale_from_another_open_shift_does_not_double_deduct_cash(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        // Venta en efectivo en Caja 2 (shift2 abierto) por $1000
        $saleRes = $this->postJson('/api/pos/sales', [
            'total' => 1000,
            'total_surcharge' => 0,
            'cash_shift_id' => $this->shift2->id,
            'tendered_amount' => 1000,
            'change_amount' => 0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->name,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'subtotal' => 1000,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'base_amount' => 1000,
                    'surcharge_amount' => 0,
                    'total_amount' => 1000,
                ],
            ],
        ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('sale.id');

        // Admin anula la venta desde Caja 1 (enviando cash_shift_id = shift1->id) mientras shift2 SIGUE ABIERTO
        $voidRes = $this->postJson("/api/sales/{$saleId}/void", [
            'cash_shift_id' => $this->shift1->id,
            'void_reason' => 'Error de carga',
        ]);
        $voidRes->assertStatus(200);

        // Verificar que NO se creó un CashMovement de refund en Caja 1 porque shift2 sigue abierto
        $this->assertEquals(0, CashMovement::where('cash_shift_id', $this->shift1->id)->where('category', 'refund')->count());

        // Verificar balances esperados:
        // Caja 1 sigue con sus $10,000 iniciales intactos
        // Caja 2 vuelve a sus $5,000 iniciales (la venta pasó a voided)
        $shiftService = app(CashShiftService::class);
        $totals1 = $shiftService->calculateLiveTotals($this->shift1);
        $totals2 = $shiftService->calculateLiveTotals($this->shift2);

        $this->assertEquals(10000.0, (float) $totals1['expected_balance']);
        $this->assertEquals(5000.0, (float) $totals2['expected_balance']);
    }

    public function test_mc03_and_mc08_pending_and_sales_index_include_cash_register_and_support_register_filter(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        // Crear orden en espera en Caja 1
        $pendingSale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $this->shift1->id,
            'total' => 1000,
            'status' => 'pending',
        ]);

        // Crear venta completada en Caja 2
        $completedSale = Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $this->shift2->id,
            'total' => 2000,
            'status' => 'completed',
        ]);

        $pendingResp = $this->getJson('/api/sales/pending');
        $pendingResp->assertStatus(200)
            ->assertJsonPath('0.cash_shift.cash_register.name', 'Caja Principal');

        // Filtrar pendientes por Caja 2 debe devolver vacío
        $pendingReg2 = $this->getJson("/api/sales/pending?cash_register_id={$this->reg2->id}");
        $pendingReg2->assertStatus(200)
            ->assertJsonCount(0);

        // Historial period=today incluye cash_shift.cash_register y soporta filtro cash_register_id
        $salesResp = $this->getJson("/api/sales?period=today&cash_register_id={$this->reg2->id}");
        $salesResp->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $completedSale->id)
            ->assertJsonPath('0.cash_shift.cash_register.name', 'Caja Secundaria');
    }

    public function test_mc07_customer_payment_resolves_shift_by_cash_register_id(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $customer = Customer::create([
            'name' => 'Cliente Cta Cte',
            'balance' => 3000,
            'credit_limit' => 10000,
        ]);

        // Enviamos cash_register_id de Caja 1 (aunque Caja 2 sea el turno con latest('id'))
        $payResp = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 1000,
            'payment_method' => 'cash',
            'cash_register_id' => $this->reg1->id,
        ]);

        $payResp->assertStatus(200)
            ->assertJsonPath('transaction.cash_shift_id', $this->shift1->id);
    }
}
