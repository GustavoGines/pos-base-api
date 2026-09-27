# Plan: Sistema POS Backend - Phase P2 (Alta Prioridad: Concurrencia, DRY y Precios)

## Objective
Implement exclusively the code fixes corresponding to Phase P2 from backend_tech_debt_report.md, keeping all Phase P1 fixes intact, leaving all modifications unstaged (no git commit), and ensuring the entire PHPUnit test suite (`php artisan test`) passes 100% green.

## Scope & Feature Inventory

| # | Item | Target Components | Description | Status |
|---|------|-------------------|-------------|--------|
| 1 | P2.1 | `DeliveryNoteController.php:91-125` | Shield delivery notes against double stock deduction. Validate if origin sale already deducted inventory before deducting stock, wrap in `DB::transaction`. | PLANNED |
| 2 | P2.2 | `SaleService::processItems()` | Reconcile unit prices and subtotals. Allow explicit agreed unit price to prevail or recalculate subtotal atomically (`unit_price * quantity == subtotal`). | PLANNED |
| 3 | P2.3 | `StockController.php`, `AdjustStockRequest.php`, `ProductController.php` | Inject `AdjustStockRequest` in `StockController::adjust()`, expand valid types to `'in,out,increment,decrement'`, delete dead method `ProductController::adjustStock()`, and update calling tests. | PLANNED |
| 4 | P2.4 | `ProfitByCategoryExport.php`, `MonthlyBalanceExport.php`, `SalesAnalyticsRepository.php` | Unify Excel exports with repository, excluding internal accounts and deducting cash expenses. | PLANNED |
| 5 | P2.5 | `ValidateSessionToken.php:47` | Connect native Laravel Auth: add `\Illuminate\Support\Facades\Auth::setUser($user)` so `auth()->user()` is always populated. | PLANNED |
| 6 | P2.6 | `Quote::nextQuoteNumber()` | Resolve race condition in quotes: wrap in transaction with locking (or atomic sequence generation) to prevent duplicate quote numbers. | PLANNED |

## Execution Phases

### Phase 1: Exploration & Code Investigation
- **Explorer 1**: Investigate P2.1 (`DeliveryNoteController`), P2.2 (`SaleService`), P2.6 (`Quote::nextQuoteNumber`). Analyze concurrency, transaction boundaries, existing tests, and edge cases.
- **Explorer 2**: Investigate P2.3 (`StockController`, `AdjustStockRequest`, `ProductController`), P2.4 (Excel exports & `SalesAnalyticsRepository`), P2.5 (`ValidateSessionToken`). Identify signatures, callers, test files, and repository methods.

### Phase 2: Implementation (Worker)
- Dispatch Worker with unified exploration blueprint.
- Worker implements fixes for P2.1 through P2.6.
- Worker runs `php artisan test` and updates existing tests that mock/call obsolete signatures or expectations.
- Worker verifies that Phase P1 changes are untouched and all changes remain unstaged.

### Phase 3: Adversarial Review & Challenger Verification
- Dispatch Reviewers to inspect code diff, logic correctness, and adhere to requirements.
- Dispatch Challenger to test concurrency (locks/transactions on Quotes and DeliveryNotes).

### Phase 4: Forensic Audit & Integrity Gate
- Dispatch Auditor to verify no shortcuts, no hardcoded test values, no fake facades.
- Gate evaluation.
- Handoff to Sentinel.
