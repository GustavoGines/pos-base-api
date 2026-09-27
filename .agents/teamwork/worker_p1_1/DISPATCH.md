## 2026-09-27T02:39:41Z

You are Worker 1 (Phase P1 Implementation Worker).
Your working directory is C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\worker_p1_1.
Your project root is C:\laragon\www\Sistema_POS\pos-backend.

MANDATORY FIRST STEP:
Read C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\ORIGINAL_REQUEST.md (specifically section ## 2026-09-27T02:27:10Z) before starting work. Do not skip this.

MANDATORY SPECIFICATION AND SURVEY REPORTS:
- Read C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\orchestrator_3\PROJECT.md
- Read C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p1_spec_1\spec_report.md
- Read C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p1_tests_1\test_suite_status.md
- Read C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p1_code_1\code_analysis.md

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_preview_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

MANDATORY GIT DISCIPLINE:
DO NOT run `git commit` or `git add`. Leave all modified and new files saved on disk in an unstaged state for user review.

NOTE ON [DEBT-04]:
[DEBT-04] (PIN bypass) is already resolved and MUST NOT be touched or re-implemented.

YOUR IMPLEMENTATION SCOPE:
Implement all Phase P1 code fixes and verify tests:
1. P1.5 / SEC-04 (Remove orphan backdoor check and fix login fatal error):
   - In `app/Http/Controllers/Api/AuthController.php:34`, remove the orphan `self::GHOST_MASTER_HASH` check.
   - In `tests/Feature/AuthTest.php`, update `test_A05` so that trying to use the legacy backdoor PIN (999) asserts `assertStatus(401)` and `assertJson(['success' => false])`.
   - Run `php artisan test --filter=AuthTest` to confirm all 12 auth tests pass green.

2. P1.1 / DEBT-01 / FIN-06 (Fix MySQL ENUM in Customer Transactions for Refunds):
   - Create migration `database/migrations/2026_09_27_000001_add_refund_to_customer_transactions_type_enum.php` altering `customer_transactions.type` to `enum('charge', 'payment', 'refund')`. Guard execution with `if (DB::getDriverName() !== 'sqlite')` to remain compatible with SQLite testing and MySQL production.
   - In `CustomerController.php` (line 271), ensure refund transactions can be cleanly created.

3. P1.2 / DEBT-02 / FIN-07 (Update Shift and Cashier on Pending Sale Pay):
   - In `app/Services/SaleService.php::payPendingSale()`, update `$lockedSale` to include `'cash_shift_id' => $context->cashShiftId` and `'cashier_id' => $context->userId` (or cashier_id from context).

4. P1.3 / FIN-04 (Persist `price_list` on Sale Model):
   - In `app/Services/SaleService.php::executeSale()`, add `'price_list' => $context->priceList` to `Sale::create([...])`.
   - Remove `'price_list' => $context->priceList` from `items()->create([...])` where `sale_items` does not have that column.

5. P1.6 / DEBT-08 / SEC-07 (Secure File Upload in Supplier Invoices & Anti-RCE):
   - In `app/Http/Controllers/Api/SupplierInvoiceController.php::uploadAttachment()`, add strict validation: `'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240'`.
   - Sanitize file names (e.g. `Str::random(40) . '.' . $file->getClientOriginalExtension()`).
   - Create/verify `storage/app/public/.htaccess` to disable PHP script execution (`<FilesMatch "\.(php|phtml|phar)$"> Order Deny,Allow Deny from all </FilesMatch>`).

6. P1.7 / DEBT-09 / DEBT-10 / SEC-08 / SEC-09 (Protect Public Endpoints & Remove Path Disclosure):
   - In `routes/api.php`, move `/customers`, `/sales`, and `/sales/pending` inside the `session.validate` middleware group.
   - In `app/Http/Controllers/Api/SystemController.php`, remove or protect the `installPath()` endpoint (return 404 or require auth/session). Remove route from public if needed.

7. P1.8 / DEBT-11 / SEC-05 (Harden Rescue Migration Endpoint to Fail-Secure):
   - In `app/Http/Controllers/Api/SystemController.php::rescueMigrate()`, convert the token check to fail-secure: if `empty($secret) || $request->header('X-Rescue-Token') !== $secret`, abort with 403 Forbidden.

8. P1.9 / ROU-02 (Real-time WebSocket Dashboard Broadcasting):
   - In `app/Events/SaleCompleted.php`, implement `Illuminate\Contracts\Broadcasting\ShouldBroadcastNow`, specify public channel `new Channel('dashboard')`, and define `broadcastAs()` returning `'App\\Events\\DashboardUpdated'`.

9. P1.10 / ARC-04 (Artisan Command Collision):
   - In `app/Console/Commands/SyncLicenseStatus.php`, update signature to `license:sync-status` (with description noting deprecation in favor of `SyncLicenseCommand`), resolving signature collision with `SyncLicenseCommand.php`.

10. Automated Test Suite Validation:
    - Create `tests/Feature/PhaseP1SecurityAndIntegrityTest.php` covering each of the P1 fixes:
      - Refund transaction type allowed
      - Pending sale pay assigns shift and cashier
      - Sale model persists price_list
      - Supplier invoice upload rejects non-whitelisted files (e.g. .php, .sh) and accepts valid files (.pdf, .jpg)
      - Customer and sales routes return 401 without valid session token
      - Rescue migrate returns 403 on missing or invalid X-Rescue-Token
      - SaleCompleted event implements ShouldBroadcastNow and broadcasts on dashboard
    - Run the entire test suite `php artisan test`.
    - Ensure 100% OF TESTS PASS GREEN (both the original 80 tests and all new tests).
