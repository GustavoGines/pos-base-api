<?php

namespace Tests\Feature;

use App\Exports\ExpensesAnalysisExport;
use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\SalesAnalyticsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
            'user_id' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/audit/stock?product_id={$product->id}");
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_r02_profit_by_category()
    {
        $response = $this->getJson('/api/reports/sales-by-category?start_date=2024-01-01&end_date=2025-01-01');
        $response->assertStatus(200);
    }

    public function test_r03_profit_by_brand()
    {
        $response = $this->getJson('/api/reports/sales-by-brand?start_date=2024-01-01&end_date=2025-01-01');
        $response->assertStatus(200);
    }

    public function test_r04_internal_consumption()
    {
        $response = $this->getJson('/api/reports/internal-consumption?start_date=2024-01-01&end_date=2025-01-01');
        $response->assertStatus(200);
    }

    public function test_r05_monthly_balance()
    {
        $response = $this->getJson('/api/reports/monthly-balance?start_month=2024-01&end_month=2025-01');
        $response->assertStatus(200);
    }

    public function test_r06_export_profit_by_category_excel(): void
    {
        $response = $this->get('/api/reports/sales-by-category/export?start_date=2026-01-01&end_date=2026-12-31');
        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_r07_export_monthly_balance_excel_and_verifications(): void
    {
        // 1. Probar descarga de Excel (no debe arrojar syntax error en SQLite)
        $response = $this->get('/api/reports/monthly-balance/export?start_month=2026-01&end_month=2026-12');
        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // 2. Verificar que el repositorio excluye cuentas internas y deduce egresos
        $category = Category::create(['name' => 'Ferretería']);
        $product = Product::create([
            'name' => 'Martillo',
            'internal_code' => 'MAR01',
            'selling_price' => 100.00,
            'cost_price' => 40.00,
            'category_id' => $category->id,
            'stock' => 50,
        ]);

        $internalCustomer = Customer::create([
            'name' => 'Consumo Interno Taller',
            'document_number' => '99999999',
            'is_internal_account' => true,
        ]);

        $normalCustomer = Customer::create([
            'name' => 'Cliente Común',
            'document_number' => '12345678',
            'is_internal_account' => false,
        ]);

        $currentMonth = now()->format('Y-m');

        // Venta a cuenta interna ($100 facturación)
        $saleInternal = Sale::create([
            'total' => 100.00,
            'customer_id' => $internalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $saleInternal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 100.00,
            'unit_cost_price' => 40.00,
            'subtotal' => 100.00,
        ]);

        // Venta a cliente común ($200 facturación, costo $80, ganancia bruta $120)
        $saleNormal = Sale::create([
            'total' => 200.00,
            'customer_id' => $normalCustomer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $saleNormal->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => 100.00,
            'unit_cost_price' => 40.00,
            'subtotal' => 200.00,
        ]);

        $register = CashRegister::create(['name' => 'Caja 1']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // Egreso de caja en el mes actual ($20)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 20.00,
            'description' => 'Compra artículos de limpieza',
        ]);

        $repo = app(SalesAnalyticsRepository::class);
        $balance = $repo->getMonthlyBalance($currentMonth, $currentMonth);

        $mes = collect($balance['months'])->firstWhere('period', $currentMonth);
        $this->assertNotNull($mes);

        // La facturación debe ser SOLO la del cliente normal ($200), excluyendo los $100 de la cuenta interna
        $this->assertEquals(200.00, (float) $mes['total_revenue'], 'Debe excluir ventas a cuentas internas');

        // La ganancia bruta ($200 - $80 = $120) menos el egreso ($20) debe resultar en $100 neta
        $this->assertEquals(100.00, (float) $mes['total_profit'], 'Debe deducir los egresos de caja de la ganancia');
    }

    public function test_r08_expenses_analysis_includes_supplier_payments_and_exports(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $supplier = Supplier::create(['name' => 'Proveedor Mayorista', 'cuit' => '30999888776']);
        $expenseCategory = ExpenseCategory::create(['name' => 'Servicios']);

        $register = CashRegister::create(['name' => 'Caja Reportes']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // 1. Gasto común ($500)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $expenseCategory->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 500.00,
            'description' => 'Pago de luz',
            'created_at' => now(),
        ]);

        // 2. Pago a proveedor ($1200)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'type' => 'supplier_payment',
            'amount' => 1200.00,
            'description' => 'Abono de factura de insumos',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        // Verificar Endpoint JSON
        $response = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}");
        $response->assertStatus(200)
            ->assertJsonPath('total_expenses', 1700);

        $categories = collect($response->json('by_category'));
        $supplierCategory = $categories->firstWhere('category', 'Pago a Proveedor');
        $servicesCategory = $categories->firstWhere('category', 'Servicios');

        $this->assertNotNull($supplierCategory, 'Debe incluir la categoría Pago a Proveedor');
        $this->assertEquals(1200.00, (float) $supplierCategory['amount']);
        $this->assertNotNull($servicesCategory, 'Debe incluir la categoría Servicios');
        $this->assertEquals(500.00, (float) $servicesCategory['amount']);

        // Verificar Exportación a Excel
        $excelResponse = $this->get("/api/reports/expenses-analysis/export?start_date={$today}&end_date={$today}");
        $excelResponse->assertStatus(200);

        // Verificar Exportación a PDF
        $pdfResponse = $this->get("/api/reports/expenses-analysis/pdf?start_date={$today}&end_date={$today}");
        $pdfResponse->assertStatus(200);
    }

    public function test_r09_expenses_analysis_with_include_suppliers_false_excludes_supplier_payments(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $supplier = Supplier::create(['name' => 'Proveedor Alimentos', 'cuit' => '30111222334']);
        $expenseCategory = ExpenseCategory::create(['name' => 'Mantenimiento']);

        $register = CashRegister::create(['name' => 'Caja 2']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $expenseCategory->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 600.00,
            'description' => 'Reparación de aire acondicionado',
            'created_at' => now(),
        ]);

        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'type' => 'supplier_payment',
            'amount' => 1500.00,
            'description' => 'Pago mercadería',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        // 1. Con include_suppliers=false
        $response = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&include_suppliers=false");
        $response->assertStatus(200)
            ->assertJsonPath('total_expenses', 600);

        $categories = collect($response->json('by_category'));
        $this->assertNull($categories->firstWhere('category', 'Pago a Proveedor'), 'Pago a Proveedor debe ser excluido');
        $maintenanceCategory = $categories->firstWhere('category', 'Mantenimiento');
        $this->assertNotNull($maintenanceCategory);
        $this->assertEquals(600.00, (float) $maintenanceCategory['amount']);

        // 2. Con include_suppliers=0 (string '0' debe ser interpretado como false)
        $responseZero = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&include_suppliers=0");
        $responseZero->assertStatus(200)
            ->assertJsonPath('total_expenses', 600);
        $this->assertNull(collect($responseZero->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // 3. Con include_suppliers=true (por defecto o explícito) debe volver a incluirlo
        $responseTrue = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&include_suppliers=true");
        $responseTrue->assertStatus(200)
            ->assertJsonPath('total_expenses', 2100);
        $this->assertNotNull(collect($responseTrue->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));
    }

    public function test_r10_expenses_analysis_with_payment_method_filter_and_normalization(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $category = ExpenseCategory::create(['name' => 'Gastos Generales']);
        $register = CashRegister::create(['name' => 'Caja Metodos']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // Movimiento 1: Efectivo ($300)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 300.00,
            'description' => 'Gasto efectivo',
            'created_at' => now(),
        ]);

        // Movimiento 2: Transferencia ($700)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 700.00,
            'description' => 'Gasto transferencia',
            'created_at' => now(),
        ]);

        // Movimiento 3: Cheque ($1000)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'check',
            'type' => 'expense',
            'amount' => 1000.00,
            'description' => 'Gasto cheque',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        // Filtrar por 'cash' y alias 'efectivo'
        $respCash = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=cash");
        $respCash->assertStatus(200)->assertJsonPath('total_expenses', 300);

        $respEfectivo = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=efectivo");
        $respEfectivo->assertStatus(200)->assertJsonPath('total_expenses', 300);

        // Filtrar por 'transfer' y alias 'transferencia'
        $respTransfer = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=transfer");
        $respTransfer->assertStatus(200)->assertJsonPath('total_expenses', 700);

        $respTransferencia = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=transferencia");
        $respTransferencia->assertStatus(200)->assertJsonPath('total_expenses', 700);

        // Filtrar por 'check' y alias 'cheque'
        $respCheck = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=check");
        $respCheck->assertStatus(200)->assertJsonPath('total_expenses', 1000);

        $respCheque = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=cheque");
        $respCheque->assertStatus(200)->assertJsonPath('total_expenses', 1000);

        // Filtrar por 'all' (debe incluir todos: 300 + 700 + 1000 = 2000)
        $respAll = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&payment_method=all");
        $respAll->assertStatus(200)->assertJsonPath('total_expenses', 2000);
    }

    public function test_r11_expenses_analysis_with_min_amount_filter(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $category = ExpenseCategory::create(['name' => 'Varios']);
        $register = CashRegister::create(['name' => 'Caja Montos']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // Movimiento menor a 1000 ($999.00)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 999.00,
            'description' => 'Micro-gasto',
            'created_at' => now(),
        ]);

        // Movimiento exacto de 1000 ($1000.00)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 1000.00,
            'description' => 'Gasto umbral',
            'created_at' => now(),
        ]);

        // Movimiento mayor a 1000 ($2500.00)
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 2500.00,
            'description' => 'Gasto mayor',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        // Al colocar min_amount de 1000, los registros de $999 o menos no suman al total_expenses
        $response = $this->getJson("/api/reports/expenses-analysis?start_date={$today}&end_date={$today}&min_amount=1000");
        $response->assertStatus(200)
            ->assertJsonPath('total_expenses', 3500);

        $catData = collect($response->json('by_category'))->firstWhere('category', 'Varios');
        $this->assertNotNull($catData);
        $this->assertEquals(2, $catData['transactions'], 'Solo 2 movimientos deben superar o igualar el umbral de 1000');
        $this->assertEquals(3500.00, (float) $catData['amount']);
    }

    public function test_r12_export_expenses_analysis_excel_with_filters(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $supplier = Supplier::create(['name' => 'Proveedor Excel', 'cuit' => '30333444556']);
        $cat1 = ExpenseCategory::create(['name' => 'Oficina']);
        $cat2 = ExpenseCategory::create(['name' => 'Logística']);

        $register = CashRegister::create(['name' => 'Caja Excel']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // 1. $400 efectivo - Oficina
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat1->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 400.00,
            'description' => 'Papelería',
            'created_at' => now(),
        ]);

        // 2. $1500 transferencia - Logística
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat2->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 1500.00,
            'description' => 'Flete',
            'created_at' => now(),
        ]);

        // 3. $3000 transferencia - Pago Proveedor
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 3000.00,
            'description' => 'Pago proveedor insumos',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        // 1. Verificar endpoint HTTP de exportación con filtros
        $response = $this->get("/api/reports/expenses-analysis/export?start_date={$today}&end_date={$today}&include_suppliers=false&payment_method=transfer&min_amount=1000");
        $response->assertStatus(200);
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('analisis_gastos_', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        // 2. Verificar datos de colección de ExpensesAnalysisExport con filtros aplicados
        $export = new ExpensesAnalysisExport($today, $today, false, 'transfer', 1000);
        $collection = $export->collection();

        $this->assertCount(1, $collection, 'Solo debe contener la categoría Logística');
        $this->assertEquals('Logística', $collection->first()->category_name);
        $this->assertEquals(1500.00, (float) $collection->first()->total_amount);
        $this->assertEquals(1, (int) $collection->first()->transactions);
    }

    public function test_r13_export_expenses_analysis_pdf_with_filters(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $supplier = Supplier::create(['name' => 'Proveedor PDF', 'cuit' => '30444555667']);
        $cat = ExpenseCategory::create(['name' => 'Servicios Web']);

        $register = CashRegister::create(['name' => 'Caja PDF']);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        // 1. $200 efectivo - Descartado por min_amount
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 200.00,
            'description' => 'Micro pago',
            'created_at' => now(),
        ]);

        // 2. $1200 efectivo - Debe coincidir
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 1200.00,
            'description' => 'Hosting anual',
            'created_at' => now(),
        ]);

        // 3. $5000 transferencia - Descartado por include_suppliers y payment_method
        CashMovement::create([
            'cash_shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 5000.00,
            'description' => 'Pago proveedor',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        $pdfResponse = $this->get("/api/reports/expenses-analysis/pdf?start_date={$today}&end_date={$today}&include_suppliers=false&payment_method=cash&min_amount=1000");
        $pdfResponse->assertStatus(200);
        $pdfResponse->assertHeader('content-disposition');
        $this->assertStringContainsString('analisis_gastos_', $pdfResponse->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', $pdfResponse->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());
    }
}
