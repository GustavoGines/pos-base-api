# Project: Sistema POS Backend - Phase P1 Implementation (Emergencias y Seguridad Operacional)

## Architecture
- Framework: Laravel 10 / PHP 8.x
- Target Project: `C:\laragon\www\Sistema_POS\pos-backend`
- Database: MySQL in production (Laragon), SQLite in-memory (`:memory:`) in testing (`APP_ENV=testing`)
- Frontend Client: Flutter POS application (`C:\laragon\www\Sistema_POS\pos-frontend`) communicating via REST API (`/api/...`) with `X-Session-Token` and WebSockets (`dashboard` channel)

## Feature Inventory
| # | Feature ID | Feature Name | Description | Target Files | Milestone | Source |
|---|------------|--------------|-------------|--------------|-----------|--------|
| 1 | P1.5 (SEC-04) | Remove Login Backdoor & Fix Fatal Constant Crash | Remove orphan `self::GHOST_MASTER_HASH` check in `AuthController:34`. Update `tests/Feature/AuthTest.php` test_A05 to assert 401 Unauthorized. Restores test suite to 80/80 green. | `app/Http/Controllers/Api/AuthController.php`, `tests/Feature/AuthTest.php` | M1 | Survey |
| 2 | P1.1 (DEBT-01/FIN-06) | Fix MySQL ENUM in Customer Transactions for Refunds | Add migration altering `customer_transactions.type` to `enum('charge', 'payment', 'refund')` with SQLite guard (`DB::getDriverName() !== 'sqlite'`). | `database/migrations/2026_09_27_000001_add_refund_to_customer_transactions_type_enum.php` | M2 | Survey |
| 3 | P1.2 (DEBT-02/FIN-07) | Update Shift and Cashier on Pending Sale Pay | Update `$lockedSale` with `cash_shift_id` and `cashier_id` from `$context` in `SaleService::payPendingSale()`. | `app/Services/SaleService.php` | M2 | Survey |
| 4 | P1.3 (FIN-04) | Persist `price_list` on Sale Model | Assign `'price_list' => $context->priceList` in `Sale::create()` and remove from `items()->create()`. | `app/Services/SaleService.php` | M2 | Survey |
| 5 | P1.6 (DEBT-08/SEC-07) | Secure File Upload in Supplier Invoices | Validate `mimes:pdf,jpeg,png,jpg|max:10240`, verify MIME type, sanitize file names, and prevent script execution in storage. | `app/Http/Controllers/Api/SupplierInvoiceController.php`, storage config / `.htaccess` | M3 | Survey |
| 6 | P1.7 (DEBT-09/10/SEC-08/09) | Protect Public Endpoints & Remove Path Disclosure | Move `/customers`, `/sales`, and `/sales/pending` into `session.validate` middleware group. Remove or secure `/system/install-path`. | `routes/api.php`, `app/Http/Controllers/Api/SystemController.php` | M3 | Survey |
| 7 | P1.8 (DEBT-11/SEC-05) | Harden Rescue Migration Endpoint to Fail-Secure | Require `$secret` to be non-empty AND match `X-Rescue-Token` header; abort 403 otherwise. | `app/Http/Controllers/Api/SystemController.php` | M3 | Survey |
| 8 | P1.9 (ROU-02) | Real-time WebSocket Dashboard Broadcasting | Implement `ShouldBroadcastNow` on `SaleCompleted`, broadcast on public channel `dashboard` as `App\Events\DashboardUpdated`. | `app/Events/SaleCompleted.php` | M3 | Survey |
| 9 | P1.10 (ARC-04) | Resolve Artisan `license:sync` Command Collision | Rename or deprecate legacy signature in `SyncLicenseStatus.php` to prevent collision with `SyncLicenseCommand.php`. | `app/Console/Commands/SyncLicenseStatus.php` | M3 | Survey |
| 10 | P1.E2E | Phase P1 Comprehensive Automated Verification Suite | Feature test suite validating all P1 fixes: refund enum, shift assignment, price_list, file upload mime, protected routes, fail-secure rescue migrate, broadcast. 100% green suite. | `tests/Feature/PhaseP1SecurityAndIntegrityTest.php` | M4 | Survey |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Auth & Login Fatal Crash Fix | P1.5 (SEC-04): Remove orphan backdoor code in `AuthController.php`, update `AuthTest.php` | None | DONE |
| M2 | Financial & Sale Engine Integrity | P1.1 (DEBT-01), P1.2 (DEBT-02), P1.3 (FIN-04): Enum refund migration, pending sale shift/cashier update, sale price_list | M1 | DONE |
| M3 | Operational Security & Infrastructure | P1.6 (DEBT-08), P1.7 (DEBT-09/10), P1.8 (DEBT-11), P1.9 (ROU-02), P1.10 (ARC-04): File upload validation, route protection, fail-secure rescue migrate, WebSocket broadcasting, Artisan command collision fix | M1 | DONE |
| M4 | E2E Validation & 100% Green Test Suite | P1.E2E: Comprehensive test file `PhaseP1SecurityAndIntegrityTest.php`, full test suite execution (`php artisan test`) passing 100% green | M1, M2, M3 | DONE |

## Interface Contracts
- **Auth Contract**: `POST /api/auth/pin` validates PIN against active admin/user, returns `{success: true, data: {token, user}}` or 401 Unauthorized.
- **Refund ENUM**: `customer_transactions.type` accepts `'charge' | 'payment' | 'refund'`.
- **Pending Sale Pay**: `SaleService::payPendingSale($saleId, SaleContext $context)` sets `cash_shift_id = $context->cashShiftId` and `cashier_id = $context->userId`.
- **Sale Price List**: `Sale::create()` persists `price_list` field.
- **Supplier Invoice Upload**: `POST /api/purchases/invoices/{id}/attachment` requires authenticated session, rejects non-allowed MIME types with 422 Unprocessable Entity.
- **Protected Routes**: `GET /api/customers`, `GET /api/sales`, `GET /api/sales/pending` require valid `X-Session-Token`, returning 401 if missing/invalid.
- **Rescue Migrate**: `POST /api/system/rescue-migrate` requires `X-Rescue-Token` matching non-empty `APP_RESCUE_SECRET` / config, returning 403 on mismatch.
- **Sale Broadcasting**: `SaleCompleted` implements `ShouldBroadcastNow`, broadcasts on channel `'dashboard'` as `'App\Events\DashboardUpdated'`.

## Code Layout
- Controllers: `app/Http/Controllers/Api/`
- Services: `app/Services/`
- Events: `app/Events/`
- Commands: `app/Console/Commands/`
- Migrations: `database/migrations/`
- Routes: `routes/api.php`
- Tests: `tests/Feature/`
