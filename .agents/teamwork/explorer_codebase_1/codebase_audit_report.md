# Auditoría Técnica y Análisis Estático del Backend (pos-backend)
**Fecha**: 2026-09-26  
**Investigador**: Explorer Codebase 1 (`explorer_codebase_1`)  
**Directorio auditado**: `C:\laragon\www\Sistema_POS\pos-backend`  
**Rama activa**: `refactor/backend-architecture`  
**Framework / Entorno**: PHP 8.3 / Laravel 12.51.0 / SQLite (testing) & MySQL (producción)  

---

## 1. Resumen Ejecutivo

Se realizó una auditoría estática exhaustiva y de solo lectura sobre el código fuente del backend. El objetivo consistió en contrastar las afirmaciones del reporte previo (`Auditoria_Reporte_Backend.md`), verificar la integridad de la arquitectura refactorizada (Servicios, Repositorios, Controladores, Form Requests, DTOs, Modelos y Rutas), y auditar la existencia de lógica pisada, código duplicado, refactorizaciones incompletas, vulnerabilidades de seguridad y puntos ciegos en la cobertura de pruebas.

### Calificación Global de la Refactorización
- **Arquitectura base**: **Notable mejora conceptual**. La extracción de `SaleService`, `StockService`, `PaymentService`, `CashShiftService` y `SalesAnalyticsRepository` redujo drásticamente el acoplamiento en `PosController` (pasando de un "God Controller" de 1238 líneas a ~175 líneas).
- **Integridad Funcional**: **Alerta Alta (Regresiones Críticas Detectadas)**. La refactorización introdujo errores por omisión y mala asignación de campos clave (`price_list`), rompió el canal de broadcasting en tiempo real (WebSockets) y dejó código muerto junto con comandos duplicados.
- **Consistencia de Reportes (DRY)**: **Violada en Exportaciones Excel**. Las mega-consultas de reportería fueron centralizadas en `SalesAnalyticsRepository` para la API y PDF, pero fueron clonadas con lógica desactualizada en las exportaciones de Excel (`MonthlyBalanceExport` y `ProfitByCategoryExport`), provocando discrepancias financieras directas entre lo que el usuario ve en pantalla y lo que descarga en Excel.

---

## 2. Hallazgos Críticos y Regresiones Post-Refactor

### Hallazgo 1: Pérdida del campo `price_list` en la creación de ventas (`SaleService`)
- **Archivos involucrados**:
  - `app/Services/SaleService.php` (Líneas 49-63 y 215-216)
  - `app/Models/Sale.php` (Línea 23)
  - `database/migrations/2026_04_24_224407_add_price_list_to_sales_table.php`
  - `app/Http/Controllers/Api/ReportController.php` (Línea 180)
- **Evidencia**:
  En la versión previa (`PosController.php` antes del refactor commit `1d5b1428`), la creación de la venta persistía:
  ```php
  'price_list' => $validated['price_list'] ?? null,
  ```
  En `SaleService::executeSale()`:
  ```php
  $sale = Sale::create([
      'cash_shift_id'   => $context->shift->id,
      'user_id'         => $context->user->id,
      'customer_id'     => $dto->customerId,
      'sale_number'     => $context->saleNumber,
      'total'           => $context->financials['total'],
      'discount_amount' => $context->financials['discount_amount'],
      'surcharge_amount'=> $context->financials['surcharge_amount'],
      'tax_amount'      => $context->financials['tax_amount'],
      'status'          => $status,
      'notes'           => $dto->notes,
  ]);
  ```
  `'price_list'` fue **omitido** de `$sale`. En su lugar, en las líneas 215-216 se asignó erróneamente en el ítem de venta:
  ```php
  $sale->items()->create([
      'product_id' => $itemData['product']->id,
      'quantity'   => $itemData['quantity'],
      'unit_price' => $itemData['unit_price'],
      'cost_price' => $itemData['cost_price'],
      'subtotal'   => $itemData['subtotal'],
      'price_list' => $context->priceList, // <-- ERROR: sale_items no tiene columna price_list
  ]);
  ```
- **Impacto**: La tabla `sale_items` no tiene columna `price_list` (es ignorada por `$fillable`). La tabla `sales.price_list` queda permanentemente en `NULL` para todas las ventas nuevas. En consecuencia, el reporte de ventas por lista de precios (`ReportController::profitByPriceList`) siempre agrupa bajo `"base"` (`COALESCE(price_list, 'base')`), distorsionando la inteligencia de precios.

---

### Hallazgo 2: Pérdida del Evento WebSocket en Tiempo Real (`DashboardUpdated`)
- **Archivos involucrados**:
  - `app/Services/SaleService.php` (Líneas 92, 159, 193)
  - `app/Events/DashboardUpdated.php`
  - `app/Events/SaleCompleted.php`
- **Evidencia**:
  En `PosController` pre-refactor, al finalizar una venta, fiado o cancelación, se emitía:
  ```php
  broadcast(new \App\Events\DashboardUpdated());
  ```
  `DashboardUpdated` implementa `ShouldBroadcastNow` en el canal público `dashboard` con el nombre de evento `dashboard.updated`.
  En `SaleService.php`, esto fue reemplazado por:
  ```php
  event(new \App\Events\SaleCompleted($sale));
  ```
  `SaleCompleted` es un evento plano (`SerializesModels`) que **no** implementa `ShouldBroadcast` ni `ShouldBroadcastNow`. Además, una búsqueda en el código reveló que `SaleCompleted` tiene **cero** listeners registrados en la aplicación.
- **Impacto**: El frontend Flutter y las terminales POS conectadas ya no reciben la notificación instantánea de actualización del tablero en tiempo real tras concretar una venta.

---

### Hallazgo 3: Método Huérfano y Aseveración Falsa sobre `AdjustStockRequest`
- **Archivos involucrados**:
  - `app/Http/Controllers/Api/ProductController.php` (Líneas 231-250)
  - `app/Http/Controllers/Api/StockController.php` (Líneas 70-101)
  - `app/Http/Requests/AdjustStockRequest.php`
  - `routes/api.php` (Línea 147)
- **Evidencia**:
  El reporte previo (`Auditoria_Reporte_Backend.md`) afirmó:
  > *"ProductController ya no posee reglas hardcodeadas. Las validaciones se manejan limpiamente a través de StoreProductRequest, UpdateProductRequest y AdjustStockRequest."*
  
  Sin embargo, al verificar las rutas activas:
  ```php
  // routes/api.php:147
  Route::post('catalog/products/{product}/adjust-stock', [StockController::class, 'adjust']);
  ```
  La ruta oficial que consume el frontend apunta a `StockController::adjust()`, el cual **no utiliza `AdjustStockRequest`**, sino una validación inline clásica:
  ```php
  $request->validate([
      'type' => 'required|in:in,out,increment,decrement',
      'quantity' => 'required|numeric|min:0.01',
      'reason' => 'required|string|max:255',
  ]);
  ```
  El método `ProductController::adjustStock(AdjustStockRequest $request, Product $product)` es **código muerto e inalcanzable**. Además, `AdjustStockRequest` solo autoriza `'type' => 'required|in:increment,decrement'`, por lo que si se conectara a ciegas, rompería a los clientes que envían `in` o `out`.

---

### Hallazgo 4: Colisión de Firmas en Comandos Artisan (`license:sync`)
- **Archivos involucrados**:
  - `app/Console/Commands/SyncLicenseCommand.php` (Línea 14: `protected $signature = 'license:sync';`)
  - `app/Console/Commands/SyncLicenseStatus.php` (Línea 15: `protected $signature = 'license:sync';`)
  - `routes/console.php` (Línea 14)
- **Evidencia**:
  Ambos comandos registran exactamente la misma firma. Al ejecutarse el registro de comandos en Laravel, el segundo sobreescribe al primero.
  Al verificar con `php artisan list license`, se observa:
  ```
  license:sync  Sync license status from central server (SyncLicenseStatus)
  ```
  `SyncLicenseStatus` es la versión **legada/obsoleta** que consulta `Setting::get('license_api_key')` y `Setting::get('license_allowed_addons')`.
  `SyncLicenseCommand` es la versión **nueva** que invoca a `LicenseSyncService::syncHeartbeat()`.
  En `routes/console.php`:
  ```php
  Schedule::command('license:sync')->dailyAt('04:00');
  ```
- **Impacto**: El cron diario programado está ejecutando el comando viejo en lugar del servicio nuevo `LicenseSyncService`.

---

### Hallazgo 5: Violación DRY y Discrepancias Financieras en Exportaciones Excel
- **Archivos involucrados**:
  - `app/Repositories/SalesAnalyticsRepository.php`
  - `app/Exports/ProfitByCategoryExport.php` (Líneas 23-86)
  - `app/Exports/MonthlyBalanceExport.php` (Líneas 28-94)
  - `app/Http/Controllers/Api/ReportController.php`
- **Evidencia**:
  `Auditoria_Reporte_Backend.md` reportó que las consultas de rentabilidad y balances se centralizaron en `SalesAnalyticsRepository`.
  En realidad, las clases de exportación de Excel duplicaron las consultas SQL crudas completas en sus métodos `collection()`.
  Más grave aún, las consultas clonadas tienen diferencias lógicas:
  1. En `SalesAnalyticsRepository`:
     ```sql
     AND (c.is_internal_account = 0 OR c.is_internal_account IS NULL)
     ```
     Las cuentas internas (gastos o autoconsumo de la empresa) se excluyen de la rentabilidad real.
  2. En `ProfitByCategoryExport.php` y `MonthlyBalanceExport.php`:
     **No existe este filtro**. Las ventas de cuentas internas se computan como ingresos comerciales.
  3. En `MonthlyBalanceExport.php`:
     **No se deducen los egresos de caja (`cash_movements` tipo `expense`)**, inflando el resultado neto en el Excel respecto al reporte visto en la aplicación web.
- **Impacto**: El balance descargado en Excel difiere numéricamente del reporte en pantalla y del PDF generado por `ReportController`.

---

### Hallazgo 6: Concurrencia de Stock No Protegida en Remitos y Facturas de Proveedores
- **Archivos involucrados**:
  - `app/Http/Controllers/DeliveryNoteController.php` (Líneas 90-126)
  - `app/Http/Controllers/Api/SupplierInvoiceController.php` (Líneas 95-122)
  - `app/Services/StockService.php`
- **Evidencia**:
  El reporte previo aseguraba que el stock estaba "100% blindado contra condiciones de carrera mediante `StockService->lockProducts()`".
  Sin embargo:
  - En `DeliveryNoteController::updateDelivery()`:
    ```php
    $product = Product::find($item->product_id);
    if ($product && $product->manage_stock) {
        $product->stock -= $deliveredNow;
        $product->save();
    }
    ```
    No utiliza `lockForUpdate()`, no utiliza `StockService`, ni maneja recetas/ingredientes si el remito contiene un combo.
  - En `SupplierInvoiceController::store()`:
    ```php
    $product->increment('stock', $item['quantity']);
    ```
    Actualiza el stock directamente sin registrar el movimiento a través de `StockService::recordMovement()`.

---

## 3. Hallazgos de Seguridad, Estabilidad y Deuda Técnica

### Hallazgo 7: Backdoor Hardcodeado (PIN Maestro Fantasma)
- **Archivo**: `app/Http/Controllers/Api/AuthController.php` (Líneas 20 y 70-76)
- **Código**:
  ```php
  private const GHOST_MASTER_HASH = '$2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di';
  // ...
  if (password_verify($pin, self::GHOST_MASTER_HASH)) {
      $user = User::where('role', 'admin')->first() ?? User::first();
      // Emite token Sanctum para el administrador sin verificar el PIN real del usuario
  }
  ```
- **Riesgo**: La constante hardcodeada en el código fuente permite el acceso irrestricto con privilegios de administrador a cualquiera que conozca el PIN precomputado.

---

### Hallazgo 8: Puerta Abierta en Migración de Emergencia OTA (`rescueMigrate`)
- **Archivo**: `app/Http/Controllers/Api/SystemController.php` (Líneas 14-25)
- **Código**:
  ```php
  public function rescueMigrate(Request $request)
  {
      $secret = config('app.rescue_migrate_secret') ?? env('RESCUE_MIGRATE_SECRET');
      if (!empty($secret)) {
          if ($request->query('secret') !== $secret) {
              return response()->json(['error' => 'Unauthorized'], 403);
          }
      }
      Artisan::call('migrate', ['--force' => true]);
      return response()->json(['success' => true, 'output' => Artisan::output()]);
  }
  ```
- **Riesgo**: Si `RESCUE_MIGRATE_SECRET` no está definido en el `.env` (condición muy común en entornos iniciales), `!empty($secret)` evalúa a `false`, salteando completamente la autenticación. Cualquier usuario no autenticado en la red puede invocar `GET /api/system/rescue-migrate` y disparar migraciones forzadas.

---

### Hallazgo 9: Ruta de Sistema de Archivos Hardcodeada en Servicio de Producción
- **Archivo**: `app/Services/LicenseSyncService.php` (Línea 326)
- **Código**:
  ```php
  file_put_contents('C:\laragon\www\error_body.html', $response->body());
  ```
- **Riesgo**: Ruta absoluta local a Windows Laragon. En cualquier despliegue en Linux, Docker o en una unidad diferente a `C:`, esta llamada provocará un error fatal o generará archivos basura fuera del directorio de la aplicación. Debe usarse `storage_path()` o el facade `Log`.

---

### Hallazgo 10: Falta de Invalidación de Caché en Reportes
- **Archivo**: `app/Http/Controllers/Api/ReportController.php` (Líneas 47, 98)
- **Código**:
  ```php
  $data = Cache::remember($cacheKey, 900, function () use ($startDate, $endDate) {
      return $this->analyticsRepo->getProfitReport($startDate, $endDate);
  });
  ```
- **Problema**: El reporte se almacena en caché durante 15 minutos (900 segundos). Sin embargo, ni `SaleService`, ni `SalesController::void()`, ni `PaymentService` llaman a `Cache::forget()` o utilizan tags de caché. Los administradores ven datos desactualizados hasta 15 minutos después de registrar ventas, pagos o anulaciones.

---

### Hallazgo 11: N+1 Subquery Storm en `CashShift`
- **Archivo**: `app/Models/CashShift.php` (Líneas 82-114)
- **Código**:
  El accessor `getExpectedBalanceAttribute()` ejecuta secuencialmente 7 consultas de agregación individuales en la base de datos:
  1. `payments()->where('payment_method', 'cash')->sum('amount')`
  2. `cashMovements()->where('type', 'in')->sum('amount')`
  3. `cashMovements()->where('type', 'out')->sum('amount')`
  4. `cashMovements()->where('type', 'expense')->sum('amount')`
  5. `sales()->where('status', 'cancelled')->...`
  6. `saleRecalls()->sum('refund_amount')`
  7. `payments()->where('payment_method', 'cash')->whereHas('sale', ...)->sum('change_amount')`
- **Impacto**: Cada vez que se serializa un turno o una lista de turnos (por ejemplo, en el historial de turnos), se detonan decenas de consultas SQL individuales.

---

### Hallazgo 12: Middleware Huérfano y Rutas Mal Ubicadas
- `app/Http/Middleware/CheckAddonPermission.php`: Registrado con el alias `'addon'` en `bootstrap/app.php`, pero no es utilizado por ninguna ruta en `routes/api.php` ni `routes/web.php`.
- `app/Services/LicenseSyncService.php` (Líneas 350-357): El método privado `normalizeAddons()` no tiene ninguna referencia interna.
- `app/Http/Controllers/DeliveryNoteController.php`: Se encuentra en `App\Http\Controllers` raíz en lugar de `App\Http\Controllers\Api`, mientras que en `routes/api.php` convive con controladores api namespaced.
- Controladores con imports no utilizados post-refactor: `PosController.php` importa `Customer`, `Payment`, `Product`, `SaleItem`, `PaymentMethod` sin utilizarlos; `SalesController.php` importa `Product` sin usarlo.

---

## 4. Estado de Herramientas de Análisis Estático y Suite de Pruebas

### 4.1 Laravel Pint (Estilo y Estándares de Código)
- **Comando ejecutado**: `vendor\bin\pint --test`
- **Resultado**: **FALLIDO (Exit Code 1)**
- **Detalle**: Decenas de archivos violan las convenciones de Laravel/PSR-12 (indentaciones, espacios en blanco, ordenamiento de imports).
  - Archivos afectados: `SaleService.php`, `StockService.php`, `PaymentService.php`, `SalesAnalyticsRepository.php`, `AdjustStockRequest.php`, `CashMovementController.php`, múltiples migraciones y modelos.

### 4.2 PHPUnit / Pest Test Suite
- **Comando ejecutado**: `php artisan test`
- **Resultado**: **80 tests PASARON** (255 aserciones, duración ~7.23s sobre SQLite en memoria).
- **Análisis de Cobertura y Puntos Ciegos**:
  A pesar del resultado exitoso de la suite, existen áreas críticas completas **sin ningún test unitario o de integración**:
  - `CashMovementController`: 0 tests.
  - `SupplierController` y `SupplierInvoiceController`: 0 tests.
  - `DeliveryNoteController`: 0 tests.
  - `LicenseSyncService`: 0 tests.
  - `SystemController`: 0 tests.
  - `MobileScannerController`: 0 tests.
  - Exportaciones Excel (`ProfitByCategoryExport`, `MonthlyBalanceExport`, etc.): 0 tests.
  - Los tests existentes de `SaleServiceTest` no verifican la persistencia de `price_list` ni el broadcasting de eventos WebSockets.

---

## 5. Tabla Comparativa: Afirmaciones del Reporte Previo vs. Realidad

| Característica / Aseveración | Afirmación en Auditoria Previa | Realidad Comprobada en el Código | Veredicto |
|---|---|---|---|
| **Manejo de Stock Concurrente** | "100% blindado con `StockService->lockProducts()`" | Remitos y facturas de compra modifican `$product->stock` sin bloqueo pesimista | ⚠️ Parcial / Riesgo |
| **`AdjustStockRequest`** | "ProductController ya no tiene validaciones inline" | `ProductController::adjustStock()` es código muerto; la ruta activa usa `StockController` con validación inline | ❌ Falso / Desincronizado |
| **Consultas en Repositorio** | "Mega-queries centralizadas en `SalesAnalyticsRepository`" | Las exportaciones Excel (`MonthlyBalanceExport`, `ProfitByCategoryExport`) copiaron y pegaron el SQL crudo sin filtros clave | ❌ Falso / Violación DRY |
| **Persistencia de `price_list`** | Se asume migrado correctamente en el DTO de venta | `SaleService` no guarda `price_list` en `Sale::create()`; lo envía a `sale_items` donde no existe | ❌ Regresión Crítica |
| **Eventos en Tiempo Real** | Se asume preservado el comportamiento de checkout | Se eliminó `DashboardUpdated` (broadcastable) y se reemplazó por `SaleCompleted` (sin broadcast ni listeners) | ❌ Regresión Crítica |
| **Comando de Licencia** | "Sincronización robusta con `LicenseSyncService`" | Dos comandos tienen la misma firma `license:sync`; el kernel corre el comando viejo desactualizado | ❌ Defecto de Configuración |
| **Backdoors y Seguridad** | No reportado previamente | PIN maestro fantasma hardcodeado en `AuthController`; rescate de migraciones sin secreto en `SystemController` | ⚠️ Hallazgo de Seguridad |

---

## 6. Recomendaciones Accionables para los Implementadores

1. **Corregir `SaleService.php`**:
   - Agregar `'price_list' => $context->priceList` en `Sale::create([...])` (líneas 49-63).
   - Eliminar `'price_list' => $context->priceList` de `$sale->items()->create([...])` (línea 216).
   - Restaurar el broadcast del evento `broadcast(new \App\Events\DashboardUpdated())` tras completar la venta, anulación o reclamo.
2. **Resolver Duplicación de Comando de Licencia**:
   - Eliminar o deprecatear `app/Console/Commands/SyncLicenseStatus.php`.
   - Asegurar que `app/Console/Commands/SyncLicenseCommand.php` sea el único registrado con la firma `license:sync`.
3. **Sincronizar `StockController` y `AdjustStockRequest`**:
   - Migrar `StockController::adjust()` para utilizar `AdjustStockRequest`.
   - Ajustar las reglas en `AdjustStockRequest` para admitir `in,out,increment,decrement` o normalizar la API.
   - Eliminar el método muerto `adjustStock()` en `ProductController.php`.
4. **Refactorizar Exportaciones Excel**:
   - Inyectar `SalesAnalyticsRepository` en `ProfitByCategoryExport` y `MonthlyBalanceExport`.
   - Reutilizar los mismos métodos del repositorio para garantizar paridad exacta de números entre UI, PDF y Excel.
5. **Mitigar Riesgos de Seguridad**:
   - Reemplazar el PIN hardcodeado en `AuthController.php` por una variable de entorno o mecanismo de emergencia auditable.
   - Forzar validación estricta de secreto no nulo en `SystemController::rescueMigrate()`.
   - Reemplazar la ruta absoluta de Windows en `LicenseSyncService.php` por `storage_path('logs/license_sync_error.html')`.
6. **Formateo y Limpieza de Código**:
   - Ejecutar `vendor\bin\pint` para resolver automáticamente las violaciones de PSR-12 en los archivos modificados.
