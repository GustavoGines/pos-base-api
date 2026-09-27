# Handoff Report: Phase P2 Technical Debt Investigation (P2.1 & P2.6)

**From**: Explorer Subagent P2.1 (`explorer_p2_1`)  
**To**: Orchestrator / Implementer Agent  
**Date**: 2026-09-27  
**Working Directory**: `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p2_1`  
**Reference Report**: `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p2_1\analysis.md`  

---

## 1. Observation

1. **DeliveryNote Stock Deduction Current Implementation (`app/Http/Controllers/DeliveryNoteController.php:91-125`):**
   ```php
   if ($actualDeliveredNow > 0) {
       $product = \App\Models\Product::find($item->product_id);
       if ($product) {
           if ($product->is_combo) {
               $combos = \Illuminate\Support\Facades\DB::table('product_combos')
                           ->where('parent_product_id', $product->id)->get();
               foreach ($combos as $combo) {
                   $childProd = \App\Models\Product::find($combo->child_product_id);
                   if ($childProd) {
                       $qtyDeducted = $actualDeliveredNow * $combo->quantity;
                       $childProd->stock -= $qtyDeducted;
                       $childProd->save();

                       \App\Models\StockMovement::create([
                           'product_id' => $childProd->id,
                           'user_id'    => $request->attributes->get('authenticated_user')?->id,
                           'type'       => 'sale', // o 'dispatch'
                           'quantity'   => -$qtyDeducted,
                           'notes'      => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}"
                       ]);
                   }
               }
           } else {
               $product->stock -= $actualDeliveredNow;
               $product->save();

               \App\Models\StockMovement::create([
                   'product_id' => $product->id,
                   'user_id'    => $request->attributes->get('authenticated_user')?->id,
                   'type'       => 'sale', // o 'dispatch'
                   'quantity'   => -$actualDeliveredNow,
                   'notes'      => "Despacho Logístico Remito #{$note->id}"
               ]);
           }
       }
   }
   ```
   - Observed that `updateDelivery()` operates completely outside a `DB::transaction`.
   - Observed that `DeliveryNote::with('items')->findOrFail($id)` does not use `lockForUpdate()`.
   - Observed that `Product::find()` does not use `lockForUpdate()`, and combo children queries do not sort by ID.
   - Observed that `StockMovement::create` calls omit `sale_id`.

2. **Sale Stock Deduction at Checkout (`app/Services/SaleService.php:87-91` & `app/Services/StockService.php:48-64`):**
   - In `SaleService.php:87`:
     ```php
     $this->stockService->processCartStock($dto->items, $lockedProducts, $sale, $context, $dto->requiresDispatch, $dto->fulfillmentStatus);
     ```
   - In `StockService.php:50`:
     ```php
     $shouldDeductStock = (!$requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');
     if (!$shouldDeductStock) {
         return; // Stock will be deducted upon delivery
     }
     ```
   - For all regular sales (`requires_dispatch = false`), stock is decremented immediately, and `StockMovement` records are created with `sale_id = $sale->id`, `type = 'sale'`, and `notes = "Venta Ticket #{$sale->id}"`.

3. **Quote Sequential Number Generation (`app/Models/Quote.php:37-45` & `app/Http/Controllers/Api/QuoteController.php:107-118`):**
   - In `Quote.php:37-45`:
     ```php
     public static function nextQuoteNumber(): string
     {
         $last = static::latest('id')->value('quote_number');
         if (!$last) {
             return 'PRES-0001';
         }
         $num = (int) substr($last, 5);
         return 'PRES-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
     }
     ```
   - In `QuoteController.php:100-118`:
     `DB::beginTransaction()` is called, but `Quote::nextQuoteNumber()` executes a simple `SELECT ... LIMIT 1` without `lockForUpdate()`.
   - In `database/migrations/2026_04_08_000002_create_quotes_tables.php:17`:
     `$table->string('quote_number')->unique()` enforces unique constraint at the database engine level.

4. **Test Suite Baseline Execution:**
   - Ran `php artisan test tests/Feature/DeliveryNoteTest.php`: Passed (2 tests, 12 assertions).
   - Ran `php artisan test tests/Feature/SaleVoidTest.php`: Passed (5 tests, 16 assertions).
   - Ran `php artisan test tests/Feature/QuoteTest.php`: Passed (9 tests, 27 assertions).

---

## 2. Logic Chain

1. **Double Stock Deduction Causality (P2.1):**
   - From Observation 2: Standard counter POS sales have `requires_dispatch = false`. `StockService::processCartStock` deducts inventory immediately at the cash register.
   - When a clerk generates a delivery note for that sale (`generateFromSale`), the note is created with `status = 'pending'`.
   - From Observation 1: When `updateDelivery` is executed, it decrements stock unconditionally (`$product->stock -= $actualDeliveredNow; $product->save();`).
   - Therefore, the physical stock is deducted twice for the exact same sold units (e.g. 5 sold -> 10 deducted).
   - Furthermore, because `updateDelivery` lacks `DB::transaction` and pessimistic locking, concurrent requests from dispatchers will race on `$item->quantity_delivered`, leading to lost updates or duplicate deductions.

2. **Detection Mechanism for Origin Sale Deduction (P2.1):**
   - From Observation 2: When a sale deducts stock at creation, it logs a `StockMovement` with `sale_id = $sale->id`, `type = 'sale'`, and `notes` matching `'Venta Ticket #' . $sale->id . '%'`.
   - Conversely, when a sale has deferred dispatch (`requires_dispatch = true, fulfillment_status = 'pending'`), no such `StockMovement` exists.
   - Therefore, querying `StockMovement::where('sale_id', $sale->id)->where('type', 'sale')->where('notes', 'like', '%Ticket #' . $sale->id . '%')->exists()` safely determines whether stock was already deducted at checkout.
   - If `true`: `updateDelivery` must update delivery item quantities and delivery note status, but MUST NOT decrement product stock.
   - If `false`: `updateDelivery` MUST decrement stock (using `StockService::lockProducts` to guarantee deadlock-free locking).

3. **Quote Number Race Condition Causality (P2.6):**
   - From Observation 3: `Quote::nextQuoteNumber()` executes `SELECT quote_number FROM quotes ORDER BY id DESC LIMIT 1`. Because it lacks `lockForUpdate()`, two concurrent HTTP requests execute this query simultaneously and receive the identical `$last` value (e.g. `PRES-0005`).
   - Both requests calculate the identical next number (`PRES-0006`).
   - The first request to execute `Quote::create()` commits successfully.
   - The second request attempts to insert the identical `quote_number = 'PRES-0006'`.
   - From Observation 3: Because `quotes.quote_number` has a `UNIQUE` index, MySQL aborts with `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry`. The controller catches this and returns HTTP 500.
   - Solution Chain: Adding `lockForUpdate()` ensures Transaction 2 blocks until Transaction 1 commits, and reads the newly committed quote number. Adding a 3-attempt retry loop in `QuoteController::store` handles the cold-start edge case (empty table where row lock cannot attach to a non-existent row).

---

## 3. Caveats

1. **Schema Migrations**: Neither P2.1 nor P2.6 requires creating new database migrations. The existing schema (`delivery_notes`, `delivery_note_items`, `quotes`, `stock_movements`) already contains all necessary fields (`sale_id`, `quote_number` UNIQUE, audit columns).
2. **Database Engine Differences**: In SQLite (used in automated testing in `:memory:`), `lockForUpdate()` is accepted syntactically but is a no-op at row-level because SQLite locks at database-file level. In MySQL 8.0 (production), `lockForUpdate()` enforces row-level exclusive locks. The proposed code works transparently across both drivers.
3. **Legacy Tests (`DeliveryNoteTest::test_d02`)**: In `DeliveryNoteTest.php:75`, `$sale = Sale::create(...)` was created directly via Eloquent rather than `SaleService`. In that test, no `StockMovement` exists. The proposed solution detects this correctly: since no movement exists, it deducts stock on delivery, allowing `test_d02` to pass without modification.
4. **Sale Voiding Interplay (`StockService::restoreStockForVoid`)**: Line 152 of `StockService.php` checks `$deliveryNote`. If a delivery note was created for a counter sale (where stock was deducted at checkout), voiding the sale must restore the entire quantity purchased, not just `quantity_delivered`. Adjusting this line prevents stock leaks upon sale cancellation.

---

## 4. Conclusion

1. **P2.1 - Delivery Notes**:
   - Wrap `updateDelivery()` in `DB::transaction()`.
   - Lock the delivery note via `DeliveryNote::with(['items', 'sale'])->lockForUpdate()->findOrFail($id)`.
   - Check if origin sale already deducted stock via `$note->sale->hasDeductedStock()`.
   - If already deducted: update delivered quantities and status without altering product stock.
   - If not yet deducted: use `StockService::lockProducts()` to safely lock products in ascending ID order, deduct stock, and log `StockMovement` with `sale_id`.
   - Adjust `StockService::restoreStockForVoid()` to check `!$sale->hasDeductedStock()` before overriding restoration quantity.

2. **P2.6 - Quotes**:
   - Refactor `Quote::nextQuoteNumber()` to apply `lockForUpdate()` on the latest quote record within an active transaction.
   - Replace brittle string slicing `substr($last, 5)` with regex `preg_match('/(\d+)$/', $last, $matches)`.
   - Wrap `QuoteController::store()` in a retry loop (up to 3 attempts with 15ms backoff) to handle cold-start concurrency on empty tables.

---

## 5. Verification Method

1. **Run DeliveryNote Test Suite:**
   ```powershell
   php artisan test tests/Feature/DeliveryNoteTest.php
   ```
   *Expected*: 2 tests pass (100% green).

2. **Run Sale Voiding Suite (Verifying Remito restoration interplay):**
   ```powershell
   php artisan test tests/Feature/SaleVoidTest.php
   ```
   *Expected*: 5 tests pass (100% green).

3. **Run Quote Test Suite:**
   ```powershell
   php artisan test tests/Feature/QuoteTest.php
   ```
   *Expected*: 9 tests pass (100% green).

4. **Invalidation Conditions:**
   - If running `updateDelivery()` on a delivery note generated from a counter sale decreases `products.stock` below the checkout level, the fix is invalid (double deduction still occurring).
   - If calling `QuoteController::store()` concurrently produces a `QueryException: Duplicate entry` or HTTP 500 error, the fix is invalid.
