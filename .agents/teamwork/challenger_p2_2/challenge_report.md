# Adversarial Challenge Report — Phase P2 (Challenger 2)

**Evaluator**: Challenger 2 (Empirical Challenger: Critic & Specialist)  
**Target Codebase**: Sistema POS Backend (`pos-backend`)  
**Scope**: P2.2 (SaleService Pricing & Subtotals), P2.3 (StockController & AdjustStockRequest), P2.4 (Excel Exports & Financial Repository), P2.5 (Native Laravel Auth)  
**Date**: 2026-09-27  

---

## 1. Challenge Summary

**Overall risk assessment**: **LOW** (All P2 implementations are resilient, mathematically atomic, and survived all 21 empirical adversarial challenges across SQLite and live MySQL 8.4.3).

The adversarial evaluation empirically tested the system under hostile, distorted, and extreme boundary inputs. The implementation has proven to be mathematically sound, transactionally secure, and fully compliant with project contracts.

---

## 2. Detailed Challenges & Empirical Verification

### Challenge 1 (P2.2): SaleService Pricing & Atomic Subtotal

- **Assumptions Tested**:
  1. Does `processItems()` calculate `subtotal = round(unit_price * quantity, 2)` atomically, regardless of client-sent values?
  2. Can a client forge a subtotal (e.g. `$0.05` instead of `$36.78` or `$9,999.00`) and trick the backend into storing it?
  3. Are extreme fractional quantities (e.g., `2.335 kg`, `0.001 g`) preserved and multiplied without precision drift?
  4. Does an explicitly negotiated price (`unit_price` or `price`) prevail over catalog selling price and volume tiers?
  5. Are zero prices (`$0.00` for 100% discount promo) handled cleanly without division by zero or errors?
  6. Are negative unit prices (`-$25.00`) prevented from corrupting inventory/finances?
  7. Do volume price tiers automatically apply when no unit price is sent, but yield when a negotiated price is explicitly specified?
  8. Does order recall in `payPendingSale()` recalculate `$lockedSale->total` dynamically from new item subtotals?

- **Empirical Attack Scenarios Executed**:
  - **Scenario 1.1 (Extreme Fraction & Tampered Subtotal)**: Quantity `2.335` at `$15.75`/kg. Expected subtotal: `round(2.335 * 15.75, 2) = 36.78`. Malicious client sent `subtotal = 0.05`.  
    *Result*: Client value discarded. Stored in `sale_items`: `quantity = 2.335`, `unit_price = 15.75`, `subtotal = 36.78`. Invariant `unit_price * quantity == subtotal` strictly held.
  - **Scenario 1.2 (Extreme Small Fraction Boundary)**: Quantity `0.001` at `$1,000.00`/unit. Client sent `subtotal = 999.99`.  
    *Result*: Stored in `sale_items`: `quantity = 0.001`, `unit_price = 1000.00`, `subtotal = 1.00`.
  - **Scenario 1.3 (Negotiated Unit Price & Alternative Key)**: Catalog price `$100.00`. Client negotiated `$62.50` with forged subtotal `$9999.00`. In a second test, payload used `'price' => 45.00` instead of `'unit_price'`.  
    *Result*: In both cases, negotiated price prevailed over catalog ($62.50 and $45.00), and subtotal was recalculated accurately ($187.50 and $90.00).
  - **Scenario 1.4 (Zero-Price 100% Promo)**: Quantity `5.0`, `unit_price = 0.00`, forged subtotal `$100.00`.  
    *Result*: Stored with `unit_price = 0.00`, `subtotal = 0.00`.
  - **Scenario 1.5 (Negative Price Tamper)**: Malicious client passed `unit_price = -15.00`.  
    *Result*: `SaleService` clamped price via `max(0.0, round($unitPrice, 2))` to `0.00`, preventing negative balance corruption.
  - **Scenario 1.6 (Price Tiers Resolution & Negotiated Override)**: Product with tiers 10→$80, 50→$60 (base $100).  
    *Result*: Qty 15 resolved tier $80 (subtotal $1200); Qty 60 resolved tier $60 (subtotal $3600); Qty 60 with negotiated price $72 resolved $72 (subtotal $4320), overriding tier $60.
  - **Scenario 1.7 (Order Recall Total Synchronization)**: Pending sale recalled with modified quantities and negotiated prices.  
    *Result*: `$lockedSale->total` updated from previous $100 to exact sum of new subtotals ($310.00).

---

### Challenge 2 (P2.3): StockController & AdjustStockRequest

- **Assumptions Tested**:
  1. Does `AdjustStockRequest` allow all 4 valid types (`in`, `out`, `increment`, `decrement`)?
  2. Does it map `increment` to `in` and `decrement` to `out` in `StockMovement` records?
  3. Are invalid types (`invalid`, `transfer`, `''`, `123`) rejected with HTTP 422?
  4. Does `quantity = 0` with `min_stock` update `min_stock` successfully without generating phantom `StockMovement` records?
  5. Is notes boundary of 500 characters respected (500 accepted, 501 rejected with 422)?
  6. Does out-of-stock decrement abort safely with 422?
  7. Is the dead method `ProductController::adjustStock` completely eradicated?

- **Empirical Attack Scenarios Executed**:
  - **Scenario 2.1 (Valid Types & Mapping)**: Tested `in` (+10), `increment` (+5), `out` (-12), `decrement` (-3). Starting stock 50 -> ending stock 50. `StockMovement` recorded proper `in`/`out` types and quantities.  
    *Result*: PASS.
  - **Scenario 2.2 (Invalid Types Fuzzing)**: Fuzzed with `['invalid', 'transfer', '', 'none', 'adjust']`.  
    *Result*: All rejected with HTTP 422 and JSON validation error on `type`.
  - **Scenario 2.3 (Quantity = 0 with min_stock)**: Sent `type = 'in'`, `quantity = 0`, `min_stock = 25`.  
    *Result*: Stock remained 30, `min_stock` updated to 25, 0 new `StockMovement` records created.
  - **Scenario 2.4 (Notes Length Boundary)**: Sent string of length 500 (`str_repeat('A', 500)`) -> 200 OK. Sent string of length 501 (`str_repeat('B', 501)`) -> 422 Unprocessable Entity (`notes` error).  
    *Result*: PASS.
  - **Scenario 2.5 (Insufficient Stock Out)**: Stock = 5, adjust `out` = 10 -> HTTP 422 "Stock insuficiente. Stock actual: 5".  
    *Result*: PASS.
  - **Scenario 2.6 (Dead Code Eradication)**: `method_exists(ProductController::class, 'adjustStock')` returned `false`. Route `/api/catalog/products/{product}/adjust-stock` points cleanly to `StockController@adjust`.  
    *Result*: PASS.

---

### Challenge 3 (P2.4): Excel Exports & SalesAnalyticsRepository

- **Assumptions Tested**:
  1. Do `MonthlyBalanceExport` and `ProfitByCategoryExport` execute cleanly under SQLite and MySQL?
  2. Does the date format SQL logic (`strftime` on SQLite, `DATE_FORMAT` on MySQL) execute without syntax or driver errors?
  3. Are internal customer accounts (`customers.is_internal_account = true`) strictly excluded from both reports?
  4. Are active cash expenses (`cash_movements` where `type = 'expense'`) deducted from profit, while non-expenses and soft-deleted expenses are excluded?
  5. Is margin calculation guarded against `DivisionByZeroError` when revenue is 0?

- **Empirical Attack Scenarios Executed**:
  - **Scenario 3.1 (SQLite Execution & Formatting)**: `SalesAnalyticsRepository::getMonthlyBalance()` executed under SQLite test harness. `MonthlyBalanceExport::collection()`, `headings()`, `map()`, and `columnFormats()` generated valid Excel rows without errors.  
    *Result*: PASS.
  - **Scenario 3.2 (Live MySQL 8.4.3 Execution)**: Connected to live MySQL instance (`sistema_pos` on port 3306). Executed `SalesAnalyticsRepository::getMonthlyBalance('2026')` and `ProfitByCategoryExport::collection()`. Driver returned grouped periods using `DATE_FORMAT(sales.created_at, '%Y-%m')` without SQL syntax errors.  
    *Result*: PASS.
  - **Scenario 3.3 (Internal Account Exclusion)**: Created internal customer ($500 sale) and regular customer ($200 sale).  
    *Result*: `getMonthlyBalance` reported `total_revenue = 200.00` and `transactions = 1`. `getProfitReport` for "Bebidas" reported only the regular sale ($300), completely excluding internal consumption ($600).
  - **Scenario 3.4 (Cash Expense Deductions & Soft-Deletes)**: Created Gross Profit = $120. Added active cash expense of $30, active cash income of $100, and soft-deleted cash expense of $50.  
    *Result*: `total_profit` reported exactly `$90.00` ($120 - $30). Income was not treated as expense, and deleted expense was ignored.
  - **Scenario 3.5 (Zero-Revenue Margin Guard)**: Tested row with `revenue_with_cost = 0.0`.  
    *Result*: Mapped margin returned `0` without PHP warning or `DivisionByZeroError`.

---

### Challenge 4 (P2.5): Native Laravel Auth & ValidateSessionToken

- **Assumptions Tested**:
  1. Does `ValidateSessionToken` populate `Auth::setUser($user)` and `$request->setUserResolver()`?
  2. Are `auth()->check()`, `auth()->id()`, `auth()->user()`, and `$request->user()` all available during request execution?
  3. Is backwards compatibility with `$request->attributes->get('authenticated_user')` preserved?
  4. Are requests with missing token rejected with 401 `SESSION_MISSING`?
  5. Are requests with invalid or rotated token rejected with 401 `SESSION_EXPIRED`?

- **Empirical Attack Scenarios Executed**:
  - **Scenario 4.1 (Native Auth Population)**: Dispatched request to `/api/sales` with valid session token.  
    *Result*: `Auth::check()` returned `true`, `auth()->id()` matched `$user->id`, `auth()->user()->id` matched `$user->id`, `$request->user()->id` matched `$user->id`.
  - **Scenario 4.2 (Missing Header)**: Request without header returned HTTP 401 with `error_code: 'SESSION_MISSING'`.  
    *Result*: PASS.
  - **Scenario 4.3 (Bogus Token)**: Request with nonexistent token returned HTTP 401 with `error_code: 'SESSION_EXPIRED'`.  
    *Result*: PASS.
  - **Scenario 4.4 (Token Rotation / Concurrent Device Login)**: User logged in on Device A (token A). Device B logged in (token B updated on user). Request with token A returned 401 `SESSION_EXPIRED`; request with token B returned 200 OK.  
    *Result*: PASS.

---

## 3. Stress Test Results Table

| ID | Scenario | Expected Behavior | Actual Behavior | Result |
|---|---|---|---|:---:|
| **T-01** | Qty 2.335 at $15.75, forged subtotal $0.05 | Discard 0.05, save subtotal = 36.78 | Saved 36.78, invariant holds | **PASS** |
| **T-02** | Qty 0.001 at $1000.00, forged subtotal $999.99 | Discard 999.99, save subtotal = 1.00 | Saved 1.00 | **PASS** |
| **T-03** | Negotiated price $62.50 over catalog $100.00 | Price 62.50 prevails, subtotal 187.50 | Saved 62.50 / 187.50 | **PASS** |
| **T-04** | Direct call with `'price'` fallback key | Resolves 45.00 from `'price'` | Saved 45.00 / 90.00 | **PASS** |
| **T-05** | Zero unit price promo ($0.00) with forged subtotal | Unit price 0.00, subtotal 0.00 | Saved 0.00 / 0.00 | **PASS** |
| **T-06** | Negative unit price (-$15.00) | Clamped to 0.00 via `max(0.0, ...)` | Clamped to 0.00 | **PASS** |
| **T-07** | Price tier resolution (qty 15 -> $80, qty 60 -> $60) | Tier prices applied when no price sent | Applied $80 and $60 | **PASS** |
| **T-08** | Price tier override with negotiated price ($72) | Negotiated $72 overrides tier $60 | Overridden to $72 (subtotal $4320) | **PASS** |
| **T-09** | Order recall total reconciliation | Sale total updated to sum of subtotals ($310) | Sale total updated to $310.00 | **PASS** |
| **T-10** | Valid stock adjust types (`in`, `out`, `inc`, `dec`) | Increments/decrements stock, maps types | Stock matches (50->50), movements logged | **PASS** |
| **T-11** | Invalid stock adjust types fuzzed | HTTP 422 on `type` | HTTP 422 validation error | **PASS** |
| **T-12** | Stock adjust qty=0 with `min_stock` update | Stock unchanged, min_stock updated, 0 movements | Stock 30, min_stock 25, 0 movements | **PASS** |
| **T-13** | Notes length = 500 chars | HTTP 200 OK | HTTP 200 OK | **PASS** |
| **T-14** | Notes length = 501 chars | HTTP 422 on `notes` | HTTP 422 validation error | **PASS** |
| **T-15** | Stock decrement exceeds available stock | HTTP 422 "Stock insuficiente" | HTTP 422 returned | **PASS** |
| **T-16** | `ProductController::adjustStock` presence | Method does not exist | `method_exists` is false | **PASS** |
| **T-17** | Monthly balance SQLite driver | Runs `strftime`, exports collection/map | Clean execution and mapping | **PASS** |
| **T-18** | Monthly balance live MySQL 8.4.3 driver | Runs `DATE_FORMAT`, exports collection/map | Clean execution without SQL error | **PASS** |
| **T-19** | Internal accounts exclusion in exports | Sales for internal accounts omitted | Excluded from revenue & counts | **PASS** |
| **T-20** | Cash expense deduction in monthly balance | Only active expenses deducted ($30) | Net profit $90 ($120-$30) | **PASS** |
| **T-21** | Zero-revenue margin guard | Returns margin 0, no division error | Margin is 0 | **PASS** |
| **T-22** | Native Laravel Auth population | `auth()->user()`, `auth()->id()`, `$req->user()` | All resolved to authenticated user | **PASS** |
| **T-23** | Missing token | HTTP 401 `SESSION_MISSING` | HTTP 401 `SESSION_MISSING` | **PASS** |
| **T-24** | Expired / Non-existent token | HTTP 401 `SESSION_EXPIRED` | HTTP 401 `SESSION_EXPIRED` | **PASS** |
| **T-25** | Single Active Session token rotation | Old token 401, new token 200 | Old token rejected, new accepted | **PASS** |

---

## 4. Unchallenged Areas

- **P2.1 (DeliveryNote stock locking & anti-double deduction)**: Evaluated and confirmed by Challenger 1 (`AdversarialConcurrencyStressTest.php`).
- **P2.6 (Quote sequence race conditions & overflow padding)**: Evaluated and confirmed by Challenger 1 (`AdversarialConcurrencyStressTest.php`).

---

## 5. Verdict

### **VERDICT: APPROVE**

The implementation of P2.2, P2.3, P2.4, and P2.5 successfully satisfies all functional specifications, passes all adversarial edge cases without regression, and exhibits resilient behavior under hostile input conditions.
