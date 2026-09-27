# Phase P2 Implementation Changes Summary

## 1. P2.1 - Shield Delivery Notes & Avoid Double Stock Deduction
- **`app/Models/Sale.php`**:
  - Added method `hasDeductedStock(): bool` checking if a `StockMovement` of type `'sale'` with notes matching `'Ticket #' . $this->id` exists for this sale.
- **`app/Services/StockService.php`**:
  - In `restoreStockForVoid()`: updated condition from `if ($deliveryNote)` to `if ($deliveryNote && !$sale->hasDeductedStock())`. This guarantees that voiding a counter sale where full stock was already deducted at checkout restores the full quantity purchased rather than only the delivered quantity.
- **`app/Http/Controllers/DeliveryNoteController.php`**:
  - Injected `StockService $stockService` via constructor.
  - Wrapped `updateDelivery()` in `DB::transaction()`.
  - Locked the delivery note using `DeliveryNote::with(['items', 'sale'])->lockForUpdate()->findOrFail($id)`.
  - Evaluated `$alreadyDeducted = $note->sale && $note->sale->hasDeductedStock();`.
  - If `$alreadyDeducted` is true: updates delivered quantities and status without decrementing product stock.
  - If `$alreadyDeducted` is false: locks products in ascending ID order via `$this->stockService->lockProducts()`, decrements stock (supporting combos and standard products), and logs `StockMovement` with `sale_id = $note->sale_id`.
- **`tests/Feature/DeliveryNoteTest.php`**:
  - Added `test_d03_update_delivery_note_does_not_double_deduct_stock_for_counter_sale` verifying that counter sales do not suffer double deductions on delivery note updates.

## 2. P2.2 - Reconcile Agreed Unit Prices & Atomic Subtotals
- **`app/Services/SaleService.php`**:
  - In `processItems()`: reconciled unit price: if explicit numeric `$rawPrice` (`$itemData['unit_price'] ?? $itemData['price']`) is provided, it prevails over catalog/tier price; otherwise fallback to `$product->getPriceForQuantity($quantity)`. Enforced non-negative rounding: `max(0.0, round($unitPrice, 2))`.
  - Computed atomic subtotal: `$subtotal = round($unitPrice * $quantity, 2)`.
  - Passed reconciled `$quantity`, `$unitPrice`, and `$subtotal` to `$sale->items()->create()`.
  - In `payPendingSale()`: reconciled `$lockedSale->total = (float) $lockedSale->items()->sum('subtotal')` and included `'total' => $lockedSale->total` in the `$lockedSale->update([...])` payload.
- **`tests/Feature/PosProcessSaleTest.php`**:
  - Added `test_V14_agreed_unit_price_prevails_and_reconciles_subtotal_atomically` asserting that agreed unit prices prevail and client-sent tampered subtotals are corrected to `unit_price * quantity`.

## 3. P2.3 - Synchronize StockController with AdjustStockRequest & Delete Dead Code
- **`app/Http/Requests/AdjustStockRequest.php`**:
  - Expanded validation rules to:
    ```php
    'type'      => 'required|in:in,out,increment,decrement',
    'quantity'  => 'required|numeric|min:0',
    'notes'     => 'nullable|string|max:500',
    'min_stock' => 'nullable|numeric|min:0',
    'user_id'   => 'nullable|exists:users,id',
    ```
  - Ensured `authorize()` returns `true`.
- **`app/Http/Controllers/Api/StockController.php`**:
  - Injected `AdjustStockRequest $request` in `adjust(AdjustStockRequest $request, Product $product)`.
  - Replaced inline `$request->validate(...)` with `$validated = $request->validated();`.
- **`app/Http/Controllers/Api/ProductController.php`**:
  - Deleted unrouted dead method `adjustStock()`.
- **`tests/Feature/CatalogStockTest.php`**:
  - Added `test_ST06_adjust_stock_request_validates_in_out_and_rejects_invalid_types`.

## 4. P2.4 - Unify Excel Exports with SalesAnalyticsRepository
- **`app/Repositories/SalesAnalyticsRepository.php`**:
  - Added method `getMonthlyBalance(string|int $startMonthOrYear, ?string $endMonth = null): array`.
  - Implemented multi-driver period grouping (`strftime('%Y-%m', sales.created_at)` for SQLite, `DATE_FORMAT(sales.created_at, '%Y-%m')` for MySQL).
  - Excluded internal accounts (`whereNotExists` on `customers.is_internal_account`).
  - Deducted cash expenses (`cash_movements` where `type = 'expense'`).
  - Returned collection of months with `revenue_with_cost` and summary totals.
- **`app/Http/Controllers/Api/ReportController.php`**:
  - Refactored `getMonthlyBalanceDataUncached` to delegate directly to `$this->analyticsRepo->getMonthlyBalance($startMonth, $endMonth)`.
- **`app/Exports/ProfitByCategoryExport.php`**:
  - Injected `SalesAnalyticsRepository` and delegated `collection()` to `$this->repository->getProfitReport($this->startDate, $this->endDate, $this->type)`.
  - Handled mapped rows supporting array and object access.
- **`app/Exports/MonthlyBalanceExport.php`**:
  - Injected `SalesAnalyticsRepository` and delegated `collection()` to `$this->repository->getMonthlyBalance($this->startMonthOrYear, $this->endMonth)`.
  - Removed raw SQL and driver-specific `DATE_FORMAT` crashes.
- **`tests/Feature/ReportTest.php`**:
  - Added `test_r06_export_profit_by_category_excel` and `test_r07_export_monthly_balance_excel_and_verifications` verifying download headers, exclusion of internal accounts, and deduction of expenses.

## 5. P2.5 - Connect Native Laravel Auth
- **`app/Http/Middleware/ValidateSessionToken.php`**:
  - Imported `Illuminate\Support\Facades\Auth`.
  - Added `Auth::setUser($user)` and `$request->setUserResolver(fn () => $user)`.
  - Maintained `$request->attributes->set('authenticated_user', $user)` for backward compatibility.
- **`tests/Feature/AuthTest.php`**:
  - Added `test_A12_validate_session_token_populates_laravel_auth_facade` asserting that `auth()->check()`, `auth()->id()`, and `auth()->user()` are properly resolved across protected routes.

## 6. P2.6 - Resolve Race Conditions in Quotes
- **`app/Models/Quote.php`**:
  - Updated `nextQuoteNumber()` to query `static::orderByDesc('id')->lockForUpdate()->value('quote_number')`.
  - Added regex `preg_match('/(\d+)$/', $last, $matches)` to reliably extract and pad sequence numbers with any length or prefix.
- **`app/Http/Controllers/Api/QuoteController.php`**:
  - Added a retry loop (up to 3 attempts with 15ms backoff) in `store()` around transaction creation to handle cold-start duplicate key collisions (`SQLSTATE[23000]`).
- **`tests/Feature/QuoteTest.php`**:
  - Added `test_Q08_next_quote_number_handles_overflow_and_custom_padding` verifying seamless numbering beyond 9999.
