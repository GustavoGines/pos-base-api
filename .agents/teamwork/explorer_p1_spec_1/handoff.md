# Handoff Report — Explorer 1 (Spec Miner: Phase P1)

## 1. Observation

Direct code and test observations from the repository:

1. **`ORIGINAL_REQUEST.md` (lines 80-92):**
   Mandates the implementation of Phase P1 from `backend_tech_debt_report.md`. Explicitly states:
   > *"Nota para los agentes: El [DEBT-04] (Bypass de PIN) ya fue resuelto; omitir su implementación."*
   > *"Todas las modificaciones deben ser validadas corriendo la suite de pruebas automatizadas del proyecto (`php artisan test`)."*

2. **`backend_tech_debt_report.md` (lines 789-799):**
   Defines the 10 actionable items for Phase P1:
   - `P1.1`: Fix MySQL ENUM en Reembolsos (`customer_transactions.type`)
   - `P1.2`: Fix Turno en Cobro de Venta Pendiente (`SaleService::payPendingSale`)
   - `P1.3`: Fix Persistencia de `price_list` (`SaleService.php:63`, remove from `items()`)
   - `P1.4`: Cerrar Bypass de Supervisor (`AuthController::authorizePin`) -> Corresponds to DEBT-04
   - `P1.5`: Eliminar Backdoor Maestro (`AuthController.php:20, 41-58`)
   - `P1.6`: Restringir Subida de Archivos (`SupplierInvoiceController.php:145`)
   - `P1.7`: Proteger Rutas Públicas (`routes/api.php:72-74`, delete `/system/install-path`)
   - `P1.8`: Blindar Endpoint de Rescate (`SystemController::rescueMigrate`)
   - `P1.9`: Restaurar Broadcasting en Tiempo Real (`SaleCompleted.php`)
   - `P1.10`: Deshacer Colisión de Comandos (`SyncLicenseStatus.php` vs `SyncLicenseCommand.php`)

3. **Status of `[DEBT-04]` / `P1.4`:**
   In `app/Http/Controllers/Api/AuthController.php` line 133:
   ```php
   $user = clone User::withoutGlobalScope('visible')
       ->whereNotNull('pin')
       ->where('role', 'admin') // FIX: Solo los administradores pueden autorizar
       ->get()
       ->first(fn ($u) => Hash::check($pin, $u->pin));
   ```
   Migration `database/migrations/2026_09_26_214109_add_is_system_to_users_table.php` exists and created the hidden system user. `User.php` lines 28-36 registers the global scope `visible`. Confirmed 100% resolved; omitted from further implementation.

4. **Verbatim Error in `php artisan test`:**
   Running `php artisan test --filter AuthTest` produces:
   ```
   Error: Undefined constant App\Http\Controllers\Api\AuthController::GHOST_MASTER_HASH
   at app\Http\Controllers\Api\AuthController.php:34
   Tests: 4 failed, 76 passed (total 80 tests).
   ```
   Lines 31-48 in `AuthController.php` still call `Hash::check($pin, self::GHOST_MASTER_HASH)` even though the constant declaration was removed, breaking all logins and 4 test assertions in `tests/Feature/AuthTest.php`.

5. **Flutter Consumer Event Binding:**
   In `C:\laragon\www\Sistema_POS\pos-frontend\lib\features\mobile\presentation\screens\mobile_dashboard_screen.dart` lines 62-68:
   ```dart
   final channel = _pusher!.publicChannel('dashboard');
   channel.bind('App\\Events\\DashboardUpdated').listen((event) { ... });
   ```
   `SaleCompleted.php` does not implement `ShouldBroadcastNow` and targets `new PrivateChannel('channel-name')`, completely breaking real-time WebSocket notifications.

6. **Artisan Command Collision:**
   Running `php artisan license:sync` outputs:
   `Sin API Key configurada. Saltando sincronización.`
   which originates directly from `SyncLicenseStatus.php:31`, proving that the legacy command masks `SyncLicenseCommand.php`.

---

## 2. Logic Chain

1. **Premise 1:** `ORIGINAL_REQUEST.md` specifically directs implementation of Phase P1 from `backend_tech_debt_report.md` while commanding that `[DEBT-04]` (PIN bypass) must be omitted because it is already resolved.
2. **Premise 2:** Codebase inspection of `AuthController.php:133` confirms that `authorizePin()` already restricts authorization to `where('role', 'admin')`, which satisfies Premise 1.
3. **Premise 3:** However, removing the constant `GHOST_MASTER_HASH` during DEBT-04 resolution left an orphan reference `self::GHOST_MASTER_HASH` at `AuthController.php:34`. This causes PHP runtime fatal crashes during PIN verification and makes 4 tests fail in `AuthTest.php`. Removing this orphan block (`P1.5`) and aligning `AuthTest.php` is necessary to return the suite to 100% green per requirement R2.
4. **Premise 4:** Inspection of `database/migrations/2026_03_24_225300_create_customer_transactions_table.php` confirms `type` is `enum('charge', 'payment')`. Because `CustomerController:271` inserts `'refund'`, MySQL production databases will crash with error 1265 unless altered via migration (`P1.1`).
5. **Premise 5:** In `SaleService.php:150`, `$lockedSale->update(...)` omits `cash_shift_id` and `cashier_id`, creating cash discrepancy bugs at shift closure (`P1.2`).
6. **Premise 6:** In `SaleService.php:63`, `Sale::create(...)` omits `price_list`, while `processItems()` pushes it to nonexistent column in `sale_items`, resulting in `price_list = NULL` across all sales (`P1.3`).
7. **Premise 7:** `SupplierInvoiceController.php:145` lacks MIME/extension validation (`max:10240` only), enabling arbitrary executable uploads (`P1.6`).
8. **Premise 8:** `routes/api.php:49, 72-74` exposes sensitive paths and endpoints (`/system/install-path`, `/sales`, `/customers`) without authentication (`P1.7`).
9. **Premise 9:** `SystemController.php:23` uses a fail-open check `if (!empty($secret))`, permitting unauthenticated migrations if `RESCUE_MIGRATE_SECRET` is unset (`P1.8`).
10. **Premise 10:** `SaleCompleted.php` does not broadcast, and the frontend specifically binds to `App\Events\DashboardUpdated` on channel `'dashboard'` (`P1.9`).
11. **Premise 11:** Both `SyncLicenseStatus.php` and `SyncLicenseCommand.php` register signature `license:sync`, causing the legacy class to override the modern service (`P1.10`).

---

## 3. Caveats

- **No Caveats:** All Phase P1 items have been traced to their exact file lines, verified against live runtime commands (Tinker, Artisan, PHPUnit), and cross-referenced with both backend and frontend repositories.
- **Note on DEBT-04:** While `authorizePin` (P1.4) is omitted from new implementation because it was already solved, cleaning up its broken companion code in `verifyPin` (P1.5) is an essential P1 task to ensure `php artisan test` passes.

---

## 4. Conclusion

Phase P1 consists of 10 items in total:
- **1 item omitted:** `P1.4 / DEBT-04` (confirmed solved).
- **9 items to be addressed:**
  1. `P1.1`: Create migration adding `'refund'` to `customer_transactions.type` with SQLite driver guard.
  2. `P1.2`: In `SaleService::payPendingSale()`, assign `cash_shift_id` and `cashier_id` in `$lockedSale->update(...)`.
  3. `P1.3`: In `SaleService.php`, add `'price_list'` to `Sale::create()` and remove it from `items()->create()`.
  4. `P1.5`: Remove `GHOST_MASTER_HASH` check in `AuthController.php:31-48` and adjust `AuthTest.php`.
  5. `P1.6`: Add `mimes:pdf,jpeg,png,jpg` validation to `SupplierInvoiceController::uploadAttachment()`.
  6. `P1.7`: Move `/sales`, `/sales/pending`, and `customers` inside `session.validate` middleware group; delete `/system/install-path`.
  7. `P1.8`: Make `SystemController::rescueMigrate()` fail-secure (require non-empty secret matching token).
  8. `P1.9`: Implement `ShouldBroadcastNow` and channel `'dashboard'` with event name `'App\Events\DashboardUpdated'` on `SaleCompleted`.
  9. `P1.10`: Remove/deprecate `SyncLicenseStatus.php` so `license:sync` runs `SyncLicenseCommand`.

The complete specification is documented in:
`C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p1_spec_1\spec_report.md`.

---

## 5. Verification Method

To independently verify all findings:

1. **Verify Test Suite & Auth Fatal Error:**
   ```bash
   php artisan test --filter AuthTest
   ```
   Fails with `Undefined constant App\Http\Controllers\Api\AuthController::GHOST_MASTER_HASH`.

2. **Verify Artisan Command Collision:**
   ```bash
   php artisan tinker --execute="echo get_class(Artisan::all()['license:sync']);"
   ```
   Outputs `App\Console\Commands\SyncLicenseStatus`.

3. **Verify Sale price_list Omission:**
   Inspect `app/Services/SaleService.php:50-65` and `app/Services/SaleService.php:215-220`.

4. **Verify Frontend WebSocket Contract:**
   Inspect `C:\laragon\www\Sistema_POS\pos-frontend\lib\features\mobile\presentation\screens\mobile_dashboard_screen.dart:62, 68`.
