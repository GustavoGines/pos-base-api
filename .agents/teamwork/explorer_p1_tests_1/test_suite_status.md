# Informe Exhaustivo del Estado de la Suite de Pruebas Automatizadas
## Sistema POS Backend — Laravel 12 / PHP 8.3
**Investigador:** Explorer 2 (Test Suite Investigator)  
**Fecha de Inspección:** 26 de Septiembre de 2026  
**Directorio de Trabajo:** `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p1_tests_1`  
**Directorio del Proyecto:** `C:\laragon\www\Sistema_POS\pos-backend`  

---

## 1. Resumen Ejecutivo del Estado Actual

La suite de pruebas automatizadas del backend se ejecuta mediante **PHPUnit 11.5.55** integrado en **Laravel 12.54.1** sobre **PHP 8.3.30 (cli)** en entorno Windows/Laragon.

### Métricas Globales de Ejecución Baseline

| Métrica | Valor Observado | Notas |
|---|---|---|
| **Total de Pruebas** | **80 tests** | Distribuidos en 15 clases Feature (0 Unit tests). |
| **Pruebas Pasando** | **76 tests (95%)** | 74 en 14 archivos Feature + 2 en `AuthTest`. |
| **Pruebas Fallando / Errores** | **4 tests (5%)** | Todos concentrados exclusivamente en `tests/Feature/AuthTest.php`. |
| **Aserciones Ejecutadas** | **242 aserciones** | (Alcanzará ~255 cuando los 4 tests de Auth pasen/se corrijan). |
| **Tiempo de Ejecución** | **~2.71s - 3.03s** | Ejecución en memoria ultrarrápida. |
| **Consumo de Memoria** | **~60.00 MB** | Bajo la configuración de SQLite en memoria. |
| **Estabilidad / Flakiness** | **0 tests intermitentes** | 100% determinístico; no se detectaron carreras de tiempo ni flaky tests. |

---

## 2. Diagnóstico Detallado de Fallos Actuales en la Suite

La ejecución de `php artisan test` arroja un código de salida `1` debido a una única causa raíz técnica en `tests/Feature/AuthTest.php`:

### Causa Raíz del Fallo: Constante Inexistente `GHOST_MASTER_HASH`
- **Ubicación del Error en Producción:** `app/Http/Controllers/Api/AuthController.php:34`
- **Error Verbatim de PHP:**
  ```text
  Error: Undefined constant App\Http\Controllers\Api\AuthController::GHOST_MASTER_HASH
  at app\Http\Controllers\Api\AuthController.php:34
  ```
- **Mecanismo del Bug:**
  En `AuthController.php`, el método `verifyPin(Request $request)` comienza evaluando:
  ```php
  // app/Http/Controllers/Api/AuthController.php:34
  if (Hash::check($pin, self::GHOST_MASTER_HASH)) { ... }
  ```
  La constante `GHOST_MASTER_HASH` fue eliminada de la clase en refactorizaciones previas, pero el bloque condicional del protocolo de rescate quedó huérfano. Dado que esta evaluación ocurre **antes** de cualquier consulta a la base de datos de usuarios, toda petición a `POST /api/auth/verify-pin` revienta fatalmente con un error de compilación/runtime PHP.

### Lista de Pruebas Afectadas en `AuthTest.php`

| Test en `tests/Feature/AuthTest.php` | Estado Actual | Causa Inmediata |
|---|---|---|
| `test_A01_login_con_pin_valido_genera_session_token` | 🔴 **FAILURE** | Falla en línea 37 (`assertStatus(200)` recibió 500 por `Undefined constant`). |
| `test_A02_login_con_pin_invalido_retorna_401` | 🔴 **FAILURE** | Falla en línea 59 (`assertStatus(401)` recibió 500 por `Undefined constant`). |
| `test_A03_single_active_session_nuevo_login_invalida_token_anterior` | 🔴 **FAILURE** | Falla en línea 73 al llamar a `verify-pin` para generar Token A. |
| `test_A04_usar_token_invalido_retorna_401_session_expired` | 🟢 **PASS** | Invoca `/api/auth/me` con header, no pasa por `verifyPin`. |
| `test_A04b_usar_ruta_protegida_con_token_invalido_retorna_401` | 🟢 **PASS** | Invoca `/api/shifts/open` con token falso, rechaza en middleware. |
| `test_A05_protocolo_rescate_genera_token_y_flag` | 🔴 **ERROR** | Falla en línea 141; **este test además prueba activamente el backdoor que la Fase P1 manda a erradicar**. |

---

## 3. Entorno de Pruebas y Configuración de Ejecución

### Comandos Exactos de Ejecución

```bash
# Ejecutar la suite completa con el runner de Laravel
php artisan test

# Ejecutar mediante el binario PHPUnit nativo (modo rápido con testdox)
php vendor/phpunit/phpunit/phpunit --testdox

# Ejecutar una suite o archivo específico
php vendor/phpunit/phpunit/phpunit tests/Feature/CustomerPaymentTest.php
php vendor/phpunit/phpunit/phpunit tests/Feature/AuthTest.php

# Filtrar un caso de prueba individual
php artisan test --filter=test_A01
```

### Prerrequisitos de Entorno

La configuración se define en `phpunit.xml`:
- **Driver de Base de Datos:** `sqlite`
- **Base de Datos:** `:memory:` (`<env name="DB_DATABASE" value=":memory:"/>`)
- **Variables de Entorno:**
  - `APP_ENV=testing`
  - `BCRYPT_ROUNDS=4` (permite que los hash de PIN y contraseñas tomen < 1ms por ejecución)
  - `CACHE_STORE=array`
  - `QUEUE_CONNECTION=sync`
  - `SESSION_DRIVER=array`
  - `BROADCAST_CONNECTION=null`
  - `TELESCOPE_ENABLED=false`, `PULSE_ENABLED=false`

### ⚠️ Caveat Crítico de Entorno: SQLite vs MySQL
SQLite `:memory:` **no valida ni restringe tipos `ENUM` de SQL**. Si se inserta una cadena arbitraria como `'refund'` en una columna creada como `enum('charge', 'payment')`, SQLite la almacena sin error como texto plano.  
Por lo tanto:
1. Una prueba que corra en SQLite pasará de largo la incompatibilidad de `customer_transactions.type = 'refund'`.
2. Para verificar que la migración de P1.1 funciona y previene el crash en producción (MySQL 8.0), se debe comprobar la estructura del Schema en los tests o correr la migración en MySQL.

---

## 4. Mapeo de Pruebas Existentes vs Alcance Fase P1

A continuación se detalla la correspondencia entre los 15 archivos de pruebas existentes y las áreas afectadas por la Fase P1:

| Archivo de Prueba | Tests | Cobertura Relacionada con Fase P1 |
|---|:---:|---|
| `tests/Feature/AuthTest.php` | 6 | Autenticación PIN y Single Active Session. **Directamente afectado por P1.5 (Backdoor Maestro)**. |
| `tests/Feature/CustomerPaymentTest.php` | 10 | Abonos, cuenta corriente, tickets con deuda. **Afectado por P1.1 (Reembolsos)**, pero actualmente NO tiene pruebas de reembolsos (`is_refund`). |
| `tests/Feature/PosProcessSaleTest.php` | 8 | Ventas POS, combos, stock, cuenta corriente. **Afectado por P1.3 (`price_list`)**, pero no comprueba persistencia de lista de precios. |
| `tests/Feature/RefactorIntegrationTest.php` | 4 | Cobro de venta pendiente (`order_recall`), anulación de ventas. **Afectado por P1.2 (Turno/Cajero en cobro pendiente)**, pero no valida actualización de `cash_shift_id` ni `cashier_id`. |
| `tests/Feature/SaleVoidTest.php` | 5 | Anulación de ventas y restitución de stock/balance. Compatible, sin conflictos. |
| `tests/Feature/CashShiftTest.php` | 6 | Apertura/cierre de turnos y balances de caja. |
| `tests/Feature/CatalogBulkTest.php` | 4 | Edición masiva y reversión de precios. |
| `tests/Feature/CatalogStockTest.php` | 5 | Ajustes de inventario y stock mínimo. |
| `tests/Feature/DeliveryNoteTest.php` | 2 | Creación de remitos y actualización de stock. |
| `tests/Feature/FeatureGateTest.php` | 5 | Restricción por licencias y roles admin/cajero. |
| `tests/Feature/QuoteTest.php` | 9 | Presupuestos y conversión a venta. |
| `tests/Feature/ReportTest.php` | 5 | Reportes contables y balances mensuales. |
| `tests/Feature/ThirdPartyCheckTest.php` | 2 | Gestión de cartera de cheques de terceros. |
| `tests/Feature/TrashTest.php` | 4 | Papelera de reciclaje y restauración. |
| `tests/Feature/AbmTest.php` | 5 | CRUDs de marcas, categorías, cajas, usuarios y métodos de pago. |

---

## 5. Matriz de Brechas de Pruebas (Test Gaps) para la Fase P1

Para cada uno de los 10 ítems de la Fase P1 (`P1.1` a `P1.10`), se evaluó la existencia de pruebas automatizadas y se identificaron las brechas exactas:

### P1.1 - Fix MySQL ENUM en Reembolsos (`customer_transactions.type`)
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No existe ninguna prueba que llame a `POST /api/customers/{id}/payments` con `'is_refund' => true`.
- **Riesgo:** El código en `CustomerController.php:271` intenta guardar `'type' => 'refund'`. En SQLite no explota porque no valida el enum, pero en MySQL produce crash 1265.
- **Prueba Faltante Requerida:** 
  - `test_refund_con_saldo_a_favor_registra_transaccion_tipo_refund`: Enviar `'is_refund' => true` con cliente con saldo negativo y verificar que se crea la transacción con `type = 'refund'`.
  - Validación de rechazo: cliente con saldo >= 0 no puede recibir reintegro.
  - Verificación de esquema: comprobar que la migración agrega `'refund'` al enum.

### P1.2 - Fix Turno en Cobro de Venta Pendiente (`SaleService::payPendingSale`)
- **Estado Actual en Tests:** ❌ **BRECHA DE ASERCIÓN**. `RefactorIntegrationTest.php:141` (`test_order_recall_reconciles_stock_and_accepts_multi_checks`) ejecuta `PUT /api/sales/{id}/pay`, pero lo hace en el mismo turno y con el mismo usuario de creación, y no comprueba `cash_shift_id` ni `cashier_id`.
- **Riesgo:** Si un cajero cobra una venta pendiente originada en otro turno, el arqueo de caja queda atribuido al turno viejo.
- **Prueba Faltante Requerida:**
  - Test donde la venta pendiente se crea en `Turno A` por `Usuario A`, y se cobra vía `payPendingSale` en `Turno B` por `Usuario B`. Aserción estricta: `$sale->fresh()->cash_shift_id === Shift B` y `$sale->fresh()->cashier_id === User B`.

### P1.3 - Fix Persistencia de `price_list` en `Sale::create()`
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. Ninguna prueba en `PosProcessSaleTest` envía ni verifica el atributo `price_list`.
- **Riesgo:** `SaleService::executeSale()` no persiste `'price_list' => $context->priceList` en la tabla `sales` y erróneamente intentaba guardarlo en `sale_items` donde no existe la columna.
- **Prueba Faltante Requerida:**
  - Test que ejecute una venta especificando `price_list => 'mayorista_especial'` y verifique que `$sale->fresh()->price_list === 'mayorista_especial'`.

### P1.4 - Cerrar Bypass de Supervisor en `AuthController::authorizePin`
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No existe ninguna prueba para `POST /api/auth/authorize-pin`.
- **Nota del Usuario:** *DEBT-04 ya fue resuelto en código; omitir implementación.*
- **Recomendación de Testing:** Aunque esté resuelto en código, se debe agregar un test de regresión para garantizar que un PIN de un usuario con rol `cashier` devuelva 403 o sea rechazado, y solo se autorice el PIN de un usuario con rol `admin`.

### P1.5 - Eliminar Backdoor Maestro (`GHOST_MASTER_HASH`)
- **Estado Actual en Tests:** ⚠️ **TEST EXISTENTE PERO OBSOLETO / ROTO**.
  - `tests/Feature/AuthTest.php:118` contiene `test_A05_protocolo_rescate_genera_token_y_flag()`.
  - Este test mockea el Hash y envía `'pin' => 'RESCUE_999'`, validando que se emita un token y `requires_pin_change => true`.
- **Impacto del Cambio:** Al remover el bloque condicional del backdoor en `AuthController.php:31-52`, `test_A01`, `test_A02` y `test_A03` pasarán a verde automáticamente.
- **Ajuste Obligatorio en `AuthTest.php`:**
  - Conforme al criterio R2 del usuario (*"arreglar el código o el test si quedó obsoleto por el cambio de lógica"*), `test_A05` debe ser modificado para validar que enviar `'pin' => 'RESCUE_999'` retorne **401 Unauthorized** (comprobando que el backdoor fue sellado permanentemente).

### P1.6 - Restringir Subida de Archivos en `SupplierInvoiceController`
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No existe ninguna prueba para `SupplierInvoiceController` ni para subida de adjuntos (`POST /api/supplier-invoices/upload-attachment`).
- **Riesgo:** Permite subir ejecutables `.php`, scripts de shell o HTML malicioso sin restricción MIME.
- **Prueba Faltante Requerida:**
  - Test subiendo un archivo falso `.php` o `.sh`: debe responder `422 Unprocessable Entity`.
  - Test subiendo un archivo `.pdf` o `.jpg` válido: debe responder `200 OK`.
  - Test subiendo un archivo mayor a 10MB: debe responder `422`.

### P1.7 - Proteger Rutas Públicas (`/sales`, `/sales/pending`, `customers`, eliminar `/system/install-path`)
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No existen pruebas que verifiquen que peticiones sin token a `/api/sales`, `/api/sales/pending` o `/api/customers` sean rechazadas con 401.
- **Prueba Faltante Requerida:**
  - Test llamando sin header `X-Session-Token` a `GET /api/sales`: assert 401.
  - Test llamando sin header a `GET /api/sales/pending`: assert 401.
  - Test llamando sin header a `GET /api/customers`: assert 401.
  - Test llamando a `GET /api/system/install-path`: assert 404 (ruta eliminada).

### P1.8 - Blindar Endpoint de Rescate (`SystemController::rescueMigrate`)
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No existe prueba automatizada para `GET /api/system/rescue-migrate`.
- **Riesgo:** Con `RESCUE_MIGRATE_SECRET` vacío, el endpoint actualmente ejecuta `migrate --force` sin requerir token (fail-open).
- **Prueba Faltante Requerida:**
  - Test sin header `X-Rescue-Token`: assert 403.
  - Test con `RESCUE_MIGRATE_SECRET` no configurado en entorno: assert 403.
  - Test con token erróneo: assert 403.
  - Test con token correcto: assert 200.

### P1.9 - Restaurar Broadcasting en Tiempo Real (`SaleCompleted`)
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. Ninguna prueba verifica la interfaz `ShouldBroadcastNow` ni el canal `'dashboard'`.
- **Prueba Faltante Requerida:**
  - Test unitario/feature que verifique:
    1. `SaleCompleted` implementa `Illuminate\Contracts\Broadcasting\ShouldBroadcastNow`.
    2. El método `broadcastOn()` retorna `Channel('dashboard')`.
    3. `Event::fake([SaleCompleted::class])` al ejecutar una venta confirma que el evento es disparado.

### P1.10 - Deshacer Colisión de Comandos (`license:sync`)
- **Estado Actual en Tests:** ❌ **BRECHA TOTAL**. No hay pruebas para comandos de consola de sincronización de licencias.
- **Prueba Faltante Requerida:**
  - Test que verifique que el comando registrado en `Artisan::all()['license:sync']` instancia `App\Console\Commands\SyncLicenseCommand` (o que `SyncLicenseStatus` fue renombrado/eliminado, evitando el secuestro del comando).

---

## 6. Recomendaciones Clave para el Equipo de Implementación

1. **Prioridad 1 — Desbloquear `AuthTest.php`:**
   Remover inmediatamente el bloque condicional del backdoor en `AuthController.php:31-52`. Esto hará que de inmediato 79 de los 80 tests pasen a verde.
2. **Prioridad 2 — Adaptar `test_A05`:**
   Cambiar las aserciones de `test_A05` en `AuthTest.php` para que espere `401 Unauthorized` al enviar `RESCUE_999`. Con esto, la suite existente quedará en **100% verde (80/80 pasando)**.
3. **Prioridad 3 — Crear Suite Específica de Fase P1:**
   Para cumplir con el estándar de calidad y la auditoría adversarial, se sugiere crear un archivo de prueba dedicado:  
   `tests/Feature/PhaseP1SecurityAndIntegrityTest.php`  
   que cubra de forma exhaustiva las brechas identificadas (subida de archivos bloqueada, rutas protegidas 401, token de rescate 403, persistencia de `price_list` y turno de cobro pendiente).
4. **Validación de Migración ENUM en MySQL:**
   Recordar que SQLite en memoria no falla con `type = 'refund'`. La migración para MySQL debe probarse explícitamente en la base de datos real o mediante inspección de esquema en las pruebas.
