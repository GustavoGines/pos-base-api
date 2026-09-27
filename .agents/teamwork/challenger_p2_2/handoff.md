# Handoff Report — Challenger 2 (Phase P2)

**From**: Challenger 2 (`challenger_p2_2`)  
**To**: Orchestrator (`orchestrator_4`)  
**Date**: 2026-09-27  
**Verdict**: **APPROVE**  

---

## 1. Observation

1. **P2.2 - SaleService Pricing & Atomic Subtotal (`app/Services/SaleService.php:203-234`):**
   - Prior implementation in `processItems`:
     `$unitPrice = $product->getPriceForQuantity($itemData['quantity']) ?? $itemData['unit_price'];`
     `$sale->items()->create(['unit_price' => $unitPrice, 'subtotal' => $itemData['subtotal']]);`
     `getPriceForQuantity()` returned float, causing `?? $itemData['unit_price']` to be dead code, overriding client-negotiated prices with catalog price while preserving client-sent `subtotal`, breaking `unit_price * quantity == subtotal`.
   - Current verified code in `SaleService::processItems`:
     ```php
     $rawPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;
     if ($rawPrice !== null && is_numeric($rawPrice)) {
         $unitPrice = (float) $rawPrice;
     } else {
         $unitPrice = (float) $product->getPriceForQuantity($quantity);
     }
     $unitPrice = max(0.0, round($unitPrice, 2));
     $subtotal = round($unitPrice * $quantity, 2);
     ```
   - In `payPendingSale()` (`SaleService.php:120-121`):
     ```php
     $newTotal = (float) $lockedSale->items()->sum('subtotal');
     $lockedSale->total = $newTotal;
     ```

2. **P2.3 - StockController & AdjustStockRequest (`app/Http/Controllers/Api/StockController.php`, `app/Http/Requests/AdjustStockRequest.php`, `app/Http/Controllers/Api/ProductController.php`):**
   - `AdjustStockRequest::rules()` permits `'type' => 'required|in:in,out,increment,decrement'`.
   - `StockController::adjust(AdjustStockRequest $request, Product $product)` maps `'in'|'increment' -> 'in'` and `'out'|'decrement' -> 'out'`.
   - When `$validated['quantity'] == 0`, `StockMovement` creation is bypassed, while `min_stock` update executes safely.
   - `ProductController::adjustStock` was deleted; `method_exists(ProductController::class, 'adjustStock')` evaluates to `false`.

3. **P2.4 - Excel Exports & SalesAnalyticsRepository (`app/Exports/MonthlyBalanceExport.php`, `app/Exports/ProfitByCategoryExport.php`, `app/Repositories/SalesAnalyticsRepository.php:115-185`):**
   - In `SalesAnalyticsRepository::getMonthlyBalance()`:
     ```php
     $isSqlite = DB::connection()->getDriverName() === 'sqlite';
     $periodSql = $isSqlite ? "strftime('%Y-%m', sales.created_at)" : "DATE_FORMAT(sales.created_at, '%Y-%m')";
     ```
   - Both `getMonthlyBalance` and `getProfitReport` exclude internal accounts using:
     ```php
     ->whereNotExists(function ($query) {
         $query->select(DB::raw(1))
               ->from('customers')
               ->whereColumn('customers.id', 'sales.customer_id')
               ->where('customers.is_internal_account', true);
     })
     ```
   - Expenses are deducted from `cash_movements` with `where('type', 'expense')->whereNull('deleted_at')`.
   - Executed live against MySQL 8.4.3 (`DB::connection()->getDriverName() === 'mysql'`) returning 2 periods with `DATE_FORMAT` and successful export mapping.

4. **P2.5 - Native Laravel Auth (`app/Http/Middleware/ValidateSessionToken.php:48-49`):**
   - Middleware now executes:
     ```php
     Auth::setUser($user);
     $request->setUserResolver(fn () => $user);
     ```
   - Legacy `$request->attributes->set('authenticated_user', $user)` remains preserved.

5. **Empirical Test Suite Execution:**
   - Command: `php artisan test tests/Feature/AdversarialChallenger2Test.php`
   - Result: **21 passed (116 assertions)** in 0.99s.
   - Command for all P2 feature tests + both challenger suites:
     `php artisan test tests/Feature/DeliveryNoteTest.php tests/Feature/PosProcessSaleTest.php tests/Feature/CatalogStockTest.php tests/Feature/ReportTest.php tests/Feature/AuthTest.php tests/Feature/QuoteTest.php tests/Feature/AdversarialChallenger2Test.php tests/Feature/AdversarialConcurrencyStressTest.php`
   - Result: **82 passed (338 assertions)** in 3.66s (100% green).
   - Git tree status: All modified files remain unstaged; NO `git commit` was executed.

---

## 2. Logic Chain

1. **Subtotal Invariance & Anti-Tampering (P2.2):**
   - Observation 1 proves that `SaleService::processItems()` calculates `$subtotal = round($unitPrice * $quantity, 2)` directly from reconciled unit price and quantity.
   - Any client-submitted `subtotal` key is ignored, ensuring that `unit_price * quantity == subtotal` holds for all items in the database.
   - Negotiated price overrides catalog and price tiers because `$rawPrice` is evaluated first. If null, `$product->getPriceForQuantity($quantity)` is invoked. Negative values are clamped to `0.0`.
   - In `payPendingSale()`, recalculating `$lockedSale->total` via `sum('subtotal')` synchronizes the sale header with item totals upon recall.

2. **Stock Adjust FormRequest & Dead Code Cleanup (P2.3):**
   - Observation 2 confirms that `AdjustStockRequest` unifies stock adjustment rules across the API.
   - Fuzzing and boundary tests confirm that valid types (`in`, `out`, `increment`, `decrement`) work as intended, invalid types are rejected with HTTP 422, notes of 500 chars are allowed while 501 chars are rejected, and zero-quantity updates adjust `min_stock` without phantom records.
   - Deletion of `ProductController::adjustStock` cleans up technical debt without breaking routes.

3. **Multi-Database Financial Reporting & Internal Account Exclusion (P2.4):**
   - Observation 3 confirms that driver detection dynamically chooses `strftime` on SQLite and `DATE_FORMAT` on MySQL, eliminating syntax crashes.
   - Both SQLite test suite and live MySQL 8.4.3 execution demonstrated valid aggregation without errors.
   - Subqueries on `customers.is_internal_account` prevent internal company consumption from distorting revenue, cost, or item counts.
   - Active cash expenses are subtracted from gross profit, while non-expenses and soft-deleted expenses are filtered out.

4. **Native Laravel Auth Integration (P2.5):**
   - Observation 4 confirms that `ValidateSessionToken` populates `Auth::setUser($user)` and binds `$request->setUserResolver()`.
   - Tests confirm that `auth()->user()`, `auth()->id()`, and `$request->user()` resolve cleanly during request processing.
   - Missing or expired tokens continue to fail gracefully with HTTP 401 and specific error codes (`SESSION_MISSING`, `SESSION_EXPIRED`).

---

## 3. Caveats

1. **Live MySQL Unmigrated P1 Table:** As observed in `tests/Feature/ChallengerFinancialIntegrityTest.php`, the user's live MySQL database (`sistema_pos`) has not yet executed migration `2026_09_27_030000_fix_customer_transactions_enum_refund.php` (Phase P1). This is an external deployment step and does not affect Phase P2 or SQLite test suite.
2. **Review-Only & Unstaged Constraint:** In accordance with instructions, Challenger 2 modified no implementation code and performed no git commits.

---

## 4. Conclusion

**Verdict: APPROVE**

All four assigned Phase P2 debt items (P2.2, P2.3, P2.4, P2.5) are fully implemented, structurally sound, mathematically atomic, and verified under adversarial edge-case conditions across both SQLite and live MySQL 8.4.3.

---

## 5. Verification Method

To independently verify the adversarial findings:

1. **Run Challenger 2 Adversarial Suite:**
   ```powershell
   php artisan test tests/Feature/AdversarialChallenger2Test.php
   ```
   *Expected Output*: 21 passed (116 assertions), 0 failures.

2. **Run Combined P2 Feature and Adversarial Suites:**
   ```powershell
   php artisan test tests/Feature/DeliveryNoteTest.php tests/Feature/PosProcessSaleTest.php tests/Feature/CatalogStockTest.php tests/Feature/ReportTest.php tests/Feature/AuthTest.php tests/Feature/QuoteTest.php tests/Feature/AdversarialChallenger2Test.php tests/Feature/AdversarialConcurrencyStressTest.php
   ```
   *Expected Output*: 82 passed (338 assertions), 0 failures.

3. **Verify Live MySQL Export Execution:**
   ```powershell
   php artisan tinker --execute="`$repo = app(App\Repositories\SalesAnalyticsRepository::class); dump(`$repo->getMonthlyBalance('2026'));"
   ```
   *Expected Output*: Array with grouped periods via `DATE_FORMAT` without SQL errors.

4. **Verify Git Working Tree:**
   ```powershell
   git status
   ```
   *Expected Output*: Modified files unstaged, no commits made.
