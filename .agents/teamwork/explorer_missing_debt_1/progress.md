# Progress — explorer_missing_debt_1

Last visited: 2026-09-26T20:05:00Z

- [x] Initialized DISPATCH.md, BRIEFING.md, and progress.md
- [x] Read ORIGINAL_REQUEST.md and backend_tech_debt_report.md
- [x] Inspect Services & DB Transactions (Concurrency, Race Conditions, Rollbacks)
  - Discovered Bug: `SaleService::payPendingSale` drops `cash_shift_id`, causing paid pending tickets to be excluded from the active shift's cash closing expected balance
  - Discovered Bug: `SaleService::processItems` ignores `unit_price` sent in request, overwriting it with `getPriceForQuantity` and decoupling it from `subtotal`
  - Discovered Bug: `ClearStaticPricesCommand` wiped wholesale/card prices claiming global factor engine, but no factor engine exists
- [x] Inspect Controllers & Routes (Validation, Auth, Middleware, Route Parameters)
  - Discovered Critical Vulnerability: `AuthController::authorizePin` queries users without filtering `role = 'admin'`, allowing cashier PIN to authorize administrative actions
  - Discovered Critical Vulnerability: `SupplierInvoiceController::uploadAttachment` lacks MIME/extension validation, allowing arbitrary file upload (PHP/RCE)
  - Discovered Vulnerability: Public unauthenticated endpoints (`GET /api/sales?period=all` with no pagination, `GET /api/customers`, `GET /api/system/install-path`)
  - Discovered Vulnerability: `SystemController::rescueMigrate` is fail-open if `rescue_migrate_secret` is empty
  - Discovered Flaw: `ValidateSessionToken` sets attribute but never calls `Auth::setUser($user)`, breaking `auth()->id()` throughout the app
- [x] Inspect Repositories & SQL queries (Performance, Indexing, N+1)
  - Discovered Bug: `SalesAnalyticsRepository` casts `items_sold` to `(int)`, truncating fractional/weighted product sales
  - Discovered Flaw: `ReportController` caches financial metrics for 15 minutes with no invalidation on sale/void/expense
  - Discovered Bottleneck: `CatalogController::bulkPriceRevert` runs N individual SQL queries in a loop
- [x] Inspect Models (Guarded/Fillable, Casts, Mutators, Relations)
  - Discovered Missing Casts: `SupplierInvoice`, `SupplierInvoiceItem`, `DeliveryNoteItem` lack casts for monetary/date/quantity fields
  - Discovered Missing Relations: `StockMovement`, `ThirdPartyCheck`, `CustomerTransaction` lack `cashShift()` relation
- [x] Inspect Console Commands & Scheduled Tasks
  - Corrected Hallucination from previous report: `license:sync` in `routes/console.php` runs every 3 minutes, not daily at 04:00
  - Verified command collision between `SyncLicenseStatus` and `SyncLicenseCommand`
- [x] Inspect Database Migrations & Seeders
  - Discovered Critical Bug: `customer_transactions.type` is `enum('charge', 'payment')` in MySQL, so `'refund'` triggers `SQLSTATE[01000]/[22001]: Data truncated for column 'type'` and crashes on MySQL
  - Discovered Anti-pattern: `2026_04_26_000000_clear_cache_and_optimize.php` calls artisan optimize inside migration
  - Discovered Performance Gap: Missing database indexes on `sales.created_at`, `sales.status`, `customer_transactions`, etc.
  - Discovered Double Deduction: Delayed delivery notes generate double stock deduction for retail sales
- [x] Executed empirical programmatic verifications via tinker / CLI
- [ ] Synthesize findings into handoff.md with complete evidence chains
- [ ] Report back to orchestrator_2
