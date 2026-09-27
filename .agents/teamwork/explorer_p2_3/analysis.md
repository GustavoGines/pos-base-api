# Informe de Análisis Técnico Profundo: Fase P2 (P2.3, P2.4, P2.5)

**Fecha**: 2026-09-27  
**Repositorio**: `C:\laragon\www\Sistema_POS\pos-backend`  
**Entorno**: Laravel 12.54.1 / PHP 8.3.30  
**Investigador**: Subagente Explorer P2.3 (`explorer_p2_3`)  
**Modo de Operación**: Solo Lectura Estricto (cero modificaciones de código en `app/`, `routes/`, `tests/` o `config/`)

---

## Índice
1. [P2.3 - Sincronización de StockController y AdjustStockRequest](#1-p23---sincronización-de-stockcontroller-y-adjuststockrequest)
   - 1.1 Diagnóstico y Evidencia en Código
   - 1.2 Análisis de Código Muerto (`ProductController::adjustStock`)
   - 1.3 Mapeo y Verificación de Rutas
   - 1.4 Disparidad en Reglas de Validación y Mensajes
   - 1.5 Propuesta de Implementación Quirúrgica
   - 1.6 Impacto en Tests y Nuevas Pruebas Requeridas
2. [P2.4 - Unificación de Exportaciones Excel con SalesAnalyticsRepository](#2-p24---unificación-de-exportaciones-excel-con-salesanalyticsrepository)
   - 2.1 Diagnóstico de `ProfitByCategoryExport`
   - 2.2 Diagnóstico de `MonthlyBalanceExport` (Crash SQLite y Fuga Financiera)
   - 2.3 Estado Actual de `SalesAnalyticsRepository` y `ReportController`
   - 2.4 Propuesta Arquitectónica DRY y Centralización en Repositorio
   - 2.5 Propuesta de Refactorización para `ProfitByCategoryExport`
   - 2.6 Propuesta de Refactorización para `MonthlyBalanceExport`
   - 2.7 Impacto en Tests y Cobertura de Exportación
3. [P2.5 - Conexión de la Autenticación Nativa de Laravel](#3-p25---conexión-de-la-autenticación-nativa-de-laravel)
   - 3.1 Diagnóstico de `ValidateSessionToken.php:47`
   - 3.2 Impacto Forense en el Sistema (`auth()->user() === null`)
   - 3.3 Verificación Empírica y Comportamiento del `AuthManager`
   - 3.4 Sintaxis y Ubicación Exacta de la Solución
   - 3.5 Interacción con Otros Middlewares y Retrocompatibilidad
   - 3.6 Plan de Verificación Automatizada y Tests
4. [Matriz Resumen de Archivos Afectados](#4-matriz-resumen-de-archivos-afectados)

---

## 1. P2.3 - Sincronización de StockController y AdjustStockRequest

### 1.1 Diagnóstico y Evidencia en Código
Actualmente, el sistema presenta una bifurcación y desincronización entre la lógica de ajuste manual de inventario expuesta en la API viva y el FormRequest existente:

1. **`app/Http/Controllers/Api/StockController.php` (Líneas 19-28)**:
   ```php
   public function adjust(Request $request, Product $product)
   {
       $validated = $request->validate([
           'type'      => 'required|in:in,out,increment,decrement',
           'quantity'  => 'required|numeric|min:0',
           'notes'     => 'nullable|string|max:500',
           'min_stock' => 'nullable|numeric|min:0',
           'user_id'   => 'nullable|exists:users,id',
       ]);
   ```
   El método `StockController::adjust` realiza validación acoplada en línea directamente en el controlador usando `$request->validate()`. Admite 4 tipos de operación: `'in'`, `'out'`, `'increment'`, `'decrement'`. Además, acepta `quantity = 0` cuando el propósito del request es únicamente actualizar el `min_stock` (líneas 30-31, 55-59).

2. **`app/Http/Requests/AdjustStockRequest.php` (Líneas 23-31)**:
   ```php
   public function rules(): array
   {
       return [
           'type' => 'required|in:increment,decrement',
           'quantity' => 'required|numeric|min:0.001',
           'notes' => 'nullable|string|max:255',
           'min_stock' => 'nullable|numeric|min:0',
       ];
   }
   ```
   El FormRequest `AdjustStockRequest`:
   - Solo admite `'increment,decrement'` (rechazaría con HTTP 422 peticiones de clientes que envíen `'in'` u `'out'`).
   - Requiere `quantity >= 0.001` (rechazaría peticiones válidas donde `quantity = 0` y solo se envía `min_stock`).
   - Limita `notes` a 255 caracteres en lugar de los 500 permitidos por el controlador.
   - Omite por completo el campo `user_id` (`nullable|exists:users,id`).
   - No tiene implementado el método `messages()` para feedback en español.

### 1.2 Análisis de Código Muerto (`ProductController::adjustStock`)
En `app/Http/Controllers/Api/ProductController.php` (Líneas 117-147) existe el siguiente método:
```php
public function adjustStock(\App\Http\Requests\AdjustStockRequest $request, Product $product)
{
    $validated = $request->validated();

    if ($validated['type'] === 'increment') {
        $product->increment('stock', $validated['quantity']);
    } else {
        $product->decrement('stock', $validated['quantity']);
    }

    if (array_key_exists('min_stock', $validated)) {
        $product->update(['min_stock' => $validated['min_stock']]);
    }

    if (method_exists($product, 'stockMovements')) {
        $product->stockMovements()->create([
            'type' => $validated['type'],
            'quantity' => $validated['quantity'],
            'notes' => $validated['notes'] ?? 'Ajuste manual desde catálogo',
            'user_id' => $request->attributes->get('authenticated_user')?->id,
        ]);
    }

    return response()->json([
        'message' => 'Stock actualizado con éxito',
        'new_stock' => $product->fresh()->stock,
        'product' => $product->fresh()->load(['category', 'brand', 'supplier']),
    ]);
}
```
**Hallazgos sobre `ProductController::adjustStock`**:
- No utiliza transacciones de base de datos (`DB::transaction`), a diferencia de `StockController::adjust` (línea 29).
- No valida si hay stock suficiente al decrementar (`$product->stock < $quantity`), pudiendo generar stock negativo no controlado.
- No mapea tipos `'in'` u `'out'`.
- Utiliza la comprobación `if (method_exists($product, 'stockMovements'))` que es frágil.

### 1.3 Mapeo y Verificación de Rutas
Se realizó una inspección exhaustiva de `routes/api.php` y `routes/web.php`:
- `routes/api.php:145`:
  ```php
  Route::post('/catalog/products/{product}/adjust-stock', [StockController::class, 'adjust']);
  ```
- `routes/api.php:146`:
  ```php
  Route::apiResource('catalog/products', ProductController::class)->except(['index', 'show']);
  ```
- **Conclusión irrefutable**: `ProductController::adjustStock()` **NO posee ninguna ruta asociada**. No existe en `routes/api.php` ni en ningún otro archivo de rutas. Es **100% código muerto** y debe eliminarse de `ProductController.php`.

### 1.4 Disparidad en Reglas de Validación y Mensajes
Para sincronizar `AdjustStockRequest` con las necesidades de `StockController::adjust()`, se deben armonizar las reglas:

| Regla / Campo | `StockController::adjust()` (Actual) | `AdjustStockRequest` (Actual) | Especificación Unificada Requerida |
|---|---|---|---|
| `type` | `required\|in:in,out,increment,decrement` | `required\|in:increment,decrement` | `'required\|in:in,out,increment,decrement'` |
| `quantity` | `required\|numeric\|min:0` | `required\|numeric\|min:0.001` | `'required\|numeric\|min:0'` |
| `notes` | `nullable\|string\|max:500` | `nullable\|string\|max:255` | `'nullable\|string\|max:500'` |
| `min_stock` | `nullable\|numeric\|min:0` | `nullable\|numeric\|min:0` | `'nullable\|numeric\|min:0'` |
| `user_id` | `nullable\|exists:users,id` | *(Omitido)* | `'nullable\|exists:users,id'` |

### 1.5 Propuesta de Implementación Quirúrgica

#### Archivo 1: `app/Http/Requests/AdjustStockRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type'      => 'required|in:in,out,increment,decrement',
            'quantity'  => 'required|numeric|min:0',
            'notes'     => 'nullable|string|max:500',
            'min_stock' => 'nullable|numeric|min:0',
            'user_id'   => 'nullable|exists:users,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required'     => 'El tipo de ajuste es obligatorio.',
            'type.in'           => 'El tipo de ajuste debe ser in, out, increment o decrement.',
            'quantity.required' => 'La cantidad es obligatoria.',
            'quantity.numeric'  => 'La cantidad debe ser un valor numérico.',
            'quantity.min'      => 'La cantidad no puede ser negativa.',
            'notes.max'         => 'Las notas no pueden exceder los 500 caracteres.',
            'min_stock.numeric' => 'El stock mínimo debe ser numérico.',
            'min_stock.min'     => 'El stock mínimo no puede ser negativo.',
            'user_id.exists'    => 'El usuario especificado no existe.',
        ];
    }
}
```

#### Archivo 2: `app/Http/Controllers/Api/StockController.php`
Reemplazar `use Illuminate\Http\Request;` por `use App\Http\Requests\AdjustStockRequest;` en la inyección de `adjust()`:
```php
// En imports:
use App\Http\Requests\AdjustStockRequest;

// En línea 19:
public function adjust(AdjustStockRequest $request, Product $product)
{
    $validated = $request->validated();

    DB::transaction(function () use ($validated, $product, $request) {
        // ... (resto de la lógica actual de StockController se mantiene intacta)
```

#### Archivo 3: `app/Http/Controllers/Api/ProductController.php`
Eliminar completamente el bloque de las líneas 117 a 147 (`public function adjustStock(...)`).

### 1.6 Impacto en Tests y Nuevas Pruebas Requeridas
- **Test existente**: `tests/Feature/CatalogStockTest.php:120-160` (`test_ST04_ST05_ajuste_manual_incrementa_decrementa_crea_movimientos`) ejecuta `increment` y `decrement` contra `/api/catalog/products/{$product->id}/adjust-stock`. Seguirá pasando al 100%.
- **Nuevos casos de prueba recomendados en `tests/Feature/CatalogStockTest.php`**:
  1. Envío de `type => 'in'` y `type => 'out'`.
  2. Envío de `quantity => 0` con `min_stock => 15` (actualización de umbral sin movimiento físico).
  3. Envío de `notes` con longitud de 450 caracteres (para asegurar que no se restrinja a 255).
  4. Envío de `type => 'invalid_type'` esperando HTTP 422 con mensaje personalizado.
  5. Envío de `quantity => -5` esperando HTTP 422.

---

## 2. P2.4 - Unificación de Exportaciones Excel con SalesAnalyticsRepository

### 2.1 Diagnóstico de `ProfitByCategoryExport`
Ubicación: `app/Exports/ProfitByCategoryExport.php:31-92`.
- **Problema 1: Violación Flagrante de DRY**: La clase reescribe toda la consulta SQL cruda que une `sale_items`, `sales`, `products` y `categories` o `brands`.
- **Problema 2: Cuentas Internas no Excluidas**: La consulta SQL en `ProfitByCategoryExport::collection()` contiene:
  ```php
  return $query->whereBetween('sales.created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
      ->where('sales.status', 'completed')
      ->orderByDesc('total_revenue')
      ->get();
  ```
  **Omite por completo** la exclusión de cuentas internas (`customers.is_internal_account = true`). En consecuencia, el archivo Excel descargado incluye ventas de autoconsumo interno influyendo la ganancia comercial.
- **Problema 3: Desincronización con el Dashboard**: Mientras el endpoint JSON `/api/reports/sales-by-category` y el PDF usan `SalesAnalyticsRepository` (que sí excluye cuentas internas), la exportación Excel reporta totales de ventas diferentes y mayores.

### 2.2 Diagnóstico de `MonthlyBalanceExport` (Crash SQLite y Fuga Financiera)
Ubicación: `app/Exports/MonthlyBalanceExport.php:29-78`.
- **Problema 1: Incompatibilidad Crítica Multi-Driver / Crash Fatal**:
  Líneas 43 y 73:
  ```php
  DATE_FORMAT(sales.created_at, '%Y-%m') as period
  // ...
  ->groupByRaw("DATE_FORMAT(sales.created_at, '%Y-%m')")
  ```
  La función `DATE_FORMAT` es exclusiva de MySQL. En SQLite (usado en tests y despliegues portátiles), llamar a esta consulta detona una excepción fatal:
  `Illuminate\Database\QueryException: no such function: DATE_FORMAT`.
- **Problema 2: Omisión de Cuentas Internas**:
  Al igual que el exportador de categorías, no contiene el filtro `whereNotExists` contra `customers.is_internal_account`.
- **Problema 3: Omisión Total de Egresos de Caja (Falsa Ganancia Neta)**:
  `MonthlyBalanceExport` calcula `total_profit = total_revenue - total_cost` y titula la columna "Ganancia Neta". Sin embargo, **nunca deduce los gastos de caja** (`cash_movements` de tipo `expense`). En cambio, `ReportController::getMonthlyBalanceData` (Líneas 280-289) sí deduce:
  ```php
  $expenses = DB::table('cash_movements')
      ->where('type', 'expense')
      ->whereNull('deleted_at')
      ->whereBetween('created_at', [...])->sum('amount');
  $netProfit = $row->total_profit - $expenses;
  ```
  Esto provoca que el Excel informe una ganancia neta inflada respecto a la realidad del negocio y a lo exhibido en la interfaz visual y el PDF.

### 2.3 Estado Actual de `SalesAnalyticsRepository` y `ReportController`
- `app/Repositories/SalesAnalyticsRepository.php`:
  Actualmente solo contiene `getProfitReport(string $startDate, string $endDate, string $groupBy = 'category'): Collection`.
  - Excluye correctamente cuentas internas (`lines 64-69`).
  - Nota de mejora técnica: en la línea 86 tiene `'items_sold' => (int) $prod->items_sold,` lo que trunca productos pesables (DEBT-11). Debe cambiarse a `(float)`.
- `app/Http/Controllers/Api/ReportController.php`:
  Posee el método privado `getMonthlyBalanceData(string $startMonth, string $endMonth): array` (Líneas 219-320).
  - Resuelve la compatibilidad multi-motor:
    ```php
    $isSqlite = DB::connection()->getDriverName() === 'sqlite';
    $periodSql = $isSqlite ? "strftime('%Y-%m', sales.created_at)" : "DATE_FORMAT(sales.created_at, '%Y-%m')";
    ```
  - Excluye cuentas internas (`lines 231-236`).
  - Deduce gastos de caja mensuales (`lines 280-289`).
  - Devuelve estructura con `months` y `totals`.

### 2.4 Propuesta Arquitectónica DRY y Centralización en Repositorio
Para que la lógica sea 100% DRY, uniforme y consumible por controladores y exportadores:

1. **Incorporar en `SalesAnalyticsRepository` el método `getMonthlyBalance(string $startMonth, string $endMonth): array`**:
   Mover la lógica de cálculo mensual desde `ReportController::getMonthlyBalanceData` hacia `SalesAnalyticsRepository::getMonthlyBalance`.
2. **Hacer que `ReportController::getMonthlyBalanceData` delegue al repositorio**:
   ```php
   private function getMonthlyBalanceData(string $startMonth, string $endMonth): array
   {
       return $this->analyticsRepo->getMonthlyBalance($startMonth, $endMonth);
   }
   ```
3. **Inyectar / instanciar `SalesAnalyticsRepository` en `ProfitByCategoryExport` y `MonthlyBalanceExport`**.

### 2.5 Propuesta de Refactorización para `ProfitByCategoryExport`
```php
<?php

namespace App\Exports;

use App\Repositories\SalesAnalyticsRepository;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProfitByCategoryExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping, WithColumnFormatting
{
    protected string $startDate;
    protected string $endDate;
    protected string $type;
    protected SalesAnalyticsRepository $repository;

    public function __construct(string $startDate, string $endDate, string $type = 'category', ?SalesAnalyticsRepository $repository = null)
    {
        $this->startDate  = $startDate;
        $this->endDate    = $endDate;
        $this->type       = $type;
        $this->repository = $repository ?? app(SalesAnalyticsRepository::class);
    }

    public function collection()
    {
        return $this->repository->getProfitReport($this->startDate, $this->endDate, $this->type);
    }

    public function headings(): array
    {
        return [
            $this->type === 'brand' ? 'Marca' : 'Categoría',
            'Cantidad Vendida',
            'Facturación',
            'Ganancia Neta',
            'Margen Promedio (%)'
        ];
    }

    public function map($row): array
    {
        $revenueWithCost = (float) data_get($row, 'revenue_with_cost', 0);
        $totalProfit     = (float) data_get($row, 'total_profit', 0);
        $margin          = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            data_get($row, 'category_name', ''),
            (float) data_get($row, 'items_sold', 0),
            (float) data_get($row, 'total_revenue', 0),
            (float) data_get($row, 'total_profit', 0),
            $margin
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_PERCENTAGE_00,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];
    }
}
```

### 2.6 Propuesta de Refactorización para `MonthlyBalanceExport`

#### Método a agregar en `SalesAnalyticsRepository`:
```php
    /**
     * Obtiene el balance mensual consolidado deduciendo gastos de caja y excluyendo cuentas internas.
     * Compatible con SQLite y MySQL.
     */
    public function getMonthlyBalance(string $startMonth, string $endMonth): array
    {
        $startDate = Carbon::parse($startMonth . '-01')->startOfMonth();
        $endDate   = Carbon::parse($endMonth   . '-01')->endOfMonth();

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $periodSql = $isSqlite ? "strftime('%Y-%m', sales.created_at)" : "DATE_FORMAT(sales.created_at, '%Y-%m')";

        $rows = DB::table('sale_items')
            ->join('sales',    'sales.id',    '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('customers')
                      ->whereColumn('customers.id', 'sales.customer_id')
                      ->where('customers.is_internal_account', true);
            })
            ->whereBetween('sales.created_at', [
                $startDate->toDateTimeString(),
                $endDate->toDateTimeString(),
            ])
            ->selectRaw("
                {$periodSql} as period,
                SUM(sale_items.subtotal)               as total_revenue,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.unit_cost_price * sale_items.quantity
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN products.cost_price * sale_items.quantity
                        ELSE 0
                    END
                )                                       as total_cost,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                        ELSE 0
                    END
                )                                       as total_profit,
                SUM(
                    CASE
                        WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                          OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                        THEN sale_items.subtotal
                        ELSE 0
                    END
                )                                       as revenue_with_cost,
                COUNT(DISTINCT sales.id)               as transactions
            ")
            ->groupByRaw($periodSql)
            ->orderByRaw("period ASC")
            ->get();

        $months = $rows->map(function ($row) {
            $date = Carbon::parse($row->period . '-01');

            $expenses = DB::table('cash_movements')
                ->where('type', 'expense')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [
                    $date->copy()->startOfMonth()->toDateTimeString(),
                    $date->copy()->endOfMonth()->toDateTimeString()
                ])->sum('amount');

            $netProfit = $row->total_profit - $expenses;

            $marginPct = $row->revenue_with_cost > 0
                ? round(($netProfit / $row->revenue_with_cost) * 100, 1)
                : 0.0;

            return [
                'period'            => $row->period,
                'label'             => $date->translatedFormat('M Y'),
                'total_revenue'     => round((float) $row->total_revenue, 2),
                'total_cost'        => round((float) $row->total_cost,    2),
                'total_profit'      => round((float) $netProfit,          2),
                'transactions'      => (int) $row->transactions,
                'margin_pct'        => $marginPct,
                'revenue_with_cost' => round((float) $row->revenue_with_cost, 2),
                'expenses'          => round((float) $expenses, 2),
            ];
        });

        $grandRevenue = $months->sum('total_revenue');
        $grandCost    = $months->sum('total_cost');
        $grandProfit  = $months->sum('total_profit');
        $grandRWC     = $rows->sum('revenue_with_cost');
        $grandMargin  = $grandRWC > 0 ? round(($grandProfit / $grandRWC) * 100, 1) : 0.0;

        return [
            'months' => $months,
            'totals' => [
                'total_revenue'  => round($grandRevenue, 2),
                'total_cost'     => round($grandCost,    2),
                'total_profit'   => round($grandProfit,  2),
                'avg_margin_pct' => $grandMargin,
            ]
        ];
    }
```

#### Código Refactorizado para `app/Exports/MonthlyBalanceExport.php`:
```php
<?php

namespace App\Exports;

use App\Repositories\SalesAnalyticsRepository;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class MonthlyBalanceExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping, WithColumnFormatting
{
    protected string $startMonth;
    protected string $endMonth;
    protected SalesAnalyticsRepository $repository;

    public function __construct(string $startMonth, string $endMonth, ?SalesAnalyticsRepository $repository = null)
    {
        $this->startMonth = $startMonth;
        $this->endMonth   = $endMonth;
        $this->repository = $repository ?? app(SalesAnalyticsRepository::class);
    }

    public function collection()
    {
        $data = $this->repository->getMonthlyBalance($this->startMonth, $this->endMonth);
        return collect($data['months']);
    }

    public function headings(): array
    {
        return [
            'Mes / Período',
            'Tickets Emitidos',
            'Facturación Total',
            'Costo de Ventas',
            'Ganancia Neta',
            'Margen Promedio (%)'
        ];
    }

    public function map($row): array
    {
        $date = Carbon::parse(data_get($row, 'period') . '-01');
        $revenueWithCost = (float) data_get($row, 'revenue_with_cost', 0);
        $totalProfit     = (float) data_get($row, 'total_profit', 0);
        $margin          = $revenueWithCost > 0 ? ($totalProfit / $revenueWithCost) : 0;

        return [
            ucfirst($date->translatedFormat('F Y')),
            (int) data_get($row, 'transactions', 0),
            (float) data_get($row, 'total_revenue', 0),
            (float) data_get($row, 'total_cost', 0),
            (float) data_get($row, 'total_profit', 0),
            $margin
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'F' => NumberFormat::FORMAT_PERCENTAGE_00,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];
    }
}
```

### 2.7 Impacto en Tests y Cobertura de Exportación
- **Tests existentes**: `tests/Feature/ReportTest.php` cubre `test_r02_profit_by_category` y `test_r05_monthly_balance`, pero solo consulta los endpoints JSON.
- **Nuevas pruebas automatizadas requeridas**:
  1. `test_r06_export_profit_by_category_excel`: Llama a `GET /api/reports/sales-by-category/export?start_date=...&end_date=...` y confirma status 200 con cabecera `Content-Type` de Excel (`vnd.openxmlformats-officedocument.spreadsheetml.sheet`).
  2. `test_r07_export_monthly_balance_excel`: Llama a `GET /api/reports/monthly-balance/export?start_month=...&end_month=...` y confirma status 200 en SQLite sin error `DATE_FORMAT`.
  3. Verificación de exclusión: Crear una venta para un cliente con `is_internal_account = true` y verificar que su facturación no aparezca en la colección del Excel.
  4. Verificación de egresos: Crear un movimiento de caja `expense` y comprobar que `total_profit` en la colección refleje la deducción del egreso.

---

## 3. P2.5 - Conexión de la Autenticación Nativa de Laravel

### 3.1 Diagnóstico de `ValidateSessionToken.php:47`
Ubicación: `app/Http/Middleware/ValidateSessionToken.php:45-50`.
```php
        // Adjuntar el usuario resuelto al request para que los controladores
        // puedan usarlo si lo necesitan (ej: auditoría, logs)
        $request->attributes->set('authenticated_user', $user);

        return $next($request);
```
El middleware implementa la validación de `X-Session-Token` contra el campo `session_token` de la tabla `users`. Al hallar el usuario, ejecuta `$request->attributes->set('authenticated_user', $user)`.

**El Defecto**:
- Nunca interactúa con el `AuthManager` de Laravel ni invoca `Auth::setUser($user)`.
- Tampoco invoca `$request->setUserResolver(...)`.

### 3.2 Impacto Forense en el Sistema (`auth()->user() === null`)
Debido a esta omisión, durante el 100% de la ejecución de solicitudes autenticadas:
1. `auth()->user()` retorna `NULL`.
2. `auth()->id()` retorna `NULL`.
3. `Auth::check()` retorna `false`.
4. `$request->user()` retorna `NULL` (a menos que se use un guard especial o resolver).

**Efectos colaterales detectados en el código**:
- `app/Http/Controllers/Api/CatalogController.php:165`:
  ```php
  $history = BulkPriceHistory::create([
      'user_id' => auth()->id() ?? 1,
      // ...
  ]);
  ```
  Al fallar `auth()->id()`, todas las modificaciones masivas de precios de cualquier administrador se registran a nombre del usuario ID 1.
- `app/Observers/ThirdPartyCheckObserver.php:17`:
  ```php
  Log::info('Auditoría de Cheque', [
      'check_id' => $thirdPartyCheck->id,
      'old_status' => $thirdPartyCheck->getOriginal('status'),
      'new_status' => $thirdPartyCheck->status,
      'user_id' => auth()->id(),
      'endorsement_note' => $thirdPartyCheck->status === 'endorsed' ? $thirdPartyCheck->endorsement_note : null,
  ]);
  ```
  La auditoría de cheques de terceros registra `'user_id' => null` en todos los eventos.
- `app/Http/Controllers/Api/PosController.php:52`, `SalesController.php:109, 133`:
  Los desarrolladores tuvieron que agregar parches defensivos:
  `$request->user()?->id ?? $request->attributes->get('authenticated_user')?->id`

### 3.3 Verificación Empírica y Comportamiento del `AuthManager`
Se ejecutó prueba programática en consola CLI comprobando que:
1. `\Illuminate\Support\Facades\Auth::setUser($user)` popula de inmediato `auth()->user()`, `auth()->id()`, `Auth::user()` y `Auth::check()`.
2. Para que `$request->user()` también responda de forma determinista e infalible independientemente de la configuración del guard o del ciclo de vida de la request, se debe añadir:
   ```php
   $request->setUserResolver(fn () => $user);
   ```
3. Mantener `$request->attributes->set('authenticated_user', $user)` garantiza compatibilidad total hacia atrás con los middlewares `EnsureUserIsAdmin` y `EnsureRoleOrPin`.

### 3.4 Sintaxis y Ubicación Exacta de la Solución
En `app/Http/Middleware/ValidateSessionToken.php`:
Importar `use Illuminate\Support\Facades\Auth;` y reemplazar las líneas 45-48:

```php
        // Adjuntar el usuario resuelto al request para retrocompatibilidad
        $request->attributes->set('authenticated_user', $user);

        // Conectar la autenticación nativa de Laravel en el AuthManager y Request Resolver
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
```

### 3.5 Interacción con Otros Middlewares y Retrocompatibilidad
- `EnsureUserIsAdmin.php:20`:
  `$user = $request->attributes->get('authenticated_user');`
  Continúa funcionando exactamente igual. Adicionalmente, ahora el middleware podría opcionalmente usar `auth()->user()` o `$request->user()`.
- `EnsureRoleOrPin.php:21`:
  `$user = $request->attributes->get('authenticated_user');`
  Continúa funcionando sin alteraciones.
- `tests/TestCase.php:actingAsAdmin`:
  Inserta el `session_token` en BD y envía el header `X-Session-Token`. Al ejecutarse `ValidateSessionToken`, ahora el usuario queda formalmente autenticado en `Auth`, beneficiando a cualquier test que evalúe `auth()->user()` o `$request->user()`.

### 3.6 Plan de Verificación Automatizada y Tests
Crear o extender en `tests/Feature/AuthTest.php`:
```php
public function test_validate_session_token_populates_laravel_auth_facade(): void
{
    $user = User::factory()->create([
        'role' => 'admin',
        'session_token' => 'test-auth-sync-token',
    ]);

    $response = $this->withHeader('X-Session-Token', 'test-auth-sync-token')
                     ->getJson('/api/sales');

    $response->assertStatus(200);
    $this->assertTrue(auth()->check());
    $this->assertEquals($user->id, auth()->id());
}
```
Y verificar que en `BulkPriceHistory` y `ThirdPartyCheckObserver` se capture el ID del usuario autenticado sin recurrir a fallback.

---

## 4. Matriz Resumen de Archivos Afectados

| Ítem | Archivo | Acción Técnica | Motivo / Justificación |
|---|---|---|---|
| **P2.3** | `app/Http/Requests/AdjustStockRequest.php` | Modificar reglas: `in:in,out,increment,decrement`, `min:0`, `max:500`, agregar `user_id` y `messages()`. | Homogeneizar validación y admitir tipos y cargas soportados por la API. |
| **P2.3** | `app/Http/Controllers/Api/StockController.php` | Reemplazar `Request $request` por `AdjustStockRequest $request` y usar `$request->validated()`. | Desacoplar validación inline a FormRequest estándar. |
| **P2.3** | `app/Http/Controllers/Api/ProductController.php` | Eliminar método `adjustStock()` (Líneas 117-147). | Erradicar método huérfano / código muerto sin ruta. |
| **P2.4** | `app/Repositories/SalesAnalyticsRepository.php` | Agregar método `getMonthlyBalance(string $startMonth, string $endMonth): array`. | Centralizar consultas financieras, exclusión de cuentas internas y egresos. |
| **P2.4** | `app/Http/Controllers/Api/ReportController.php` | Delegar `getMonthlyBalanceData` a `$this->analyticsRepo->getMonthlyBalance()`. | Eliminar megaconsulta duplicada en controlador. |
| **P2.4** | `app/Exports/ProfitByCategoryExport.php` | Inyectar `SalesAnalyticsRepository` y usar `getProfitReport()`. | DRY; excluir cuentas internas que inflaban utilidades. |
| **P2.4** | `app/Exports/MonthlyBalanceExport.php` | Inyectar `SalesAnalyticsRepository` y usar `getMonthlyBalance()`. | DRY; evitar crash por `DATE_FORMAT` en SQLite; deducir egresos de caja. |
| **P2.5** | `app/Http/Middleware/ValidateSessionToken.php` | Invocar `Auth::setUser($user)` y `$request->setUserResolver(fn() => $user)`. | Conectar `auth()->user()`, `auth()->id()`, `$request->user()` globalmente. |
| **P2.3-5** | `tests/Feature/CatalogStockTest.php`, `ReportTest.php`, `AuthTest.php` | Agregar casos de prueba específicos para validaciones, exports y `Auth::check()`. | Garantizar cobertura total y blindaje contra regresiones. |
