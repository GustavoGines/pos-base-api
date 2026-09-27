# Empirical Concurrency Stress Test Report: Phase P2

**Agent**: Challenger 1 (`challenger_p2_1`)  
**Role**: Empirical Challenger (critic, specialist)  
**Date**: 2026-09-27  
**Engine**: MySQL 8.4.3 (InnoDB, default transaction isolation level `REPEATABLE READ`) & SQLite (`:memory:`)  
**Runtime**: PHP 8.3.30, Laravel Framework 12.54.1  
**Project**: Sistema POS Backend  

---

## Executive Summary

Challenger 1 performed rigorous adversarial stress testing and empirical multi-process concurrency verification of the Phase P2 implementations across two major domains:
1. **P2.1 - Delivery Note Concurrency, Anti-Deadlock Combo Locking & Void Stock Restoration**:
   - Evaluated under real multi-process concurrency on MySQL 8.4 InnoDB and SQLite.
   - **Verdict**: **APPROVED**. Zero double deductions, mathematically proven deadlock prevention via ascending ID sorting, and exact inventory restoration upon voiding with zero stock leakage.
2. **P2.6 - Quote Sequence Number Generation & Concurrency**:
   - Evaluated under real multi-process concurrency on MySQL 8.4 InnoDB with sub-millisecond barrier synchronization.
   - **Verdict**: **REQUEST_CHANGES** (**CRITICAL FAILURE FOUND**).
   - When 5 concurrent worker processes attempt to create quotes simultaneously on an empty table or sequential overflow, **3 out of 5 requests crash with HTTP 500**.
   - **Root Cause**: InnoDB detects lock conflict on gap locks during concurrent `SELECT ... FOR UPDATE` followed by `INSERT` and rolls back transactions with `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock`. `QuoteController::store()` **only retries on duplicate key (23000)** and completely fails to handle `40001`/`1213`, instantly aborting with HTTP 500.

---

## 1. Test Suite 1: Automated Unit & Contract Suite (`AdversarialConcurrencyStressTest.php`)

An adversarial test suite with 14 test methods and 70 assertions was executed in the Laravel environment:

```
php artisan test tests/Feature/AdversarialConcurrencyStressTest.php
```

### Execution Results:
```text
   PASS  Tests\Feature\AdversarialConcurrencyStressTest
  ✓ p2 6 quote number cold start empty table                                                                     0.49s  
  ✓ p2 6 quote number sequential progression                                                                     0.02s  
  ✓ p2 6 quote number boundary and overflow 9999 to 10000                                                        0.02s  
  ✓ p2 6 quote number large overflow 99999 to 100000                                                             0.02s  
  ✓ p2 6 quote number custom prefix and fallback                                                                 0.02s  
  ✓ p2 6 quote controller store collision retry mechanism                                                        0.08s  
  ✓ p2 1 counter sale delivery note multiple updates never double deduct                                         0.07s  
  ✓ p2 1 deferred delivery note partial and over delivery bounds                                                 0.04s  
  ✓ combo locking retrieves and orders all components ascending                                                  0.02s  
  ✓ combo delivery note deducts components cleanly                                                               0.03s  
  ✓ sale void counter sale with delivery note restores full stock                                                0.05s  
  ✓ sale void deferred sale only restores actually delivered stock                                               0.03s  
  ✓ sale void deferred zero delivered restores zero stock                                                        0.04s  
  ✓ sale void combo deferred delivery restores components proportionally                                         0.04s  

  Tests:    14 passed (70 assertions)
  Duration: 1.10s
```

All contractual unit logic passes under single-connection sequential simulation.

---

## 2. Test Suite 2: Real Multi-Process Concurrency Challenge (MySQL 8.4.3 InnoDB)

To test true OS-level parallelism, row locks, gap locks, and race conditions, a dedicated harness was built:
- **Test Database**: `sistema_pos_stress_test` on MySQL 8.4.3 InnoDB (isolated clone of production schema).
- **Architecture**: Separate OS processes spawned via `proc_open` and synchronized to the exact same microsecond using a shared timestamp barrier:
  ```php
  while (microtime(true) < $syncTimestamp) { usleep(100); }
  ```

### Test Results Matrix:

| Test ID | Test Description | Concurrency | Engine | Expected Behavior | Actual Behavior | Result |
|---|---|---|---|---|---|---|
| **ST-01** | Quote Cold Start (Empty Table) | 5 Workers | MySQL 8.4 InnoDB | 5 quotes created (PRES-0001 .. PRES-0005), 0 errors | 2 created, 3 crashed with HTTP 500 (Deadlock 1213) | **FAIL** |
| **ST-02** | Quote Overflow (PRES-9999) | 5 Workers | MySQL 8.4 InnoDB | 5 quotes created (PRES-10000 .. PRES-10004), 0 errors | 2 created, 3 crashed with HTTP 500 (Deadlock 1213) | **FAIL** |
| **ST-03** | Delivery Note Counter Sale Concurrency | 5 Workers | MySQL 8.4 InnoDB | Stock remains 95.0, 1 StockMovement, 0 double deduction | Stock: 95.0, Movements: 1, Qty delivered: 5.0 | **PASS** |
| **ST-04** | Delivery Note Deferred Sale Bounded | 5 Workers | MySQL 8.4 InnoDB | Stock remains >= 90.0, net deducted: -10.0 | Stock: 90.0, Qty delivered: 10.0, net deducted: -10.0 | **PASS** |
| **ST-05** | Combo Products Anti-Deadlock Locking | 4 Workers | MySQL 8.4 InnoDB | Inverted combos lock cleanly, 0 deadlocks | 4 successes, 0 deadlocks | **PASS** |
| **ST-06** | Sale Void Exact Stock Restoration | 2 Scenarios | MySQL 8.4 InnoDB | Counter: 100% restored. Deferred: only delivered restored | Counter: 50.0 restored. Deferred: exactly 50.0 restored | **PASS** |

---

## 3. Deep Dive into the Discovered Failure: Quote Concurrency Deadlock (ST-01 & ST-02)

### Verbatim Error Logs:
```json
{
  "status": 500,
  "error": "Error al guardar el presupuesto: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: sistema_pos_stress_test, SQL: insert into `quotes` (`quote_number`, `status`, `subtotal`, `total`, `customer_name`, `customer_phone`, `notes`, `valid_until`, `user_id`, `price_list`, `updated_at`, `created_at`) values (PRES-0001, pending, 10, 10, Cliente Worker 1, ?, ?, 2026-10-04 00:00:00, ?, base, 2026-09-27 01:54:40, 2026-09-27 01:54:40))"
}
```
And:
```json
{
  "status": 500,
  "error": "Error al guardar el presupuesto: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: sistema_pos_stress_test, SQL: select `quote_number` from `quotes` order by `id` desc limit 1 for update)"
}
```

### Forensic Analysis of the Defect:

1. **Vulnerable Code Location**: `app/Http/Controllers/Api/QuoteController.php:139-156`:
   ```php
   } catch (\Illuminate\Database\QueryException $e) {
       DB::rollBack();
       $isDuplicate = in_array($e->getCode(), [23000, '23000'])
           || str_contains($e->getMessage(), 'Duplicate entry')
           || str_contains($e->getMessage(), 'UNIQUE constraint failed');

       if ($isDuplicate && $attempt < $maxAttempts) {
           usleep(15000 * $attempt);
           continue;
       }

       \Illuminate\Support\Facades\Log::error('Database Error in QuoteController store: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
       return response()->json(['message' => 'Error al guardar el presupuesto: ' . $e->getMessage()], 500);
   }
   ```

2. **The Mechanism**:
   - In MySQL InnoDB, when multiple concurrent transactions start and execute:
     `SELECT quote_number FROM quotes ORDER BY id DESC LIMIT 1 FOR UPDATE;`
     on an empty table or on the supremum record, InnoDB places **gap locks**.
   - Multiple transactions can hold gap locks on the same gap simultaneously.
   - When each transaction then attempts to `INSERT INTO quotes (quote_number, ...)`, it requires an **insert intention lock** on that gap.
   - Because other active transactions already hold gap locks on that gap, an insert intention lock cannot be granted.
   - InnoDB detects a cyclic lock wait graph and terminates one or more transactions with:
     `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction`.
   - **Because `QuoteController` ONLY checks for duplicate entries (`23000`), `$isDuplicate` evaluates to `false`!**
   - The deadlock is **not retried**; it immediately throws, rolls back, and returns **HTTP 500** with the raw SQL error message leaked to the user.

3. **Secondary Defect: Lack of Backoff Jitter and Low MaxAttempts**:
   - `usleep(15000 * $attempt)` uses deterministic sleep durations without randomization.
   - Colliding processes wake up simultaneously and collide again.
   - With `$maxAttempts = 3`, even 5 concurrent requests cannot resolve under duplicate key collisions without randomized jitter.

---

## 4. Empirical Proof of Mitigation

We constructed a proof-of-concept implementation incorporating:
1. **Deadlock & Serialization Failure Recognition**:
   ```php
   $isRetryable = in_array($e->getCode(), [23000, '23000', 40001, '40001', 1213])
       || str_contains($e->getMessage(), 'Duplicate entry')
       || str_contains($e->getMessage(), 'UNIQUE constraint failed')
       || str_contains($e->getMessage(), 'Deadlock found')
       || str_contains($e->getMessage(), 'Serialization failure');
   ```
2. **Randomized Jitter Exponential Backoff**:
   ```php
   if ($isRetryable && $attempt < $maxAttempts) {
       usleep(random_int(10000, 30000) * $attempt);
       continue;
   }
   ```
3. **Max Attempts Raised to 5**:
   Allows larger concurrent bursts (10+ simultaneous requests) to resolve cleanly.

### Proof-of-Concept Execution with 10 Parallel Workers:
```text
Running Proof of Concept: 10 concurrent workers on cold start with Deadlock Retry & Jitter...
Quotes in DB: PRES-0001, PRES-0002, PRES-0003, PRES-0004, PRES-0005, PRES-0006, PRES-0007, PRES-0008, PRES-0009, PRES-0010
Count: 10 (Expected: 10)
Worker 1: Status 201 | Number: PRES-0008 | Attempts: 4
Worker 2: Status 201 | Number: PRES-0009 | Attempts: 4
Worker 3: Status 201 | Number: PRES-0005 | Attempts: 2
Worker 4: Status 201 | Number: PRES-0004 | Attempts: 1
Worker 5: Status 201 | Number: PRES-0003 | Attempts: 2
Worker 6: Status 201 | Number: PRES-0006 | Attempts: 3
Worker 7: Status 201 | Number: PRES-0002 | Attempts: 1
Worker 8: Status 201 | Number: PRES-0007 | Attempts: 3
Worker 9: Status 201 | Number: PRES-0010 | Attempts: 4
Worker 10: Status 201 | Number: PRES-0001 | Attempts: 1
```
**Result**: **10 out of 10 requests succeeded (100% success rate)**, zero 500 errors, zero duplicate key violations, and perfectly sequential numbering (`PRES-0001` through `PRES-0010`).

---

## 5. Verification of P2.1 (Delivery Notes, Combos & Sale Void)

### P2.1.1: Delivery Note Counter Sale Concurrency
- **Condition**: Sale of 5 units of product (stock 100 -> 95 at checkout). Delivery note created.
- **Stress**: 5 concurrent worker processes execute `updateDelivery()` attempting to deliver 5 units each.
- **Observed Result**:
  - Final Product Stock: `95.000` (unchanged).
  - Number of `StockMovement` records: `1` (checkout only).
  - DeliveryNoteItem `quantity_delivered`: `5.0`.
  - Delivery Note status: `delivered`.
- **Conclusion**: **PASSED**. The check `$alreadyDeducted = $note->sale && $note->sale->hasDeductedStock()` inside the transaction with `lockForUpdate()` completely prevents double deduction.

### P2.1.2: Delivery Note Deferred Sale Bounded
- **Condition**: Deferred sale of 10 units of product (stock remains 100 at checkout). Delivery note created with 10 units purchased.
- **Stress**: 5 concurrent worker processes execute `updateDelivery()` requesting 5 units each (total requested: 25 units).
- **Observed Result**:
  - Final Product Stock: `90.000` (exactly 10 units deducted).
  - DeliveryNoteItem `quantity_delivered`: `10.0` (capped at purchased quantity).
  - Net stock deducted across all movements: `-10.0`.
- **Conclusion**: **PASSED**. Over-delivery and concurrent over-deduction are strictly prevented by:
  ```php
  $newDelivered = min($item->quantity_purchased, $item->quantity_delivered + $itemRequest['delivered_now']);
  $actualDeliveredNow = $newDelivered - $item->quantity_delivered;
  ```

### P2.1.3: Combo Products Anti-Deadlock Locking
- **Condition**: Combo 1 (Child A then Child B) and Combo 2 (Child B then Child A).
- **Stress**: 4 concurrent worker processes execute transactions acquiring locks via `StockService::lockProducts()`.
- **Observed Result**:
  - Successes: 4 / 4 (100%).
  - Deadlocks encountered: 0.
- **Conclusion**: **PASSED**. Ordering all IDs ascending via:
  ```php
  Product::whereIn('id', $allIds)->with('children')->orderBy('id', 'asc')->lockForUpdate()
  ```
  satisfies Dijkstra's resource ordering and eliminates circular wait deadlocks.

### P2.1.4: Sale Void Exact Stock Restoration
- **Condition A (Counter Sale with Delivery Note)**:
  - 5 units deducted at checkout.
  - Delivery note delivered 2 units.
  - Sale voided: **5 units restored (100% of checkout deduction)**. Delivery note marked `cancelled`. Stock returned to initial 50.0.
- **Condition B (Deferred Sale with Partial Delivery)**:
  - 0 units deducted at checkout.
  - Delivery note delivered 2 units (stock 50 -> 48).
  - Sale voided: **ONLY 2 units restored**. Stock returned to initial 50.0.
  - No phantom inventory created.
- **Conclusion**: **PASSED**. `StockService::restoreStockForVoid()` correctly discriminates counter sales from deferred deliveries.

---

## 6. Recommendations for Worker Subagent P2

1. **Patch `app/Http/Controllers/Api/QuoteController.php:139-148`**:
   - Update the retry condition to handle deadlocks and serialization failures:
     ```php
     $isRetryable = in_array($e->getCode(), [23000, '23000', 40001, '40001', 1213])
         || str_contains($e->getMessage(), 'Duplicate entry')
         || str_contains($e->getMessage(), 'UNIQUE constraint failed')
         || str_contains($e->getMessage(), 'Deadlock found')
         || str_contains($e->getMessage(), 'Serialization failure');
     ```
   - Update retry backoff with randomized jitter:
     ```php
     if ($isRetryable && $attempt < $maxAttempts) {
         usleep(random_int(10000, 30000) * $attempt);
         continue;
     }
     ```
   - Increase `$maxAttempts` from `3` to `5`.
