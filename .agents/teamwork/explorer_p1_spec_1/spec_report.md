# Reporte de Especificación Técnica — Fase P1 (Emergencias y Seguridad Operacional)

**Módulo:** Sistema POS Backend — Laravel 12 / PHP 8.3  
**Auditor / Rol:** Explorer 1 (Specification Miner)  
**Fecha:** 2026-09-27  
**Ubicación de Documentación:** `.agents/teamwork/explorer_p1_spec_1/spec_report.md`  
**Fuentes Canónicas Auditadas:**
- `backend_tech_debt_report.md` (Sección 5, 6, 7, 8, 9, 11)
- `ORIGINAL_REQUEST.md` (Directiva ## 2026-09-27T02:27:10Z)
- Código fuente vivo en `C:\laragon\www\Sistema_POS\pos-backend`
- Código de cliente consumidor en `C:\laragon\www\Sistema_POS\pos-frontend`

---

## 1. Resumen Ejecutivo del Alcance de Fase P1

La Fase P1 se enfoca de manera estricta y exclusiva en la **Integridad Contable Crítica y Brechas de Seguridad Operacional**. Esta fase abarca 10 ítems catalogados (`P1.1` al `P1.10`), de los cuales:
- **1 Ítem OMITIDO de Implementación:** `[DEBT-04]` (Bypass de PIN de Supervisor / SEC-06 / P1.4) ya fue resuelto con la migración `2026_09_26_214109_add_is_system_to_users_table.php`, Global Scope en `User.php` y el filtro `where('role', 'admin')` en `AuthController::authorizePin()`. Conforme a la instrucción mandatoria en `ORIGINAL_REQUEST.md`, este ítem queda confirmado como resuelto y se omite de cualquier nueva implementación de código.
- **1 Ítem Co-dependiente a Limpiar:** `P1.5` / `SEC-04` (Remover Backdoor Maestro y bloque condicional en `AuthController.php:31-48`). Al eliminarse la constante `GHOST_MASTER_HASH` en la intervención de DEBT-04 pero dejarse el bloque `if (Hash::check($pin, self::GHOST_MASTER_HASH))` en `verifyPin()`, el login por PIN arroja un error fatal de PHP (`Undefined constant App\Http\Controllers\Api\AuthController::GHOST_MASTER_HASH`), haciendo fallar 4 tests de la suite (`AuthTest.php`). La remoción de este bloque y la actualización de `AuthTest.php` son prioritarias para devolver la suite a 100% verde.
- **8 Ítems Activos de Implementación Obligatoria:** P1.1 (Crash ENUM MySQL en reembolsos), P1.2 (Pérdida de turno/cajero en cobro diferido), P1.3 (Persistencia de `price_list` en ventas), P1.6 (Subida arbitraria de archivos / RCE en facturas de proveedores), P1.7 (Protección de rutas públicas de ventas y clientes; eliminación de `/system/install-path`), P1.8 (Blindaje Fail-Secure de endpoint de migraciones de rescate), P1.9 (Broadcasting en tiempo real hacia WebSockets/Pusher), y P1.10 (Deshacer colisión de comandos Artisan `license:sync`).

---

## 2. Inventario Detallado de Ítems de Deuda Técnica — Fase P1

### [OMITIDO] P1.4 / DEBT-04 / SEC-06: Bypass Crítico de PIN de Supervisor en `AuthController::authorizePin`
- **Estado:** ✅ **RESUELTO PREVIAMENTE — OMITIR IMPLEMENTACIÓN**
- **Archivos Auditados:**
  - `app/Http/Controllers/Api/AuthController.php:126-146`
  - `app/Models/User.php:28-36`
  - `database/migrations/2026_09_26_214109_add_is_system_to_users_table.php`
- **Descripción:** El endpoint `POST /api/auth/authorize-pin` previamente no filtraba por `role == 'admin'`, permitiendo que cualquier cajero ingresara su propio PIN y obtuviera autorización de supervisor (`authorized: true`).
- **Verificación en Código:** Línea 133 de `AuthController.php` ya contiene:
  ```php
  $user = clone User::withoutGlobalScope('visible')
      ->whereNotNull('pin')
      ->where('role', 'admin') // FIX: Solo los administradores pueden autorizar
      ->get()
      ->first(fn ($u) => Hash::check($pin, $u->pin));
  ```
  La migración creó el usuario del sistema y el Global Scope `visible` oculta al usuario de rescate. **No requiere modificaciones adicionales de lógica.**

---

### P1.1: Fix MySQL ENUM en Reembolsos de Clientes
- **Debt ID:** `[DEBT-01]` / `FIN-06`
- **Archivos Afectados y Líneas:**
  - `database/migrations/2026_03_24_225300_create_customer_transactions_table.php:20`
  - `app/Http/Controllers/Api/CustomerController.php:271`
  - `app/Models/CashShift.php:134-137`
  - `app/Services/CashShiftService.php:205-208`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  La migración inicial creó la tabla `customer_transactions` con la definición:
  `$table->enum('type', ['charge', 'payment']);`
  Posteriormente, en `CustomerController::registerPayment()`, al emitir reintegros en efectivo a clientes, se inserta:
  `'type' => $isRefund ? 'refund' : 'payment'`
  Tanto `CashShift.php` como `CashShiftService.php` esperan y leen explícitamente `where('type', 'refund')` para deducir el efectivo devuelto en el balance de turno.
  En entornos de testing con SQLite, la prueba pasa inadvertida porque SQLite almacena cadenas sin validar tipos ENUM. Sin embargo, en **MySQL 8.0 en modo estricto (producción)**, insertar el valor `'refund'` viola la enumeración y lanza inmediatamente:
  `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type' at row 1`
  provocando una excepción HTTP 500 y revirtiendo la devolución de dinero al cliente.
- **Estrategia de Remediación Requerida:**
  Crear una nueva migración de base de datos (ej. `database/migrations/2026_09_27_000001_alter_customer_transactions_add_refund_enum.php`) que altere la columna `type` en `customer_transactions` para admitir `'charge'`, `'payment'`, y `'refund'`.
  Para mantener compatibilidad total con SQLite en testing y MySQL en producción (conforme a la convención `TST-01` del proyecto):
  ```php
  public function up(): void
  {
      if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
          \Illuminate\Support\Facades\DB::statement("ALTER TABLE customer_transactions MODIFY COLUMN type ENUM('charge', 'payment', 'refund') NOT NULL");
      }
  }

  public function down(): void
  {
      if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
          \Illuminate\Support\Facades\DB::statement("ALTER TABLE customer_transactions MODIFY COLUMN type ENUM('charge', 'payment') NOT NULL");
      }
  }
  ```
- **Dependencias e Impacto en Pruebas:**
  - Depende de `CustomerController`, `CashShiftService` y `CustomerTransaction`.
  - Debe validarse en `CustomerPaymentTest` y en pruebas ad-hoc contra base de datos MySQL o simulada.

---

### P1.2: Fix Turno y Cajero en Cobro de Venta Pendiente
- **Debt ID:** `[DEBT-02]` / `FIN-07`
- **Archivos Afectados y Líneas:**
  - `app/Services/SaleService.php:149-160`
  - `app/Http/Controllers/Api/SalesController.php:107-113`
  - `app/Http/Requests/PaySaleRequest.php:38-42`
  - `app/Services/CashShiftService.php:148-150`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  Cuando un cliente liquida un ticket que fue emitido como pendiente (`status = pending`), el cajero que efectúa el cobro envía su `cash_shift_id` en el request validado por `PaySaleRequest`. `SalesController::pay()` empaqueta dicho ID y el `userId` en el `SaleContextDTO`.
  Sin embargo, dentro de `SaleService::payPendingSale()`, el método ejecuta:
  ```php
  $lockedSale->update([
      'status' => 'completed',
      'payment_status' => $isCuentaCorriente ? ... : 'paid',
      'total_surcharge' => $dto->totalSurcharge,
      'shipping_cost' => $dto->shippingCost,
      'amount_due' => $isCuentaCorriente ? $ccPaymentTotal : 0,
      'tendered_amount' => $dto->tenderedAmount,
      'change_amount' => $dto->changeAmount,
  ]);
  ```
  **No se actualizan `cash_shift_id` ni `cashier_id`**. Como resultado, la venta conserva el turno y cajero originales que la abrieron. Al momento en que el cajero activo cierra su turno, `CashShiftService::closeShift()` totaliza las ventas en efectivo mediante:
  `SalePayment::whereHas('sale', fn($q) => $q->where('cash_shift_id', $shiftId)...)`
  El efectivo cobrado físicamente no se computa en el arqueo del turno que lo recibió, produciendo un descuadre contable ("faltante artificial de caja").
- **Estrategia de Remediación Requerida:**
  En `SaleService::payPendingSale()`, incorporar `'cash_shift_id'` y `'cashier_id'` en la llamada a `$lockedSale->update([...])`:
  ```php
  $lockedSale->update([
      'status'          => 'completed',
      'cash_shift_id'   => $context->cashShiftId ?? $lockedSale->cash_shift_id,
      'cashier_id'      => $context->userId ?? $lockedSale->cashier_id,
      'payment_status'  => $isCuentaCorriente ? ($ccPaymentTotal >= ($totalToValidate - 0.1) ? 'pending' : 'partial') : 'paid',
      'total_surcharge' => $dto->totalSurcharge,
      'shipping_cost'   => $dto->shippingCost,
      'amount_due'      => $isCuentaCorriente ? $ccPaymentTotal : 0,
      'tendered_amount' => $dto->tenderedAmount,
      'change_amount'   => $dto->changeAmount,
  ]);
  ```
- **Dependencias e Impacto en Pruebas:**
  - Depende de `SaleContextDTO`, `PaySaleRequest`, `SalesController`, `CashShiftService`.
  - Verifica que en el cobro de una venta pendiente abierta en Turno 1 y cobrada en Turno 2, `sales.cash_shift_id == 2` y `sales.cashier_id == 2`.

---

### P1.3: Fix Persistencia de `price_list` en Ventas
- **Debt ID:** `[FIN-04]` / Hallazgo 5.1
- **Archivos Afectados y Líneas:**
  - `app/Services/SaleService.php:49-65` (omisión en creación de cabecera de venta)
  - `app/Services/SaleService.php:215-220` (asignación errónea en ítems de venta)
  - `app/Models/Sale.php:18` (incluye `'price_list'` en `$fillable`)
  - `app/Models/SaleItem.php:13` (no incluye ni posee `'price_list'`)
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  En `SaleService::executeSale()`, al persistir el registro maestro en la tabla `sales` con `Sale::create([...])`, el campo `'price_list' => $context->priceList` fue omitido por completo.
  En su lugar, en el método auxiliar `SaleService::processItems()`, se programó:
  `$sale->items()->create([ ... 'price_list' => $context->priceList ])`
  Dado que la tabla `sale_items` no tiene dicha columna ni `SaleItem` la tiene en `$fillable`, Eloquent descarta silenciosamente el atributo. En consecuencia, en la base de datos `sales.price_list` se guarda como `NULL` para el 100% de las ventas.
  En `ReportController::profitByPriceList()`, la consulta SQL agrupa por `COALESCE(price_list, 'base')`, lo que clasifica todas las ventas bajo el plan base, arruinando los reportes comerciales de listas de precios mayoristas o especiales.
- **Estrategia de Remediación Requerida:**
  1. En `SaleService.php:50-65`, agregar `'price_list' => $context->priceList` dentro del array pasado a `Sale::create([...])`.
  2. En `SaleService.php:215-220`, remover `'price_list' => $context->priceList` del array pasado a `$sale->items()->create([...])`.
- **Dependencias e Impacto en Pruebas:**
  - Depende de `SaleContextDTO`, `Sale`, `SaleItem`, `ReportController`.
  - Verificar que una venta creada con `price_list = 'mayorista'` persista efectivamente dicho valor en la tabla `sales`.

---

### P1.5: Eliminar Backdoor Maestro y Bloque Inconsistente en `AuthController`
- **Debt ID:** `SEC-04` (asociado a la resolución de DEBT-04)
- **Archivos Afectados y Líneas:**
  - `app/Http/Controllers/Api/AuthController.php:31-48`
  - `tests/Feature/AuthTest.php:18, 116-150`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  Previamente, `AuthController` contenía una constante hardcodeada `GHOST_MASTER_HASH` con un hash bcrypt que permitía a quien conociera el PIN maestro asumir la identidad del primer administrador del sistema.
  En la resolución preliminar de DEBT-04 documentada en Sección 11.1, se eliminó la definición `const GHOST_MASTER_HASH = '...';` de la clase `AuthController`, pero **se dejó vivo el bloque en `verifyPin()`**:
  ```php
  // ── PROTOCOLO DE RESCATE (Master Override) ────────────────────────
  if (Hash::check($pin, self::GHOST_MASTER_HASH)) {
      $admin = User::where('role', 'admin')->first();
      ...
  ```
  Al ser `self::GHOST_MASTER_HASH` una constante no definida, **toda llamada a `/api/auth/verify-pin` lanza un error fatal no capturado**:
  `Error: Undefined constant App\Http\Controllers\Api\AuthController::GHOST_MASTER_HASH`
  Esto rompe el inicio de sesión y causa que fallen 4 pruebas en `tests/Feature/AuthTest.php` (`test_A01`, `test_A02`, `test_A03`, `test_A05`).
- **Estrategia de Remediación Requerida:**
  1. En `AuthController.php`, eliminar completamente el bloque de rescate (líneas 31-48) en `verifyPin()`. La autenticación debe buscar exclusivamente los usuarios registrados en BD vía `User::withoutGlobalScope('visible')->whereNotNull('pin')->...`.
  2. En `tests/Feature/AuthTest.php`, eliminar o refactorizar `test_A05_protocolo_rescate_genera_token_y_flag` para que ya no intente invocar el viejo PIN hardcodeado ni la constante eliminada, o testear la autenticación del usuario oculto inyectado por la migración `2026_09_26_214109_add_is_system_to_users_table.php`.
- **Dependencias e Impacto en Pruebas:**
  - Crítico para que `php artisan test` retorne a estado 100% verde (R2).

---

### P1.6: Restringir Subida de Archivos y Riesgo de RCE en Facturas de Proveedores
- **Debt ID:** `[DEBT-08]` / `SEC-07` (en Sección 8 `[DEBT-05]`)
- **Archivos Afectados y Líneas:**
  - `app/Http/Controllers/Api/SupplierInvoiceController.php:143-155`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  El método `SupplierInvoiceController::uploadAttachment()` valida el archivo subido utilizando únicamente:
  ```php
  $request->validate([
      'file' => 'required|file|max:10240', // Max 10MB
  ]);
  ```
  No se especifican extensiones permitidas ni tipos MIME. El archivo recibido se almacena inmediatamente en el disco público:
  `$path = $request->file('file')->store('supplier_invoices', 'public');`
  En servidores de producción (Apache / Laragon / Nginx sin restricciones estrictas de ejecución en `storage/`), un usuario con acceso puede subir archivos ejecutables `.php`, `.phtml`, scripts `.sh`, o archivos `.html` maliciosos, permitiendo la ejecución remota de código (RCE) o Cross-Site Scripting (XSS) almacenado en el dominio de la aplicación.
- **Estrategia de Remediación Requerida:**
  Blindar la regla de validación en `SupplierInvoiceController::uploadAttachment()` exigiendo mimes seguros y extensiones específicas:
  ```php
  $request->validate([
      'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240',
  ]);
  ```
- **Dependencias e Impacto en Pruebas:**
  - Crear o ejecutar pruebas que aseguren que la subida de un archivo `.php` o `.txt` sea rechazada con HTTP 422, mientras que un `.pdf` o `.png` sea aceptado con HTTP 200.

---

### P1.7: Proteger Rutas Públicas de Ventas, Clientes y Eliminar `installPath`
- **Debt ID:** `[DEBT-09]`, `[DEBT-10]`, `SEC-08`, `SEC-09`
- **Archivos Afectados y Líneas:**
  - `routes/api.php:49` (`/system/install-path`)
  - `routes/api.php:72-74` (`customers`, `/sales`, `/sales/pending`)
  - `app/Http/Controllers/Api/SystemController.php:11-17`
  - `app/Http/Controllers/Api/SalesController.php:18-61`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  1. **Full Path Disclosure (`SEC-09` / `[DEBT-10]`):** La ruta pública `GET /api/system/install-path` expone en JSON la ruta absoluta del sistema de archivos del servidor (`C:\laragon\www\Sistema_POS\pos-backend`), facilitando información a posibles atacantes.
  2. **Exposición de Datos y Vector DoS (`SEC-08` / `[DEBT-09]`):** Las rutas `GET /api/customers`, `GET /api/customers/{id}`, `GET /api/sales` y `GET /api/sales/pending` están definidas fuera del middleware `session.validate`. Cualquier visitante anónimo en la red puede volcar el padrón íntegro de clientes y el historial comercial completo de ventas. Además, `/sales` ejecuta `$query->get()` sin paginación obligatoria, lo que permite colapsar la memoria del servidor (Denial of Service).
- **Estrategia de Remediación Requerida:**
  1. En `routes/api.php`: Eliminar la ruta `Route::get('/system/install-path', ...)` y el método `SystemController::installPath()`.
  2. En `routes/api.php`: Mover las rutas `Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);`, `Route::get('/sales', [SalesController::class, 'index']);` y `Route::get('/sales/pending', [SalesController::class, 'pending']);` al grupo protegido `Route::middleware(['session.validate'])->group(...)`.
  3. En `SalesController::index()`: Asegurar compatibilidad para proteger contra consumo excesivo de memoria (si `$request->has('paginate')` o paginación por defecto si `period == 'all'`).
- **Dependencias e Impacto en Pruebas:**
  - Se debe verificar que peticiones sin cabecera `X-Session-Token` a `/api/sales`, `/api/sales/pending` y `/api/customers` retornen HTTP 401.
  - Verificar que `/api/system/install-path` retorne HTTP 404.

---

### P1.8: Blindar Endpoint de Migración de Rescate (Fail-Secure)
- **Debt ID:** `[DEBT-11]` / `SEC-05` / Hallazgo 5.6
- **Archivos Afectados y Líneas:**
  - `app/Http/Controllers/Api/SystemController.php:19-32`
  - `config/app.php:134-137`
  - `routes/api.php:50`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  En `SystemController::rescueMigrate()`:
  ```php
  $secret = config('app.rescue_migrate_secret');
  if (!empty($secret)) {
      $token = $request->header('X-Rescue-Token');
      if ($token !== $secret) {
          return response()->json(['error' => 'Unauthorized'], 403);
      }
  }
  Artisan::call('migrate', ['--force' => true]);
  ```
  La condición `if (!empty($secret))` implementa un patrón **Fail-Open**: si la variable de entorno `RESCUE_MIGRATE_SECRET` no está configurada en `.env` (o es cadena vacía), la verificación se ignora y cualquier usuario no autenticado en la red puede disparar `Artisan::call('migrate', ['--force' => true])`.
- **Estrategia de Remediación Requerida:**
  Transformar la comprobación a **Fail-Secure**: abortar con HTTP 403 si el secreto no está configurado en el servidor O si el token enviado no coincide:
  ```php
  public function rescueMigrate(Request $request)
  {
      $secret = config('app.rescue_migrate_secret');

      if (empty($secret) || $request->header('X-Rescue-Token') !== $secret) {
          return response()->json(['error' => 'Unauthorized'], 403);
      }

      Artisan::call('migrate', ['--force' => true]);
      return response()->json(['success' => true, 'output' => Artisan::output()]);
  }
  ```
- **Dependencias e Impacto en Pruebas:**
  - Verificar que con secreto no configurado o token incorrecto el endpoint responda 403.

---

### P1.9: Restaurar Broadcasting en Tiempo Real (WebSockets / Pusher)
- **Debt ID:** `ROU-02` / Hallazgo 5.2
- **Archivos Afectados y Líneas:**
  - `app/Events/SaleCompleted.php:13-36`
  - `app/Events/DashboardUpdated.php:11-26`
  - `app/Services/SaleService.php:92, 159, 193`
  - Consumidor Frontend: `pos-frontend/lib/features/mobile/presentation/screens/mobile_dashboard_screen.dart:62, 68`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  En la refactorización monolítica previa de `PosController`, se sustituyó `broadcast(new DashboardUpdated())` por `event(new SaleCompleted($sale))`.
  Sin embargo:
  1. `SaleCompleted` **no implementa `ShouldBroadcastNow` ni `ShouldBroadcast`**.
  2. Su método `broadcastOn()` apunta a un canal privado ficticio (`new PrivateChannel('channel-name')`).
  3. El frontend de Flutter escucha expresamente en el canal público `'dashboard'` el evento `'App\Events\DashboardUpdated'`:
     `final channel = _pusher!.publicChannel('dashboard');`
     `channel.bind('App\\Events\\DashboardUpdated').listen(...)`
  Al no transmitirse el evento por WebSockets, las pantallas móviles, monitores de despacho y dashboards gerenciales pierden la actualización en tiempo real cuando se completa, cobra o anula una venta.
- **Estrategia de Remediación Requerida:**
  1. Hacer que `SaleCompleted` implemente `\Illuminate\Contracts\Broadcasting\ShouldBroadcastNow`.
  2. Configurar `broadcastOn()` para emitir en `[new Channel('dashboard')]`.
  3. Definir `broadcastAs()` retornando `'App\\Events\\DashboardUpdated'` (o emitir `DashboardUpdated` en paralelo) para satisfacer el binding exacto del frontend de Flutter.
- **Dependencias e Impacto en Pruebas:**
  - Depende de `SaleService`, `SaleCompleted`, `Channel`.
  - Probar que `class_implements(SaleCompleted::class)` contenga `ShouldBroadcastNow` y que `broadcastOn()` retorne el canal `'dashboard'`.

---

### P1.10: Deshacer Colisión de Comandos Artisan (`license:sync`)
- **Debt ID:** `ARC-04` / Hallazgo 5.4
- **Archivos Afectados y Líneas:**
  - `app/Console/Commands/SyncLicenseStatus.php:14` (Comando legado sobreescribiente)
  - `app/Console/Commands/SyncLicenseCommand.php:15` (Comando moderno que usa `LicenseSyncService`)
  - `routes/console.php:13`
- **Descripción del Problema / Causa Raíz / Vector de Fallo:**
  Ambos comandos declaran idéntica firma:
  `protected $signature = 'license:sync';`
  En el ciclo de auto-discovery de Laravel, al ordenarse por nombre de archivo, `SyncLicenseStatus.php` se carga después de `SyncLicenseCommand.php` y sobreescribe la firma en el Kernel de Artisan.
  Como resultado, cuando el cron de `routes/console.php` ejecuta `license:sync` cada 3 minutos, corre el código legado de `SyncLicenseStatus` (que consulta directamente `business_settings.license_api_key`), omitiendo por completo las funciones avanzadas de normalización de addons, heartbeat y validación de planes provistas por el nuevo `LicenseSyncService`.
- **Estrategia de Remediación Requerida:**
  Renombrar o deprecar `SyncLicenseStatus.php` (ej. cambiando su firma a `license:sync-legacy` o eliminando el archivo obsoleto del repositorio) de modo que `Artisan::all()['license:sync']` resuelva inequívocamente a `\App\Console\Commands\SyncLicenseCommand`.
- **Dependencias e Impacto en Pruebas:**
  - Depende de `LicenseSyncService` y `routes/console.php`.
  - Verificar mediante `get_class(Artisan::all()['license:sync'])` que la clase registrada sea `SyncLicenseCommand`.

---

## 3. Matriz de Dependencias e Interacciones entre Ítems

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        MAPA DE DEPENDENCIAS - FASE P1                                   │
└────────────────────────────────────────────────────────────────────────────────────────┘

 [P1.5] Remoción de Backdoor y Fix de AuthTest
   │
   ├── Desbloquea la suite completa de tests de PHPUnit (`php artisan test`).
   └── Permite que el resto de los fixes se validen con la suite en verde (100%).

 [P1.7] Proteger Rutas Públicas (session.validate)
   │
   ├── Afecta GET /sales, GET /sales/pending y customers.
   └── Debe coordinarse con los tests de integración para garantizar que envíen X-Session-Token.

 [P1.2] Fix Turno en Cobro de Venta Pendiente
   │
   ├── Impacta en `SaleService::payPendingSale()`.
   └── Sincroniza la contabilidad de turnos con `CashShiftService::closeShift()`.

 [P1.1] Migración MySQL ENUM en Reembolsos
   │
   ├── Requiere condicional `DB::getDriverName() !== 'sqlite'` (TST-01).
   └── Previene fallos en MySQL sin romper SQLite in-memory en tests.

 [P1.9] WebSocket Real-Time Broadcasting
   │
   ├── Impacta en `SaleCompleted.php`.
   └── Debe preservar el nombre de evento esperado por Flutter: 'App\Events\DashboardUpdated'.
```

---

## 4. Tabla de Características Descubiertas (Features Discovered)

| # | Categoría | Feature / Debt | Descripción | Inputs | Outputs | Comportamiento ante Error | Descubierto Vía |
|---|-----------|----------------|-------------|--------|---------|---------------------------|-----------------|
| 1 | Integridad DB | `P1.1` ENUM Refund | Soporte para `'refund'` en `customer_transactions.type` | `$isRefund = true` en `CustomerController::registerPayment` | Fila creada en `customer_transactions` con `type='refund'` | En MySQL sin migración: Error 1265 Data truncated, HTTP 500 | `backend_tech_debt_report.md:395`, migración `2026_03_24_225300` |
| 2 | Contabilidad | `P1.2` Turno en Cobro | Asignación de `cash_shift_id` y `cashier_id` al liquidar venta pendiente | `PaySaleDTO` + `SaleContextDTO` en `SaleService::payPendingSale` | `Sale` actualizada con turno y cajero activos | Si no se actualiza, la caja no cuadra al cierre (`closeShift`) | `backend_tech_debt_report.md:416`, `SaleService.php:150` |
| 3 | Inteligencia Precios | `P1.3` Persistencia `price_list` | Guardado de `price_list` en cabecera `sales` en lugar de `sale_items` | `SaleContextDTO->priceList` | `sales.price_list` poblado con la lista aplicada | Guardaba `NULL` en `sales` e ignoraba en `sale_items` | `backend_tech_debt_report.md:293`, `SaleService.php:63, 215` |
| 4 | Seguridad / Auth | `P1.4` Bypass PIN Admin | Restricción estricta a usuarios con rol `admin` en `authorizePin` | `pin` numérico de 4 a 10 dígitos | `{"authorized": true, "user": ...}` | Si no es admin: HTTP 403 `authorized: false` | `backend_tech_debt_report.md:485`, `AuthController.php:133` (Ya resuelto) |
| 5 | Seguridad / Auth | `P1.5` Desinfección Backdoor | Eliminación de `GHOST_MASTER_HASH` y bloque condicional huérfano | `pin` en `/api/auth/verify-pin` | Token de sesión normal | Si se deja el bloque: Error PHP `Undefined constant` rompe login | `backend_tech_debt_report.md:126`, `AuthController.php:34`, `AuthTest.php` |
| 6 | Seguridad / RCE | `P1.6` MIME Filter Facturas | Validación estricta de tipos MIME en subida de comprobantes | Archivo adjunto multipart `file` | `{"message": "Archivo subido...", "file_url": ...}` | Archivo peligroso (.php, .html): HTTP 422 Unprocessable Entity | `backend_tech_debt_report.md:502`, `SupplierInvoiceController.php:145` |
| 7 | Seguridad / DoS | `P1.7` Rutas Protegidas | Encapsular `/sales`, `/sales/pending` y `customers` tras `session.validate` | Header `X-Session-Token` válido | Datos de clientes o ventas | Petición anónima: HTTP 401 Unauthorized | `backend_tech_debt_report.md:515`, `routes/api.php:72-74` |
| 8 | Seguridad / Leak | `P1.7b` Quitar `install-path` | Eliminar endpoint público de divulgación de rutas del servidor | GET `/api/system/install-path` | N/A (Ruta eliminada) | HTTP 404 Not Found | `backend_tech_debt_report.md:528`, `routes/api.php:49` |
| 9 | Seguridad / OTA | `P1.8` Rescate Fail-Secure | Bloquear `/system/rescue-migrate` si el secreto no está fijado o es erróneo | Header `X-Rescue-Token` | `{"success": true, "output": ...}` | Sin secreto o token no coincidente: HTTP 403 Unauthorized | `backend_tech_debt_report.md:539`, `SystemController.php:23` |
| 10 | Tiempo Real / UI | `P1.9` Broadcasting Reactivo | Transmisión por WebSockets en canal `'dashboard'` con evento esperado | Evento `SaleCompleted` disparado en `SaleService` | Emisión Pusher en canal público `'dashboard'` | Sin broadcast, terminales Flutter quedan desfasadas | `backend_tech_debt_report.md:305`, `SaleCompleted.php:13`, `pos-frontend` |
| 11 | Arquitectura CLI | `P1.10` Unificación Comandos | Resolver colisión en `license:sync` para que corra `SyncLicenseCommand` | Comando Artisan `php artisan license:sync` | Ejecución de `LicenseSyncService->syncHeartbeat()` | Antes: corría `SyncLicenseStatus` ignorando servicio | `backend_tech_debt_report.md:325`, `SyncLicenseCommand.php` |

---

## 5. Casos de Borde y Comportamientos Observados (Edge Cases)

| # | Feature / Ítem | Input / Escenario | Comportamiento Observado / Esperado |
|---|----------------|-------------------|--------------------------------------|
| 1 | `P1.1` Migración ENUM | Entorno de base de datos SQLite en ejecución de tests automatizados | SQLite no soporta sintaxis `ALTER TABLE MODIFY COLUMN`. La migración debe condicionar la ejecución SQL cruda con `if (DB::getDriverName() !== 'sqlite')` para no abortar las migraciones de prueba. |
| 2 | `P1.2` Cobro diferido | Venta pendiente creada sin turno (ej. venta web o presupuestada) y cobrada en mostrador | `$context->cashShiftId` asigna la venta al turno físico actual de cobranza; si la venta ya tenía turno y se cobra en el mismo turno, se preserva el ID sin colisiones. |
| 3 | `P1.3` Persistencia `price_list` | Venta realizada sin lista de precios especificada en el request POS | `$context->priceList` es `null`; `sales.price_list` queda `NULL`, activando el fallback `COALESCE(price_list, 'base')` estándar sin errores. |
| 4 | `P1.5` Auth Backdoor | Petición con PIN de rescate viejo (`RESCUE_999`) | Con el bloque eliminado, el PIN se evalúa contra la base de datos. Si el usuario de soporte oculto existe en la BD (creado por la migración), valida su hash normalmente; si no, retorna HTTP 401. En ningún caso debe lanzar `Undefined constant`. |
| 5 | `P1.6` Subida de archivos | Archivo con doble extensión (ej. `payload.php.png` o `script.phtml`) | La regla `mimes:pdf,jpeg,png,jpg` inspecciona los magic bytes del contenido y la extensión MIME del cliente; la extensión `.php` o contenido ejecutable es rechazada con HTTP 422. |
| 6 | `P1.7` Protección de rutas | Consulta de cliente Flutter en arranque inicial (antes de autenticar) | Las rutas públicas requeridas previas al login (`/shifts/current`, `/registers`, `/settings/license`) permanecen abiertas; solo las rutas de datos sensibles (`/sales`, `/customers`) exigen `session.validate`. |
| 7 | `P1.8` Endpoint de rescate | Servidor sin variable `RESCUE_MIGRATE_SECRET` en archivo `.env` | Con el arreglo Fail-Secure, responde HTTP 403 en lugar de ejecutar `migrate --force` sin autenticación. |
| 8 | `P1.9` Broadcasting | Terminal Flutter conectada vía Pusher en tiempo real | Debe recibir el evento con nombre `App\Events\DashboardUpdated` en canal `'dashboard'` para disparar `_fetchMetrics()` y refrescar balances. |
| 9 | `P1.10` Sincronización CLI | Ejecución programada vía cron scheduler cada 3 minutos | Se ejecuta `SyncLicenseCommand`, que maneja internamente reintentos, logs de errores y actualización de llaves en `business_settings`. |

---

## 6. Verificación Inicial de la Suite de Pruebas Existente

Se ejecutó la suite de pruebas del proyecto (`php artisan test`):
- **Estado Actual:** 76 pruebas pasadas, 4 pruebas falladas.
- **Causa Raíz de las Fallas:**
  Las 4 fallas corresponden exclusivamente a `tests/Feature/AuthTest.php` debido al error fatal por constante no definida `self::GHOST_MASTER_HASH` en `AuthController.php`.
- **Condición de Éxito para Implementación:**
  Una vez subsanado `P1.5` y actualizadas las aserciones de `AuthTest.php`, la suite debe pasar al 100% (80 pruebas pasando).
