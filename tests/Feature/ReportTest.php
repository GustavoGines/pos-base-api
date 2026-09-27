<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\StockMovement;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
    }

    public function test_r01_stock_kardex_report()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'R01', 'cost_price' => 10, 'selling_price' => 20]);
        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
            'user_id' => $this->admin->id
        ]);

        $response = $this->getJson("/api/audit/stock?product_id={$product->id}");
        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data');
    }

    public function test_r02_profit_by_category()
    {
        $response = $this->getJson("/api/reports/sales-by-category?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r03_profit_by_brand()
    {
        $response = $this->getJson("/api/reports/sales-by-brand?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r04_internal_consumption()
    {
        $response = $this->getJson("/api/reports/internal-consumption?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r05_monthly_balance()
    {
        $response = $this->getJson("/api/reports/monthly-balance?start_month=2024-01&end_month=2025-01");
        $response->assertStatus(200);
    }

    public function test_r06_export_profit_by_category_excel(): void
    {
        $response = $this->get("/api/reports/sales-by-category/export?start_date=2026-01-01&end_date=2026-12-31");
        $response->assertStatus(200)
                 ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_r07_export_monthly_balance_excel_and_verifications(): void
    {
        // 1. Probar descarga de Excel (no debe arrojar syntax error en SQLite)
        $response = $this->get("/api/reports/monthly-balance/export?start_month=2026-01&end_month=2026-12");
        $response->assertStatus(200)
                 ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // 2. Verificar que el repositorio excluye cuentas internas y deduce egresos
        $category = \App\Models\Category::create(['name' => 'Ferretería']);
        $product = Product::create([
            'name'          => 'Martillo',
            'internal_code' => 'MAR01',
            'selling_price' => 100.00,
            'cost_price'    => 40.00,
            'category_id'   => $category->id,
            'stock'         => 50,
        ]);

        $internalCustomer = \App\Models\Customer::create([
            'name'                => 'Consumo Interno Taller',
            'document_number'     => '99999999',
            'is_internal_account' => true,
        ]);

        $normalCustomer = \App\Models\Customer::create([
            'name'                => 'Cliente Común',
            'document_number'     => '12345678',
            'is_internal_account' => false,
        ]);

        $currentMonth = now()->format('Y-m');

        // Venta a cuenta interna ($100 facturación)
        $saleInternal = \App\Models\Sale::create([
            'total'          => 100.00,
            'customer_id'    => $internalCustomer->id,
            'status'         => 'completed',
            'payment_status' => 'paid',
        ]);
        $saleInternal->items()->create([
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'quantity'        => 1,
            'unit_price'      => 100.00,
            'unit_cost_price' => 40.00,
            'subtotal'        => 100.00,
        ]);

        // Venta a cliente común ($200 facturación, costo $80, ganancia bruta $120)
        $saleNormal = \App\Models\Sale::create([
            'total'          => 200.00,
            'customer_id'    => $normalCustomer->id,
            'status'         => 'completed',
            'payment_status' => 'paid',
        ]);
        $saleNormal->items()->create([
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'quantity'        => 2,
            'unit_price'      => 100.00,
            'unit_cost_price' => 40.00,
            'subtotal'        => 200.00,
        ]);

        $register = \App\Models\CashRegister::create(['name' => 'Caja 1']);
        $shift = \App\Models\CashShift::create([
            'cash_register_id' => $register->id,
            'user_id'          => $this->admin->id,
            'status'           => 'open',
            'opening_balance'  => 0,
            'opened_at'        => now(),
        ]);

        // Egreso de caja en el mes actual ($20)
        \App\Models\CashMovement::create([
            'cash_shift_id'  => $shift->id,
            'user_id'        => $this->admin->id,
            'payment_method' => 'cash',
            'type'           => 'expense',
            'amount'         => 20.00,
            'description'    => 'Compra artículos de limpieza',
        ]);

        $repo = app(\App\Repositories\SalesAnalyticsRepository::class);
        $balance = $repo->getMonthlyBalance($currentMonth, $currentMonth);

        $mes = collect($balance['months'])->firstWhere('period', $currentMonth);
        $this->assertNotNull($mes);

        // La facturación debe ser SOLO la del cliente normal ($200), excluyendo los $100 de la cuenta interna
        $this->assertEquals(200.00, (float) $mes['total_revenue'], 'Debe excluir ventas a cuentas internas');

        // La ganancia bruta ($200 - $80 = $120) menos el egreso ($20) debe resultar en $100 neta
        $this->assertEquals(100.00, (float) $mes['total_profit'], 'Debe deducir los egresos de caja de la ganancia');
    }
}
