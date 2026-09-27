# Handoff Report — Explorer P2 (P2.3, P2.4, P2.5)

**Fecha**: 2026-09-27  
**Subagente**: `explorer_p2_3`  
**Tipo de Handoff**: Hard Handoff (Investigación Completa)  
**Destinatario**: Agente Orquestador (`0e6bb95c-aef7-4a7c-8486-6e6b793d9506`)  
**Archivos generados**:
- `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p2_3\analysis.md`
- `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p2_3\handoff.md`

---

## 1. Observation (Observaciones Directas)

1. **P2.3 — `StockController` vs `AdjustStockRequest` vs `ProductController`**:
   - `app/Http/Controllers/Api/StockController.php:19-27`:
     Valida en línea con `$request->validate()`:
     ```php
     'type'      => 'required|in:in,out,increment,decrement',
     'quantity'  => 'required|numeric|min:0',
     'notes'     => 'nullable|string|max:500',
     'min_stock' => 'nullable|numeric|min:0',
     'user_id'   => 'nullable|exists:users,id',
     ```
   - `app/Http/Requests/AdjustStockRequest.php:25-30`:
     Define reglas discordantes:
     ```php
     'type'      => 'required|in:increment,decrement',
     'quantity'  => 'required|numeric|min:0.001',
     'notes'     => 'nullable|string|max:255',
     'min_stock' => 'nullable|numeric|min:0',
     ```
     Omite `in,out`, exige `min:0.001` (impidiendo ajustes donde `quantity=0` para solo cambiar `min_stock`), limita `notes` a 255 y omite `user_id`.
   - `app/Http/Controllers/Api/ProductController.php:117-147`:
     Contiene el método `adjustStock(\App\Http\Requests\AdjustStockRequest $request, Product $product)`.
   - `routes/api.php:145-146` y `routes/web.php:1-8`:
     La ruta oficial es `POST /catalog/products/{product}/adjust-stock` apuntando a `[StockController::class, 'adjust']`. En `ProductController`, solo se registran `store`, `update`, `destroy` mediante `apiResource(...)->except(['index', 'show'])`. `ProductController::adjustStock` no tiene ruta y nunca es invocado.

2. **P2.4 — Exportaciones Excel vs `SalesAnalyticsRepository`**:
   - `app/Exports/ProfitByCategoryExport.php:31-92`:
     Reescribe una megaconsulta SQL cruda idéntica a la del repositorio, pero **omite** el filtro de cuentas internas (`customers.is_internal_account = true`), provocando inconsistencias financieras respecto a los dashboards web y reportes PDF.
   - `app/Exports/MonthlyBalanceExport.php:29-78`:
     Reescribe la consulta mensual con SQL crudo. En las líneas 43 y 73 utiliza `DATE_FORMAT(sales.created_at, '%Y-%m')`, provocando un crash fatal en SQLite (`QueryException: no such function: DATE_FORMAT`). Además, omite cuentas internas y nunca deduce los egresos de caja (`cash_movements` de tipo `expense`), informando una ganancia neta errónea y desalineada con `ReportController`.
   - `app/Repositories/SalesAnalyticsRepository.php:10-97`:
     Contiene `getProfitReport()` (que sí excluye cuentas internas), pero carece del método para balances mensuales.
   - `app/Http/Controllers/Api/ReportController.php:219-320`:
     Posee `getMonthlyBalanceData()` que ya resuelve compatibilidad multi-motor (`strftime` en SQLite vs `DATE_FORMAT` en MySQL), excluye cuentas internas y descuenta egresos de caja, pero se encuentra implementado de forma privada en el controlador en lugar del repositorio.

3. **P2.5 — Autenticación Nativa en `ValidateSessionToken`**:
   - `app/Http/Middleware/ValidateSessionToken.php:47`:
     Solo ejecuta `$request->attributes->set('authenticated_user', $user);`.
     No invoca `Auth::setUser($user)` ni `$request->setUserResolver(...)`.
   - Consecuencia observada:
     - `auth()->user()`, `auth()->id()`, `Auth::user()` y `$request->user()` retornan `null`.
     - `app/Http/Controllers/Api/CatalogController.php:165`: `BulkPriceHistory` siempre graba `'user_id' => 1` por fallback (`auth()->id() ?? 1`).
     - `app/Observers/ThirdPartyCheckObserver.php:17`: Las auditorías de cheques registran `'user_id' => null`.
     - Se verificó en consola CLI (`tinker`) que `\Illuminate\Support\Facades\Auth::setUser($user)` y `$request->setUserResolver(fn () => $user)` populan instantáneamente `auth()->id()`, `auth()->user()` y `$request->user()`.

---

## 2. Logic Chain (Cadena Lógica de Razonamiento)

1. **P2.3**:
   - *Premisa A*: La ruta viva `POST /catalog/products/{product}/adjust-stock` ejecuta `StockController::adjust()`.
   - *Premisa B*: `ProductController::adjustStock()` no está mapeado a ninguna ruta en el enrutador.
   - *Deducción 1*: `ProductController::adjustStock()` es código muerto y puede ser removido con seguridad sin romper ninguna funcionalidad del sistema.
   - *Premisa C*: Para desacoplar `StockController::adjust()`, se debe inyectar `AdjustStockRequest`.
   - *Deducción 2*: Para evitar regresiones con clientes que envían `in` o `out`, notas largas (hasta 500 chars), `quantity: 0` con `min_stock`, o `user_id`, las reglas de `AdjustStockRequest` deben coincidir exactamente con el contrato previo de `StockController`.

2. **P2.4**:
   - *Premisa A*: Los reportes contables deben reflejar la misma verdad económica en todos los formatos (JSON, PDF, Excel).
   - *Premisa B*: `ProfitByCategoryExport` y `MonthlyBalanceExport` ignoraron `SalesAnalyticsRepository`, omitiendo la deducción de gastos de caja y la exclusión de cuentas internas, e introduciendo funciones SQL dependientes de MySQL (`DATE_FORMAT`).
   - *Deducción 1*: Centralizar `getMonthlyBalance()` en `SalesAnalyticsRepository` permite reutilizar la misma lógica probada y compatible (SQLite/MySQL) en `ReportController` y `MonthlyBalanceExport`.
   - *Deducción 2*: Hacer que ambos exportadores consuman `SalesAnalyticsRepository` elimina la duplicación de código (DRY), garantiza la exclusión de autoconsumos y asegura que el Excel reporte la verdadera ganancia neta.

3. **P2.5**:
   - *Premisa A*: Los observadores de Eloquent (`ThirdPartyCheckObserver`) y controladores (`CatalogController`) dependen de `auth()->id()`.
   - *Premisa B*: El middleware `ValidateSessionToken` resuelve con éxito el modelo `User` mediante el token del encabezado `X-Session-Token`, pero no notifica al subsistema `Auth` de Laravel.
   - *Deducción 1*: Al agregar `Auth::setUser($user)` y `$request->setUserResolver(fn () => $user)` en la línea 47 de `ValidateSessionToken.php`, el usuario queda registrado en el framework para toda la duración del ciclo de vida del request.
   - *Deducción 2*: Al mantener la inyección `$request->attributes->set('authenticated_user', $user)`, no se produce ninguna ruptura en los middlewares secundarios que leen dicho atributo (`EnsureUserIsAdmin`, `EnsureRoleOrPin`).

---

## 3. Caveats (Advertencias y Supuestos)

- **Compatibilidad con Frontends**: Se asume que clientes móviles o web existentes pueden estar enviando indistintamente `'type': 'increment'` o `'type': 'in'`. La ampliación a `'in,out,increment,decrement'` garantiza que ninguna versión de cliente falle.
- **Formato Porcentual en Excel**: En `MonthlyBalanceExport`, Excel formatea porcentajes multiplicando por 100 si la celda tiene formato `FORMAT_PERCENTAGE_00`. La fórmula en `map()` debe entregar la razón decimal (ej. `0.15` para `15%`).
- **Estado de Pruebas**: Actualmente pasan 104 tests (374 aserciones). No existen tests para los endpoints de exportación Excel de balances y categorías; deben crearse durante la fase de implementación.

---

## 4. Conclusion (Conclusión de Ingeniería)

La implementación técnica de los ítems P2.3, P2.4 y P2.5 es directa, de bajo riesgo y altamente desacoplada:
1. **P2.3**: Ampliar `AdjustStockRequest`, inyectarlo en `StockController::adjust()`, y purgar `ProductController::adjustStock()`.
2. **P2.4**: Añadir `getMonthlyBalance()` a `SalesAnalyticsRepository`, hacer que `ReportController` lo consuma, y refactorizar `ProfitByCategoryExport` y `MonthlyBalanceExport` para delegar su colección al repositorio.
3. **P2.5**: Agregar `Auth::setUser($user)` y `$request->setUserResolver(fn () => $user)` en `ValidateSessionToken.php:47`.

---

## 5. Verification Method (Método de Verificación Independiente)

El implementador puede verificar la corrección ejecutando:

1. **Suite de Pruebas Existentes**:
   ```powershell
   php artisan test --filter CatalogStockTest
   php artisan test --filter ReportTest
   php artisan test --filter AuthTest
   php artisan test
   ```
2. **Nuevos Tests a Incorporar**:
   - `CatalogStockTest`: Validar ajuste con `type => 'in'`, `type => 'out'`, `type => 'invalid'` (422), y `quantity => 0` con `min_stock`.
   - `ReportTest`: Invocar `GET /api/reports/sales-by-category/export` y `GET /api/reports/monthly-balance/export` y verificar respuesta 200 con archivo descargable y compatibilidad SQLite.
   - `AuthTest`: Invocar ruta protegida con token válido y verificar `auth()->check() === true` y `auth()->id() === $user->id`.
3. **Inspección de Archivos Clave**:
   - `app/Http/Controllers/Api/ProductController.php`: Confirmar ausencia del método `adjustStock`.
   - `app/Exports/MonthlyBalanceExport.php`: Confirmar ausencia de `DATE_FORMAT` en crudo.
   - `app/Http/Middleware/ValidateSessionToken.php`: Confirmar presencia de `Auth::setUser($user)`.
