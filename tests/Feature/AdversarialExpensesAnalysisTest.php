<?php

namespace Tests\Feature;

use App\Exports\ExpensesAnalysisExport;
use App\Models\BusinessSetting;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdversarialExpensesAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected $shift;

    protected $today;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable expenses license feature
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['expenses' => true])]
        );

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        $register = CashRegister::create(['name' => 'Caja Adversarial']);
        $this->shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        $this->today = now()->toDateString();
    }

    /**
     * Objective 1: Test boundary conditions for min_amount: 999.99 (excluded) vs 1000.00 (included).
     */
    public function test_adv01_min_amount_strict_boundary_conditions(): void
    {
        $category = ExpenseCategory::create(['name' => 'Categoría Frontera']);

        // Gasto A: $999.99 (Boundary Just Below 1000)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 999.99,
            'description' => 'Gasto de 999.99',
            'created_at' => now(),
        ]);

        // Gasto B: $1000.00 (Exact Boundary Threshold)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 1000.00,
            'description' => 'Gasto de 1000.00 exactos',
            'created_at' => now(),
        ]);

        // Gasto C: $1000.01 (Boundary Just Above 1000)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 1000.01,
            'description' => 'Gasto de 1000.01',
            'created_at' => now(),
        ]);

        // Gasto D: $0.01 (Micro-gasto extremo)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 0.01,
            'description' => 'Micro centavo',
            'created_at' => now(),
        ]);

        // 1. Boundary check: min_amount=1000.00
        // Must EXCLUDE 999.99 and 0.01. Must INCLUDE 1000.00 and 1000.01.
        $res1000 = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=1000.00");
        $res1000->assertStatus(200);
        $this->assertEquals(2000.01, (float) $res1000->json('total_expenses'));

        $catData1000 = collect($res1000->json('by_category'))->firstWhere('category', 'Categoría Frontera');
        $this->assertNotNull($catData1000);
        $this->assertEquals(2, $catData1000['transactions'], 'Solo 2 movimientos deben superar o igualar $1000.00');
        $this->assertEquals(2000.01, (float) $catData1000['amount']);

        // Check movements array inside category
        $movements = collect($catData1000['movements']);
        $this->assertTrue($movements->contains('amount', 1000.00), 'Debe incluir 1000.00');
        $this->assertTrue($movements->contains('amount', 1000.01), 'Debe incluir 1000.01');
        $this->assertFalse($movements->contains('amount', 999.99), 'Debe excluir 999.99');
        $this->assertFalse($movements->contains('amount', 0.01), 'Debe excluir 0.01');

        // 2. Boundary check: min_amount=999.99
        // Must INCLUDE 999.99, 1000.00, and 1000.01. Must EXCLUDE 0.01.
        $res999 = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=999.99");
        $res999->assertStatus(200);
        $this->assertEquals(3000.00, (float) $res999->json('total_expenses'));

        $catData999 = collect($res999->json('by_category'))->firstWhere('category', 'Categoría Frontera');
        $this->assertEquals(3, $catData999['transactions']);
        $this->assertEquals(3000.00, (float) $catData999['amount']);

        // 3. Edge check: min_amount=0 (should not filter, all 4 included)
        $res0 = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=0");
        $res0->assertStatus(200);
        $this->assertEquals(3000.01, (float) $res0->json('total_expenses'));

        // 4. Edge check: min_amount=-50 (negative, should not filter)
        $resNeg = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=-50");
        $resNeg->assertStatus(200);
        $this->assertEquals(3000.01, (float) $resNeg->json('total_expenses'));

        // 5. Edge check: min_amount=invalid_string (non-numeric, should not filter)
        $resStr = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=invalid");
        $resStr->assertStatus(200);
        $this->assertEquals(3000.01, (float) $resStr->json('total_expenses'));

        // 6. Edge check: min_amount=999999 (unreachable threshold, empty response)
        $resHigh = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=999999");
        $resHigh->assertStatus(200);
        $this->assertEquals(0.00, (float) $resHigh->json('total_expenses'));
        $this->assertEquals([], $resHigh->json('by_category'));
    }

    /**
     * Objective 2: Test boolean variations for include_suppliers: "false", "0", "true", "1", "", missing.
     */
    public function test_adv02_include_suppliers_boolean_variations_matrix(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Matriz', 'cuit' => '30888999112']);
        $category = ExpenseCategory::create(['name' => 'Suministros']);

        // Expense: $700.00
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $category->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 700.00,
            'description' => 'Compra suministros oficina',
            'created_at' => now(),
        ]);

        // Supplier Payment: $1300.00
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'type' => 'supplier_payment',
            'amount' => 1300.00,
            'description' => 'Pago factura proveedor',
            'created_at' => now(),
        ]);

        // A. include_suppliers="false" -> Excludes supplier payments
        $resFalse = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=false");
        $resFalse->assertStatus(200);
        $this->assertEquals(700.00, (float) $resFalse->json('total_expenses'));
        $this->assertNull(collect($resFalse->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // B. include_suppliers="0" -> Excludes supplier payments
        $resZero = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=0");
        $resZero->assertStatus(200);
        $this->assertEquals(700.00, (float) $resZero->json('total_expenses'));
        $this->assertNull(collect($resZero->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // C. include_suppliers="FALSE" (uppercase) -> Excludes supplier payments
        $resFalseUpper = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=FALSE");
        $resFalseUpper->assertStatus(200);
        $this->assertEquals(700.00, (float) $resFalseUpper->json('total_expenses'));
        $this->assertNull(collect($resFalseUpper->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // D. include_suppliers="true" -> Includes supplier payments
        $resTrue = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=true");
        $resTrue->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resTrue->json('total_expenses'));
        $this->assertNotNull(collect($resTrue->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // E. include_suppliers="1" -> Includes supplier payments
        $resOne = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=1");
        $resOne->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resOne->json('total_expenses'));
        $this->assertNotNull(collect($resOne->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // F. include_suppliers="TRUE" (uppercase) -> Includes supplier payments
        $resTrueUpper = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=TRUE");
        $resTrueUpper->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resTrueUpper->json('total_expenses'));
        $this->assertNotNull(collect($resTrueUpper->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // G. include_suppliers="" (empty string) -> Defaults to true
        $resEmpty = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=");
        $resEmpty->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resEmpty->json('total_expenses'));
        $this->assertNotNull(collect($resEmpty->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));

        // H. missing include_suppliers parameter -> Defaults to true
        $resMissing = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}");
        $resMissing->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resMissing->json('total_expenses'));
        $this->assertNotNull(collect($resMissing->json('by_category'))->firstWhere('category', 'Pago a Proveedor'));
    }

    /**
     * Objective 3: Test combinations of all filters simultaneously
     * (include_suppliers=false + payment_method=transfer + min_amount=1000)
     */
    public function test_adv03_simultaneous_filter_combinations_matrix(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Combinado', 'cuit' => '30777888990']);
        $catLogistics = ExpenseCategory::create(['name' => 'Logística']);
        $catOffice = ExpenseCategory::create(['name' => 'Oficina']);

        // 1. [TARGET TO MATCH] expense, transfer, 1500.00 -> MATCHES ALL 3
        $targetMovement = CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catLogistics->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 1500.00,
            'description' => 'Flete principal',
            'created_at' => now(),
        ]);

        // 2. expense, transfer, 999.99 -> FAILS min_amount
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catLogistics->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 999.99,
            'description' => 'Flete menor',
            'created_at' => now(),
        ]);

        // 3. expense, cash, 1500.00 -> FAILS payment_method
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catOffice->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 1500.00,
            'description' => 'Mobiliario efectivo',
            'created_at' => now(),
        ]);

        // 4. expense, check, 1500.00 -> FAILS payment_method
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catOffice->id,
            'payment_method' => 'check',
            'type' => 'expense',
            'amount' => 1500.00,
            'description' => 'Mobiliario cheque',
            'created_at' => now(),
        ]);

        // 5. expense, cash, 500.00 -> FAILS payment_method + min_amount
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catOffice->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 500.00,
            'description' => 'Papelería',
            'created_at' => now(),
        ]);

        // 6. supplier_payment, transfer, 2500.00 -> FAILS include_suppliers
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 2500.00,
            'description' => 'Factura proveedor alta',
            'created_at' => now(),
        ]);

        // 7. supplier_payment, transfer, 999.99 -> FAILS include_suppliers + min_amount
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 999.99,
            'description' => 'Factura proveedor baja',
            'created_at' => now(),
        ]);

        // 8. supplier_payment, cash, 3000.00 -> FAILS include_suppliers + payment_method
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'type' => 'supplier_payment',
            'amount' => 3000.00,
            'description' => 'Factura proveedor efectivo',
            'created_at' => now(),
        ]);

        // 9. withdrawal, transfer, 5000.00 -> NON-EXPENSE TYPE (MUST BE EXCLUDED)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'transfer',
            'type' => 'withdrawal',
            'amount' => 5000.00,
            'category' => 'Retiro de Dueño',
            'description' => 'Retiro',
            'created_at' => now(),
        ]);

        // 10. deposit, transfer, 5000.00 -> NON-EXPENSE TYPE (MUST BE EXCLUDED)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'payment_method' => 'transfer',
            'type' => 'deposit',
            'amount' => 5000.00,
            'category' => 'Depósito Inicial',
            'description' => 'Depósito',
            'created_at' => now(),
        ]);

        // 11. expense, transfer, 1500.00 -> SOFT DELETED (MUST BE EXCLUDED)
        $deletedMovement = CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $catLogistics->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 1500.00,
            'description' => 'Flete cancelado',
            'created_at' => now(),
        ]);
        $deletedMovement->delete();

        // EXECUTE COMBINATION QUERY ON JSON ENDPOINT
        $response = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=false&payment_method=transfer&min_amount=1000");

        $response->assertStatus(200);
        $this->assertEquals(1500.00, (float) $response->json('total_expenses'));

        $categories = $response->json('by_category');
        $this->assertCount(1, $categories, 'Debe haber exactamente 1 categoría resultante');
        $this->assertEquals('Logística', $categories[0]['category']);
        $this->assertEquals(1500.00, (float) $categories[0]['amount']);
        $this->assertEquals(1, (int) $categories[0]['transactions']);
        $this->assertEquals(100.0, (float) $categories[0]['percentage']);
        $this->assertCount(1, $categories[0]['movements']);
        $this->assertEquals($targetMovement->id, $categories[0]['movements'][0]['id']);

        // EXECUTE COMBINATION QUERY ON EXCEL EXPORT ENDPOINT & COLLECTION
        $excelResponse = $this->get("/api/reports/expenses-analysis/export?start_date={$this->today}&end_date={$this->today}&include_suppliers=false&payment_method=transfer&min_amount=1000");
        $excelResponse->assertStatus(200);

        $export = new ExpensesAnalysisExport($this->today, $this->today, false, 'transfer', 1000);
        $exportCollection = $export->collection();
        $this->assertCount(1, $exportCollection);
        $this->assertEquals('Logística', $exportCollection->first()->category_name);
        $this->assertEquals(1500.00, (float) $exportCollection->first()->total_amount);
        $this->assertEquals(1, (int) $exportCollection->first()->transactions);

        // EXECUTE COMBINATION QUERY ON PDF EXPORT ENDPOINT
        $pdfResponse = $this->get("/api/reports/expenses-analysis/pdf?start_date={$this->today}&end_date={$this->today}&include_suppliers=false&payment_method=transfer&min_amount=1000");
        $pdfResponse->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());
    }

    /**
     * Objective 4: Test Spanish aliases normalization for payment_method
     * (efectivo, transferencia, cheque) vs DB enums (cash, transfer, check).
     */
    public function test_adv04_payment_method_normalization_and_spanish_aliases(): void
    {
        $cat = ExpenseCategory::create(['name' => 'Operativos']);

        // Cash: $110.00
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 110.00,
            'description' => 'Pago efectivo',
            'created_at' => now(),
        ]);

        // Transfer: $220.00
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 220.00,
            'description' => 'Pago transferencia',
            'created_at' => now(),
        ]);

        // Check: $330.00
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'check',
            'type' => 'expense',
            'amount' => 330.00,
            'description' => 'Pago cheque',
            'created_at' => now(),
        ]);

        // 1. Cash / Efectivo variants
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=cash");
        $res->assertStatus(200);
        $this->assertEquals(110.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=efectivo");
        $res->assertStatus(200);
        $this->assertEquals(110.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=EFECTIVO");
        $res->assertStatus(200);
        $this->assertEquals(110.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=Efectivo");
        $res->assertStatus(200);
        $this->assertEquals(110.00, (float) $res->json('total_expenses'));

        // 2. Transfer / Transferencia variants
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=transfer");
        $res->assertStatus(200);
        $this->assertEquals(220.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=transferencia");
        $res->assertStatus(200);
        $this->assertEquals(220.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=TRANSFERENCIA");
        $res->assertStatus(200);
        $this->assertEquals(220.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=Transferencia");
        $res->assertStatus(200);
        $this->assertEquals(220.00, (float) $res->json('total_expenses'));

        // 3. Check / Cheque variants
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=check");
        $res->assertStatus(200);
        $this->assertEquals(330.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=cheque");
        $res->assertStatus(200);
        $this->assertEquals(330.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=CHEQUE");
        $res->assertStatus(200);
        $this->assertEquals(330.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=Cheque");
        $res->assertStatus(200);
        $this->assertEquals(330.00, (float) $res->json('total_expenses'));

        // 4. Trimming whitespace around alias
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=%20efectivo%20");
        $res->assertStatus(200);
        $this->assertEquals(110.00, (float) $res->json('total_expenses'));

        // 5. 'all', empty, or missing should return all (110 + 220 + 330 = 660)
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=all");
        $res->assertStatus(200);
        $this->assertEquals(660.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=");
        $res->assertStatus(200);
        $this->assertEquals(660.00, (float) $res->json('total_expenses'));

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}");
        $res->assertStatus(200);
        $this->assertEquals(660.00, (float) $res->json('total_expenses'));

        // 6. Unknown / Invalid payment method should gracefully default without 500 error
        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method=criptomoneda");
        $res->assertStatus(200);
        $this->assertEquals(660.00, (float) $res->json('total_expenses'));

        // 7. Verify Excel export class handles Spanish alias directly
        $exportCash = new ExpensesAnalysisExport($this->today, $this->today, true, 'efectivo');
        $this->assertEquals(110.00, (float) $exportCash->collection()->first()->total_amount);

        $exportTrans = new ExpensesAnalysisExport($this->today, $this->today, true, 'TRANSFERENCIA');
        $this->assertEquals(220.00, (float) $exportTrans->collection()->first()->total_amount);

        $exportCheck = new ExpensesAnalysisExport($this->today, $this->today, true, 'Cheque');
        $this->assertEquals(330.00, (float) $exportCheck->collection()->first()->total_amount);
    }

    /**
     * Objective 5: Verify PDF and Excel endpoints under edge case parameters.
     */
    public function test_adv05_pdf_and_excel_export_edge_cases(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Edge', 'cuit' => '30666777889']);
        $cat = ExpenseCategory::create(['name' => 'Mantenimiento General']);

        // Record A: $999.99 cash expense (below threshold)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 999.99,
            'description' => 'Mantenimiento menor',
            'created_at' => now(),
        ]);

        // Record B: $1000.00 transfer expense (exact threshold)
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'transfer',
            'type' => 'expense',
            'amount' => 1000.00,
            'description' => 'Mantenimiento mayor',
            'created_at' => now(),
        ]);

        // Record C: $4000.00 transfer supplier_payment
        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 4000.00,
            'description' => 'Pago proveedor repuestos',
            'created_at' => now(),
        ]);

        // Case 1: Empty results edge case (min_amount = 999999)
        // Verify PDF handles zero division without fatal crash
        $pdfEmpty = $this->get("/api/reports/expenses-analysis/pdf?start_date={$this->today}&end_date={$this->today}&min_amount=999999");
        $pdfEmpty->assertStatus(200);
        $pdfEmpty->assertHeader('content-disposition');
        $this->assertStringStartsWith('%PDF-', $pdfEmpty->getContent());

        // Case 2: Excel empty results edge case
        $excelEmpty = $this->get("/api/reports/expenses-analysis/export?start_date={$this->today}&end_date={$this->today}&min_amount=999999");
        $excelEmpty->assertStatus(200);
        $excelEmpty->assertHeader('content-disposition');

        $exportEmptyObj = new ExpensesAnalysisExport($this->today, $this->today, true, null, 999999);
        $this->assertCount(0, $exportEmptyObj->collection(), 'Debe retornar colección vacía');

        // Case 3: Combined filters on Excel export
        // include_suppliers=0, payment_method=transferencia, min_amount=1000
        // Should only match Record B ($1000.00)
        $excelFiltered = $this->get("/api/reports/expenses-analysis/export?start_date={$this->today}&end_date={$this->today}&include_suppliers=0&payment_method=transferencia&min_amount=1000");
        $excelFiltered->assertStatus(200);
        $this->assertStringContainsString('analisis_gastos_', $excelFiltered->headers->get('content-disposition'));
        $this->assertStringContainsString('.xlsx', $excelFiltered->headers->get('content-disposition'));

        $exportFilteredObj = new ExpensesAnalysisExport($this->today, $this->today, false, 'transferencia', 1000);
        $filteredCollection = $exportFilteredObj->collection();
        $this->assertCount(1, $filteredCollection);
        $this->assertEquals('Mantenimiento General', $filteredCollection->first()->category_name);
        $this->assertEquals(1000.00, (float) $filteredCollection->first()->total_amount);
        $this->assertEquals(1, (int) $filteredCollection->first()->transactions);

        // Case 4: Combined filters on PDF export
        $pdfFiltered = $this->get("/api/reports/expenses-analysis/pdf?start_date={$this->today}&end_date={$this->today}&include_suppliers=0&payment_method=transferencia&min_amount=1000");
        $pdfFiltered->assertStatus(200);
        $this->assertStringContainsString('analisis_gastos_', $pdfFiltered->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', $pdfFiltered->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $pdfFiltered->getContent());
    }

    /**
     * Objective 6: Date boundaries (start of day to end of day precision).
     */
    public function test_adv06_date_time_boundaries_precision(): void
    {
        $cat = ExpenseCategory::create(['name' => 'Temporal']);

        $targetDate = '2026-09-15';
        $dayBefore = '2026-09-14 23:59:59';
        $startOfDay = '2026-09-15 00:00:00';
        $endOfDay = '2026-09-15 23:59:59';
        $dayAfter = '2026-09-16 00:00:00';

        // 1. Right before start of day (must be excluded)
        DB::table('cash_movements')->insert([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'Temporal',
            'amount' => 10.00,
            'description' => 'Antes del período',
            'created_at' => Carbon::parse($dayBefore),
            'updated_at' => Carbon::parse($dayBefore),
        ]);

        // 2. Exact start of day (must be included)
        DB::table('cash_movements')->insert([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'Temporal',
            'amount' => 100.00,
            'description' => 'Inicio del período',
            'created_at' => Carbon::parse($startOfDay),
            'updated_at' => Carbon::parse($startOfDay),
        ]);

        // 3. Exact end of day (must be included)
        DB::table('cash_movements')->insert([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'Temporal',
            'amount' => 200.00,
            'description' => 'Fin del período',
            'created_at' => Carbon::parse($endOfDay),
            'updated_at' => Carbon::parse($endOfDay),
        ]);

        // 4. Right after end of day (must be excluded)
        DB::table('cash_movements')->insert([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'category' => 'Temporal',
            'amount' => 30.00,
            'description' => 'Después del período',
            'created_at' => Carbon::parse($dayAfter),
            'updated_at' => Carbon::parse($dayAfter),
        ]);

        $res = $this->getJson("/api/reports/expenses-analysis?start_date={$targetDate}&end_date={$targetDate}");
        $res->assertStatus(200);
        $this->assertEquals(300.00, (float) $res->json('total_expenses'));

        $catData = collect($res->json('by_category'))->firstWhere('category', 'Temporal');
        $this->assertEquals(2, $catData['transactions'], 'Solo los 2 movimientos dentro del rango de 00:00:00 a 23:59:59 deben ser incluidos');
        $this->assertEquals(300.00, (float) $catData['amount']);
    }

    /**
     * Objective 7: Adversarial inputs, injection strings, and type resilience.
     */
    public function test_adv07_malicious_and_malformed_inputs_resilience(): void
    {
        $cat = ExpenseCategory::create(['name' => 'Seguridad']);
        $supplier = Supplier::create(['name' => 'Proveedor Seguro', 'cuit' => '30555666778']);

        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'expense_category_id' => $cat->id,
            'payment_method' => 'cash',
            'type' => 'expense',
            'amount' => 500.00,
            'description' => 'Gasto normal',
            'created_at' => now(),
        ]);

        CashMovement::create([
            'cash_shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'transfer',
            'type' => 'supplier_payment',
            'amount' => 1500.00,
            'description' => 'Pago proveedor normal',
            'created_at' => now(),
        ]);

        // 1. SQL Injection attempt in min_amount
        $resSqlMin = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=100+OR+1%3D1");
        $resSqlMin->assertStatus(200);
        // Non-numeric should ignore filter and include all movements
        $this->assertEquals(2000.00, (float) $resSqlMin->json('total_expenses'));

        // 2. SQL Injection attempt in payment_method
        $resSqlPay = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&payment_method='--+OR+1=1");
        $resSqlPay->assertStatus(200);
        // Unrecognized payment method should map to null and include all
        $this->assertEquals(2000.00, (float) $resSqlPay->json('total_expenses'));

        // 3. SQL Injection attempt in include_suppliers
        $resSqlSupp = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=false;+DROP+TABLE+users;");
        $resSqlSupp->assertStatus(200);
        // filter_var returns false for invalid string -> safely excludes suppliers
        $this->assertEquals(500.00, (float) $resSqlSupp->json('total_expenses'));

        // 4. Scientific notation in min_amount (1e3 = 1000)
        $resSci = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=1e3");
        $resSci->assertStatus(200);
        // 1e3 is numeric in PHP and evaluates to 1000.0, so 500 is excluded, 1500 included
        $this->assertEquals(1500.00, (float) $resSci->json('total_expenses'));

        // 5. Zero and micro boundary checks
        $resZero = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=0.00");
        $resZero->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resZero->json('total_expenses'));

        $resNeg = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&min_amount=-0.01");
        $resNeg->assertStatus(200);
        $this->assertEquals(2000.00, (float) $resNeg->json('total_expenses'));
    }

    /**
     * Objective 8: Stress test with diverse multi-category dataset and mathematical reconciliation.
     */
    public function test_adv08_multi_category_stress_and_math_reconciliation(): void
    {
        $supplier = Supplier::create(['name' => 'Distribuidora Global', 'cuit' => '30111999887']);
        $catA = ExpenseCategory::create(['name' => 'Categoría Alpha']);
        $catB = ExpenseCategory::create(['name' => 'Categoría Beta']);

        $methods = ['cash', 'transfer', 'check'];

        // Seed 30 varied expenses
        for ($i = 1; $i <= 10; $i++) {
            CashMovement::create([
                'cash_shift_id' => $this->shift->id,
                'user_id' => $this->admin->id,
                'expense_category_id' => $catA->id,
                'payment_method' => $methods[$i % 3],
                'type' => 'expense',
                'amount' => 100.00 * $i,
                'description' => "Gasto Alpha {$i}",
                'created_at' => now(),
            ]);

            CashMovement::create([
                'cash_shift_id' => $this->shift->id,
                'user_id' => $this->admin->id,
                'expense_category_id' => $catB->id,
                'payment_method' => $methods[($i + 1) % 3],
                'type' => 'expense',
                'amount' => 150.00 * $i,
                'description' => "Gasto Beta {$i}",
                'created_at' => now(),
            ]);

            CashMovement::create([
                'cash_shift_id' => $this->shift->id,
                'user_id' => $this->admin->id,
                'supplier_id' => $supplier->id,
                'payment_method' => $methods[($i + 2) % 3],
                'type' => 'supplier_payment',
                'amount' => 200.00 * $i,
                'description' => "Proveedor {$i}",
                'created_at' => now(),
            ]);
        }

        // Apply filters: include_suppliers=false, payment_method=transferencia, min_amount=500
        $response = $this->getJson("/api/reports/expenses-analysis?start_date={$this->today}&end_date={$this->today}&include_suppliers=false&payment_method=transferencia&min_amount=500");
        $response->assertStatus(200);

        // Manually calculate expected matches from seeded data:
        // CatA: amounts 100*i for i=1..10 where methods[i%3] == 'transfer' (i%3 == 1 -> i in [1, 4, 7, 10])
        // With amount >= 500:
        // i=1: 100 (excluded < 500)
        // i=4: 400 (excluded < 500)
        // i=7: 700 (INCLUDED)
        // i=10: 1000 (INCLUDED)
        // Sum CatA = 700 + 1000 = 1700
        //
        // CatB: amounts 150*i for i=1..10 where methods[(i+1)%3] == 'transfer' ((i+1)%3 == 1 -> i%3 == 0 -> i in [3, 6, 9])
        // With amount >= 500:
        // i=3: 450 (excluded < 500)
        // i=6: 900 (INCLUDED)
        // i=9: 1350 (INCLUDED)
        // Sum CatB = 900 + 1350 = 2250
        //
        // Supplier payments are excluded because include_suppliers=false.
        // Expected Total = 1700 + 2250 = 3950.00
        $expectedTotal = 3950.00;

        $this->assertEquals($expectedTotal, (float) $response->json('total_expenses'));

        $byCategory = collect($response->json('by_category'));
        $this->assertCount(2, $byCategory);
        $this->assertNull($byCategory->firstWhere('category', 'Pago a Proveedor'));

        $alpha = $byCategory->firstWhere('category', 'Categoría Alpha');
        $this->assertNotNull($alpha);
        $this->assertEquals(1700.00, (float) $alpha['amount']);
        $this->assertEquals(2, (int) $alpha['transactions']);

        $beta = $byCategory->firstWhere('category', 'Categoría Beta');
        $this->assertNotNull($beta);
        $this->assertEquals(2250.00, (float) $beta['amount']);
        $this->assertEquals(2, (int) $beta['transactions']);

        // Sum of percentages must equal 100.0% within rounding tolerance
        $sumPct = $byCategory->sum('percentage');
        $this->assertEqualsWithDelta(100.0, $sumPct, 0.2);

        // Verify Excel Export reconciliation
        $export = new ExpensesAnalysisExport($this->today, $this->today, false, 'transferencia', 500);
        $excelRows = $export->collection();
        $this->assertCount(2, $excelRows);
        $this->assertEquals($expectedTotal, (float) $excelRows->sum('total_amount'));

        // Verify PDF Export generates successfully
        $pdfResp = $this->get("/api/reports/expenses-analysis/pdf?start_date={$this->today}&end_date={$this->today}&include_suppliers=false&payment_method=transferencia&min_amount=500");
        $pdfResp->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $pdfResp->getContent());
    }
}
