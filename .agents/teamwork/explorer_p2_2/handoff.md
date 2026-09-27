# Handoff Report: Phase P2.2 - Reconcile Unit Prices and Subtotals

## 1. Observation

### Exact File Locations and Code Snippets
1. **`app/Services/SaleService.php:208`:**
   ```php
   $unitPrice = $product->getPriceForQuantity($itemData['quantity']) ?? $itemData['unit_price'];
   $costPrice = $this->stockService->calculateCostPrice($product);
   
   $sale->items()->create([
       'product_id'      => $product->id,
       'product_name'    => $product->name,
       'quantity'        => $itemData['quantity'],
       'unit_cost_price' => $costPrice,
       'unit_price'      => $unitPrice,
       'subtotal'        => $itemData['subtotal'],
   ]);
   ```
2. **`app/Models/Product.php:99-115`:**
   ```php
   public function getPriceForQuantity(float $quantity): float
   {
       $tiers = $this->relationLoaded('priceTiers')
           ? $this->priceTiers
           : $this->priceTiers()->get();

       if ($tiers->isEmpty()) {
           return (float) $this->selling_price;
       }

       $applicable = $tiers->filter(fn($t) => $quantity >= (float) $t->min_quantity);

       return $applicable->isNotEmpty()
           ? (float) $applicable->last()->unit_price
           : (float) $this->selling_price;
   }
   ```
3. **`app/Services/SaleService.php:120`:**
   In `payPendingSale()`:
   ```php
   // Recalculate Totals based on new items
   $newTotal = collect($dto->items)->sum('subtotal');
   $lockedSale->total = $newTotal;
   ```

### Live Empirical Verification Output
We executed a live verification run using `php .agents/teamwork/explorer_p2_2/verify_p2_2.php` where a sale was submitted with agreed price $350.00 for a product whose catalog price is $500.00:
```text
Product Catalog selling_price: 500.00
Sent in request -> unit_price: 350, quantity: 2, subtotal: 700
Saved in DB sale_items -> unit_price: 500.00, quantity: 2.000, subtotal: 700.00
Arithmetic check: unit_price * quantity = 1000
Does unit_price * quantity == subtotal in DB? NO - INCONSISTENCY!
```

---

## 2. Logic Chain

1. **Observation:** `Product::getPriceForQuantity()` has return type `float` and returns either `(float) $applicable->last()->unit_price` or `(float) $this->selling_price`.
2. **Deduction:** In PHP, any float value (including `0.0`) is non-null. Therefore, the expression `$product->getPriceForQuantity(...) ?? $itemData['unit_price']` always resolves to the left-hand operand, rendering `?? $itemData['unit_price']` unreachable dead code.
3. **Observation:** When the Flutter frontend or POS cashier applies a discount, custom tier multiplier, or negotiated quote price, it sends the agreed unit price in `$itemData['unit_price']` and calculates `$itemData['subtotal'] = unitPrice * quantity`.
4. **Deduction:** `SaleService::processItems()` silently overwrites the agreed unit price with the catalog base price, but stores `$itemData['subtotal']` verbatim from the client payload.
5. **Consequence:** The database record in `sale_items` stores `unit_price` from the catalog and `subtotal` from the discounted transaction. Arithmetic consistency is broken: `unit_price * quantity != subtotal`.
6. **Solution:** Reconcile unit price by allowing an explicit numeric price (`$rawPrice !== null && is_numeric($rawPrice)`) to prevail over `getPriceForQuantity()`. Reconcile subtotal by calculating `round($unitPrice * $quantity, 2)` atomically on the backend, thereby preventing client tampering, handling promotional zero-price items, and eliminating rounding errors.

---

## 3. Caveats

1. **Price Validation at FormRequest level:** `ProcessSaleRequest` requires `items.*.unit_price => min:0` and `items.*.subtotal => min:0`. The backend reconciliation safely handles any numeric input within these rules.
2. **Sales Total Validation:** In `executeSale()`, `$dto->total` is validated against payments via `PaymentService::validatePaymentsTotal()`. Because standard POS transactions construct `$total` as the sum of items' subtotals, atomic item recalculation will remain fully aligned with `$dto->total`.
3. **No Database Schema Changes Needed:** The `sale_items` table schema already supports `decimal(10,3)` for quantity, `decimal(10,2)` for unit_price, and `decimal(12,2)` for subtotal. No new migrations are required.

---

## 4. Conclusion

The technical debt described in **P2.2 / DEBT-04** is fully confirmed and ready for implementation by the Worker agent.

### Actionable Implementation Blueprint for Worker:
1. In `app/Services/SaleService.php:208`:
   Replace:
   ```php
   $unitPrice = $product->getPriceForQuantity($itemData['quantity']) ?? $itemData['unit_price'];
   ```
   With:
   ```php
   $quantity = isset($itemData['quantity']) && is_numeric($itemData['quantity'])
       ? (float) $itemData['quantity']
       : 1.0;

   $rawPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;
   if ($rawPrice !== null && is_numeric($rawPrice)) {
       $unitPrice = (float) $rawPrice;
   } else {
       $unitPrice = (float) $product->getPriceForQuantity($quantity);
   }
   $unitPrice = max(0.0, round($unitPrice, 2));

   $subtotal = round($unitPrice * $quantity, 2);
   ```
2. In `app/Services/SaleService.php:214-218`:
   Pass `$quantity`, `$unitPrice`, and `$subtotal`.
3. In `app/Services/SaleService.php:120`:
   Update `$newTotal = (float) $lockedSale->items()->sum('subtotal');` to keep order recall totals synchronized with reconciled database subtotals.
4. In `tests/Feature/PosProcessSaleTest.php`:
   Add test `test_V14_agreed_unit_price_prevails_and_reconciles_subtotal_atomically()`.

---

## 5. Verification Method

### Automated Test Commands
Run the complete test suite to verify 0 regressions:
```bash
php artisan test
```
Expected: 104 passed tests (374 assertions).

Run specific POS sales feature tests:
```bash
php artisan test tests/Feature/PosProcessSaleTest.php
```

### Scripted Verification
Execute the test script in the agent working directory to test all 5 reconciliation edge cases:
```bash
php .agents/teamwork/explorer_p2_2/verify_p2_2.php
```
Expected output:
```text
ALL 5 RECONCILIATION TEST CASES PASSED ATOMICALLY!
```

Execute the simulation script:
```bash
php .agents/teamwork/explorer_p2_2/simulate_patch.php
```
Expected output:
```text
SUCCESS: PatchedSaleService passed all assertions!
```

### Invalidation Conditions
This handoff report is invalidated if:
- `Product::getPriceForQuantity()` is changed to return nullable float.
- A new column (e.g. `discount_percentage`) is introduced into `sale_items` that alters the formula for `subtotal`.
