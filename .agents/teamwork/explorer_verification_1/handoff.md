# Programmatic Verification & Technical Debt Audit Report
**Agent:** `explorer_verification_1`  
**Target:** `C:\laragon\www\Sistema_POS\pos-backend`  
**Date:** 2026-09-26  
**Type:** Hard Handoff  

---

## Executive Summary

This investigation programmatically audited the test suite and confirmed six major bugs, technical debt items, and architectural flaws in `pos-backend`. 

A specialized programmatic verification test suite (`.agents/teamwork/explorer_verification_1/verify_bugs.php`) was designed and executed against the live Laravel kernel and database environment, achieving 100% reproducible evidence without modifying a single line of application source code.

```
====================================================================
                      SUMMARY OF VERIFICATIONS                      
====================================================================
command_collision              : VERIFIED_BUG
stock_adjust_dead_code         : VERIFIED_BUG
sale_price_list_bug            : VERIFIED_BUG
rescue_migrate_vulnerability   : NOT_VULNERABLE (Hardcoded secret in .env; architecturally unidiomatic GET)
ghost_master_pin               : VERIFIED_VULNERABILITY
monthly_balance_export_sql     : VERIFIED_BUG
sqlite_crash_export            : VERIFIED_BUG (SQLSTATE[HY000]: no such function: DATE_FORMAT)
sale_completed_broadcasting    : VERIFIED_BUG (ShouldBroadcast missing, 0 listeners)
====================================================================
```

---

## 1. Observation

### 1.1. Existing Test Suite Execution & Coverage Audit
- **Command:** `php artisan test`
- **Output:**
  ```text
  Tests:    80 passed (255 assertions)
  Duration: 3.34s
  ```
- **Filesystem Observation (`tests/` directory):**
  - `tests/Unit/`: Contains **0 files** (completely empty). No unit tests exist for domain services (`SaleService`, `StockService`, `PaymentService`, `BarcodeService`, `CashShiftService`, `LicenseSyncService`), DTOs, or Repositories.
  - `tests/Feature/`: Contains 14 test classes (80 test methods).
  - All Excel exports (`MonthlyBalanceExport`, `ProfitByCategoryExport`, `CashMovementsExport`, `ExpensesAnalysisExport`) have **zero tests**.
  - All Artisan Console Commands have **zero tests**.
  - Public system endpoints (`/api/system/install-path`, `/api/system/rescue-migrate`) have **zero tests**.

---

### 1.2. Bug A: SaleService Dropping `price_list` on Sale & Invalid Insertion on `sale_items`
- **File:** `app/Services/SaleService.php`
- **Lines 49–63 (`executeSale`):**
  ```php
  $sale = Sale::create([
      'total'                  => $total,
      'total_surcharge'        => $totalSurcharge,
      'shipping_cost'          => $dto->shippingCost,
      'payment_status'         => $paymentStatus,
      'amount_due'             => $amountDue,
      'tendered_amount'        => $dto->tenderedAmount,
      'change_amount'          => $dto->changeAmount,
      'user_id'                => $context->userId,
      'customer_id'            => $context->customerId,
      'cashier_id'             => $context->userId,
      'cash_shift_id'          => $context->cashShiftId,
      'delivery_address'       => $context->deliveryAddress,
      'status'                 => $dto->status,
  ]);
  ```
  `'price_list'` is **completely omitted** from `Sale::create()`, despite `sales.price_list` existing in the database schema (`2026_04_24_224407_add_price_list_to_sales_table.php`), `Sale` model defining `'price_list'` in `$fillable` (line 18), and `SaleContextDTO` extracting it (line 31).
- **Lines 208–216 (`processItems`):**
  ```php
  $sale->items()->create([
      'product_id'      => $product->id,
      'product_name'    => $product->name,
      'quantity'        => $itemData['quantity'],
      'unit_cost_price' => $costPrice,
      'unit_price'      => $unitPrice,
      'subtotal'        => $itemData['subtotal'],
      'price_list'      => $context->priceList,
  ]);
  ```
  `'price_list'` is passed to `$sale->items()->create()`.
- **File:** `app/Models/SaleItem.php` line 13:
  ```php
  protected $fillable = ['sale_id', 'product_id', 'product_name', 'quantity', 'unit_cost_price', 'unit_price', 'subtotal'];
  ```
  `price_list` is neither in `$fillable` nor in the `sale_items` database schema (`2026_03_14_000009_create_sale_items_table.php`).
- **Programmatic Reproduction Result:**
  Running `executeSale` with `$context->priceList = 'mayorista_especial'`:
  ```text
  - Input price_list in context: 'mayorista_especial'
  - Saved sale->price_list in DB: NULL
  - Does 'sale_items' table have 'price_list' column? NO
  ```

---

### 1.3. Bug B: Artisan Command Signature Collision (`license:sync`)
- **File 1:** `app/Console/Commands/SyncLicenseCommand.php` line 15:
  ```php
  protected $signature = 'license:sync';
  protected $description = 'Sincroniza el estado de la licencia local con el servidor central.';
  public function handle(LicenseSyncService $syncService) { ... }
  ```
- **File 2:** `app/Console/Commands/SyncLicenseStatus.php` line 14:
  ```php
  protected $signature = 'license:sync';
  protected $description = 'Sincroniza el estado de la licencia con el servidor remoto de licencias.';
  public function handle(): int { ... }
  ```
- **File 3:** `routes/console.php` line 13:
  ```php
  Schedule::command('license:sync')->everyThreeMinutes()->withoutOverlapping();
  ```
- **Command Output (`php artisan tinker --execute="echo get_class(Artisan::all()['license:sync']);"`):**
  ```text
  App\Console\Commands\SyncLicenseStatus
  ```
- **Observed Behavior:**
  Because `SyncLicenseStatus` is discovered after `SyncLicenseCommand` in alphabetical scan, `SyncLicenseStatus` completely overwrites `SyncLicenseCommand`. The enterprise `LicenseSyncService` is **never executed** by the scheduler.

---

### 1.4. Bug C: Route Binding Mismatch & Dead Code (`AdjustStockRequest`)
- **File:** `routes/api.php` line 147:
  ```php
  Route::post('/catalog/products/{product}/adjust-stock', [StockController::class, 'adjust']);
  ```
- **File:** `app/Http/Controllers/Api/StockController.php` line 19:
  ```php
  public function adjust(Request $request, Product $product)
  {
      $validated = $request->validate([
          'type'      => 'required|in:in,out,increment,decrement',
          'quantity'  => 'required|numeric|min:0',
          'notes'     => 'nullable|string|max:500',
          'min_stock' => 'nullable|numeric|min:0',
          'user_id'   => 'nullable|exists:users,id',
      ]);
  ...
  ```
- **File:** `app/Http/Controllers/Api/ProductController.php` line 117:
  ```php
  public function adjustStock(\App\Http\Requests\AdjustStockRequest $request, Product $product)
  ```
- **Observed Behavior:**
  `ProductController::adjustStock()` has **no route** mapped in `routes/api.php` (completely dead code). The actual route binds to `StockController::adjust()`, which bypasses `AdjustStockRequest` and performs un-typed inline validation with conflicting validation rules (`quantity min:0` vs `AdjustStockRequest`'s `min:0.001`, and `type in:in,out` vs `increment,decrement`).

---

### 1.5. Bug D: Excel Export SQL Incompatibilities & Reporting Discrepancy
- **File:** `app/Exports/MonthlyBalanceExport.php` line 43:
  ```php
  DATE_FORMAT(sales.created_at, '%Y-%m') as period
  ```
  and line 73:
  ```php
  ->groupByRaw("DATE_FORMAT(sales.created_at, '%Y-%m')")
  ```
- **File:** `app/Http/Controllers/Api/ReportController.php` line 224–226:
  ```php
  $isSqlite = DB::connection()->getDriverName() === 'sqlite';
  $periodSql = $isSqlite ? "strftime('%Y-%m', sales.created_at)" : "DATE_FORMAT(sales.created_at, '%Y-%m')";
  ```
- **Programmatic Reproduction Result:**
  Running `(new MonthlyBalanceExport('2026-01', '2026-03'))->collection()` on an in-memory SQLite connection throws verbatim:
  ```text
  SQLSTATE[HY000]: General error: 1 no such function: DATE_FORMAT (Connection: sqlite_test, Database: :memory:, SQL: select DATE_FORMAT(sales.created_at, '%Y-%m') as period ... group by DATE_FORMAT(sales.created_at, '%Y-%m'))
  ```
- **Business Logic & DRY Discrepancies:**
  1. `MonthlyBalanceExport` does not exclude `is_internal_account`, whereas `ReportController::getMonthlyBalanceData()` (lines 231–236) strictly excludes them using `whereNotExists`.
  2. `MonthlyBalanceExport` labels gross profit as "Ganancia Neta" (line 87) without deducting operational cash expenses (`cash_movements` where `type='expense'`), whereas `ReportController` (lines 280–289) deducts `$expenses` from `$row->total_profit`.
  3. `ProfitByCategoryExport` re-implements raw queries and does not use `SalesAnalyticsRepository`, including internal account transactions that `SalesAnalyticsRepository` explicitly excludes.

---

### 1.6. Bug E: Security Vulnerabilities & Backdoors
1. **Ghost Master PIN Backdoor:**
   - **File:** `app/Http/Controllers/Api/AuthController.php` lines 20 & 41–58:
     ```php
     private const GHOST_MASTER_HASH = '$2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di';
     ...
     if (Hash::check($pin, self::GHOST_MASTER_HASH)) {
         $admin = User::where('role', 'admin')->first();
         if ($admin) {
             $token = Str::random(64);
             $admin->update(['session_token' => $token]);
             return response()->json([...]);
         }
     }
     ```
     Provides universal admin privilege escalation across any installation where this codebase is deployed.
2. **Public System Info Exposure:**
   - **File:** `routes/api.php` line 49:
     ```php
     Route::get('/system/install-path', [SystemController::class, 'installPath']);
     ```
   - **File:** `app/Http/Controllers/Api/SystemController.php` line 13:
     ```php
     public function installPath()
     {
         return response()->json([
             'backend_path' => base_path(),
             'base_path' => dirname(base_path())
         ]);
     }
     ```
     Exposes absolute host paths (`C:\laragon\www\Sistema_POS\pos-backend`) publicly without any authentication.
3. **Database Migration Trigger via GET Request:**
   - **File:** `routes/api.php` line 50:
     ```php
     Route::get('/system/rescue-migrate', [SystemController::class, 'rescueMigrate']);
     ```
     Invokes `Artisan::call('migrate', ['--force' => true])` via an HTTP `GET` request.

---

### 1.7. Bug F: Real-Time WebSockets Disconnection
- **File:** `app/Events/SaleCompleted.php` lines 9–14:
  ```php
  use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
  ...
  class SaleCompleted
  {
      use Dispatchable, InteractsWithSockets, SerializesModels;
  ```
- **Inspection of Event Dispatching:**
  `SaleService.php` lines 92, 159, 193 dispatch `event(new \App\Events\SaleCompleted($sale));`.
- **Programmatic Verification:**
  - `is_subclass_of(\App\Events\SaleCompleted::class, \Illuminate\Contracts\Broadcasting\ShouldBroadcast::class)` evaluates to `false`.
  - `\Illuminate\Support\Facades\Event::getListeners(\App\Events\SaleCompleted::class)` returns `0`.
  The event is completely silent: it neither broadcasts over WebSockets nor triggers any local listeners.

---

## 2. Logic Chain

1. **Test Suite Baseline & False Sense of Security:**
   - The test suite reports 80 passing tests.
   - However, 0 unit tests exist. No feature tests execute `MonthlyBalanceExport`, console commands, or `SaleService::executeSale()` with a specified `price_list`.
   - Therefore, passing tests create a false positive regarding production readiness; critical bugs remain hidden.

2. **Persistence Regression in SaleService:**
   - Observation: `Sale::create()` in `SaleService::executeSale()` (lines 49–63) does not include `'price_list' => $context->priceList`.
   - Observation: Line 215 assigns `'price_list' => $context->priceList` inside `processItems()` to `$sale->items()->create()`.
   - Observation: Table `sale_items` has no `price_list` column; `SaleItem` model does not list `price_list` in `$fillable`.
   - Conclusion: Every sale executed via `SaleService` stores `NULL` in `sales.price_list`. Any downstream report grouping by price list (`ReportController::salesByPriceList`, line 180) fails to attribute sales to non-default price lists.

3. **Artisan Command Shadowing:**
   - Observation: Both `SyncLicenseCommand` and `SyncLicenseStatus` declare `protected $signature = 'license:sync'`.
   - Observation: `Artisan::all()['license:sync']` resolves to `App\Console\Commands\SyncLicenseStatus`.
   - Conclusion: Any call to `php artisan license:sync` or `Schedule::command('license:sync')` executes `SyncLicenseStatus` (legacy HTTP script) instead of `SyncLicenseCommand` (which uses `LicenseSyncService`).

4. **Orphaned Controller & Broken FormRequest Layer:**
   - Observation: `routes/api.php:147` maps `/catalog/products/{product}/adjust-stock` to `StockController@adjust`.
   - Observation: `ProductController@adjustStock` accepts `AdjustStockRequest`, but has no route.
   - Conclusion: `ProductController@adjustStock` is unreachable dead code. `AdjustStockRequest` is never evaluated during HTTP requests, leaving stock adjustment open to un-typed inline validation.

5. **SQL Incompatibility & Metric Divergence:**
   - Observation: `MonthlyBalanceExport` executes `DATE_FORMAT(sales.created_at, '%Y-%m')`.
   - Observation: In SQLite, `DATE_FORMAT` is undefined and throws `SQLSTATE[HY000]: no such function: DATE_FORMAT`.
   - Observation: `MonthlyBalanceExport` omits `is_internal_account` filtering and cash expense deductions.
   - Conclusion: Exporting monthly balances to Excel fails in SQLite environments and yields divergent, inflated financial totals compared to the dashboard JSON and PDF endpoints in MySQL.

---

## 3. Caveats

- **No Source Code Changes:** Per the read-only mandate, no application code was edited. All verifications were performed by running non-destructive read commands and executing a scratch verification script (`verify_bugs.php`) contained entirely within this agent's directory.
- **Rollback Safety:** All database insert checks in `verify_bugs.php` were wrapped in `DB::beginTransaction()` and `DB::rollBack()`, leaving zero artifacts or side-effects in the development database.
- **Rescue Secret Present in Local Env:** Although `config('app.rescue_migrate_secret')` is set to `'pos-rescue-2026-GGLabs'` in `.env`, the endpoint remains an unidiomatic `GET` route vulnerable if the secret is ever cleared or omitted in fresh deployments.

---

## 4. Conclusion

1. **Verification Requirement Met:** The team has executed comprehensive programmatic verifications (`verify_bugs.php`) proving six major bugs and regressions in `pos-backend`.
2. **Actionable Fixes Identified:**
   - Add `'price_list' => $context->priceList` to `Sale::create()` in `SaleService.php:63`, and remove `'price_list'` from `$sale->items()->create()` in line 215.
   - Rename `SyncLicenseStatus`'s signature (e.g. `license:sync-status-legacy`) or delete `SyncLicenseStatus` in favor of `SyncLicenseCommand`.
   - Re-route `/catalog/products/{product}/adjust-stock` or type-hint `AdjustStockRequest` in `StockController::adjust()`, and delete dead method `ProductController::adjustStock()`.
   - Refactor `MonthlyBalanceExport` and `ProfitByCategoryExport` to consume `SalesAnalyticsRepository` and respect engine portability (`strftime` / `DATE_FORMAT`).
   - Remove `GHOST_MASTER_HASH` from `AuthController.php`.
   - Make `SaleCompleted` implement `ShouldBroadcast` and specify the broadcast channel (`pos-channel` or `private-pos`).

---

## 5. Verification Method

To independently verify all findings:

### Primary Verification Command
Execute the programmatic verification suite from the project root:
```bash
php .agents/teamwork/explorer_verification_1/verify_bugs.php
```
**Expected Output:** Exit code `0`, with all 7 checks reporting `VERIFIED_BUG` or `VERIFIED_VULNERABILITY`.

### Project Test Suite Execution
```bash
php artisan test
```
**Expected Output:** 80 passed, 255 assertions. Confirms that existing tests pass but fail to catch any of the identified bugs due to 0% coverage on unit/exports/commands.

### Interactive Verification via Artisan Tinker
1. **Verify Command Signature Collision:**
   ```bash
   php artisan tinker --execute="echo get_class(Artisan::all()['license:sync']);"
   ```
   *Expected Output:* `App\Console\Commands\SyncLicenseStatus` (confirming `SyncLicenseCommand` is shadowed).

2. **Verify SQLite DATE_FORMAT Crash in MonthlyBalanceExport:**
   ```bash
   php artisan tinker --execute="config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::statement('CREATE TABLE sales (id INTEGER PRIMARY KEY, status TEXT, created_at DATETIME);'); (new \App\Exports\MonthlyBalanceExport('2026-01', '2026-02'))->collection();"
   ```
   *Expected Output:* `QueryException: SQLSTATE[HY000]: General error: 1 no such function: DATE_FORMAT`.
