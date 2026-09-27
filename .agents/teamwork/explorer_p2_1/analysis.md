# Análisis Técnico Exhaustivo: Fase P2.1 y P2.6 (Sistema POS Backend)

**Fecha**: 2026-09-27  
**Autor**: Agente Explorer P2.1  
**Directorio de Trabajo**: `C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\explorer_p2_1`  
**Objetivo**: Diagnóstico forense y propuesta técnica detallada para:
1. **P2.1**: Blindaje de remitos logísticos contra doble descuento de stock (`DeliveryNoteController.php:91-125`).
2. **P2.6**: Eliminación de condición de carrera en presupuestos (`Quote::nextQuoteNumber()`).

---

## 1. Misión P2.1: Blindar Remitos Logísticos y Prevenir Doble Descuento de Stock

### 1.1. Contexto del Problema y Mecanismo del Fallo

En el flujo de ventas del Sistema POS existen dos modalidades operativas para la entrega de mercadería:
1. **Venta Directa de Mostrador (`requires_dispatch = false`):**
   - El cliente abona y retira la mercadería de inmediato.
   - En `SaleService::processSale()` (`app/Services/SaleService.php:87`), se ejecuta:
     ```php
     $this->stockService->processCartStock($dto->items, $lockedProducts, $sale, $context, $dto->requiresDispatch, $dto->fulfillmentStatus);
     ```
   - Dentro de `StockService::processCartStock()` (`app/Services/StockService.php:50`), se evalúa:
     ```php
     $shouldDeductStock = (!$requiresDispatch) || ($requiresDispatch && $fulfillmentStatus === 'delivered');
     ```
   - Al ser `$requiresDispatch === false`, `$shouldDeductStock` es `true`.
   - **El inventario físico se descuenta de inmediato**, decrementando `$product->stock` y creando registros en `stock_movements` con `sale_id = $sale->id`, `type = 'sale'` y `notes = "Venta Ticket #{$sale->id}"`.
   - En este flujo **no** se genera remito logístico en la venta.

2. **Generación Posterior de Remito para Venta de Mostrador (`generateFromSale`):**
   - Con posterioridad, el cliente solicita un comprobante de remito o transporte para la venta ya realizada.
   - El operador invoca `POST /api/delivery-notes/from-sale/{saleId}` (`DeliveryNoteController::generateFromSale`).
   - El remito (`DeliveryNote`) se crea con `status = 'pending'` y `quantity_delivered = 0`.

3. **Ejecución de la Entrega Logística (`updateDelivery` - El Fallo Crítico):**
   - Cuando el chofer o despachante entrega la mercadería o se confirma el remito, se invoca:
     `PUT /api/delivery-notes/{id}/deliver`.
   - En `DeliveryNoteController::updateDelivery()` (`DeliveryNoteController.php:91-125`), el código ejecuta incondicionalmente:
     ```php
     if ($actualDeliveredNow > 0) {
         $product = \App\Models\Product::find($item->product_id);
         if ($product) {
             if ($product->is_combo) {
                 ...
                 $childProd->stock -= $qtyDeducted;
                 $childProd->save();
                 ...
             } else {
                 $product->stock -= $actualDeliveredNow;
                 $product->save();
                 \App\Models\StockMovement::create([...]);
             }
         }
     }
     ```
   - **Consecuencia**: El stock físico del producto se resta **por segunda vez**.
   - Ejemplo: Para una venta de 5 unidades de un producto con stock inicial 100:
     - Venta POS: Stock pasa de 100 a 95.
     - Entrega de Remito: Stock pasa de 95 a 90.
     - Se descontaron 10 unidades en lugar de 5 (discrepancia física y contable severa).

---

### 1.2. Fallas Estructurales y de Concurrencia en `DeliveryNoteController.php`

La inspección forense revela 5 deficiencias críticas en `DeliveryNoteController.php`:

| ID Deficiencia | Ubicación | Descripción del Fallo |
|---|---|---|
| **DN-01: Doble Descuento** | `DeliveryNoteController.php:91-125` | Descuenta stock incondicionalmente sin verificar si la venta originaria ya dedujo el stock en mostrador. |
| **DN-02: Falta de Transacción** | `DeliveryNoteController.php:67-146` | El método `updateDelivery()` carece de `DB::transaction()`. Si falla la actualización del ítem 2 o de un combo, la BD queda corrupta y en estado inconsistente. |
| **DN-03: Race Conditions sin Lock** | `DeliveryNoteController.php:75, 92` | `DeliveryNote::with('items')->findOrFail($id)` no utiliza `lockForUpdate()`. Dos clics concurrentes ejecutan el bloque de descuento dos veces. |
| **DN-04: Deadlocks en Combos** | `DeliveryNoteController.php:94-113` | No se bloquean los productos ni sus hijos en orden ascendente (`orderBy('id')`), violando el estándar anti-deadlock de `StockService::lockProducts()`. |
| **DN-05: Auditoría Incompleta** | `DeliveryNoteController.php:104-123` | Las llamadas a `StockMovement::create()` omiten el campo `'sale_id' => $note->sale_id`, dejando los movimientos huérfanos de venta. |

---

### 1.3. Relación entre Modelos y Detección de Descuento Previo

#### Esquema de Tablas
1. **`delivery_notes`** (`database/migrations/2026_04_16_020558_create_delivery_notes_table.php`):
   - `id`: unsignedBigInteger
   - `sale_id`: unsignedBigInteger (FK `sales.id`)
   - `status`: string ('pending', 'partial', 'delivered', 'cancelled')
   - `notes`: text nullable
2. **`stock_movements`** (`database/migrations/2026_09_26_013833_add_audit_columns_to_stock_movements_table.php`):
   - Columnas: `id`, `product_id`, `user_id`, `cash_shift_id`, `sale_id`, `type`, `quantity`, `notes`.
3. **`sales`**:
   - `id`: unsignedBigInteger
   - La tabla `sales` no posee una columna booleana `requires_dispatch`. La bandera `requires_dispatch` pertenece exclusivamente al DTO de entrada (`ProcessSaleDTO`).

#### Mecanismo Seguro para Determinar si la Venta Ya Descontó Stock
Cuando `SaleService` procesa una venta y descuenta stock (`$shouldDeductStock = true`), `StockService::deductProductStock()` invoca `StockService::logMovement()`:
```php
$this->logMovement($product->id, -$qty, 'sale', "Venta Ticket #{$sale->id}", $context, $sale);
```
O para hijos de combo:
```php
$this->logMovement($canonicalChild->id, -$qtyDeducted, 'sale', "Venta Ticket #{$sale->id} (Hijo de: {$product->name})", $context, $sale);
```
Por lo tanto, la venta originaria ya descontó inventario si y solo si:
```php
\App\Models\StockMovement::where('sale_id', $sale->id)
    ->where('type', 'sale')
    ->where('notes', 'like', '%Ticket #' . $sale->id . '%')
    ->exists();
```
- **Si retorna `true`**: La venta fue completada en mostrador con descuento inmediato de inventario. El remito logístico NO debe volver a restar stock físico ni duplicar movimientos contables. Solo debe actualizar las cantidades entregadas en el remito (`quantity_delivered`) y el estado del remito (`partial` / `delivered`).
- **Si retorna `false`**: La venta fue emitida con entrega diferida (`requires_dispatch = true, fulfillment_status = 'pending'`), o fue creada directamente en tests legacy sin descuento previo. En este caso, `updateDelivery()` **SÍ** debe descontar el stock proporcional a `$actualDeliveredNow`.

---

### 1.4. Interacción con Anulación de Ventas (`SaleService::voidSale`)

Un hallazgo conexo fundamental descubierto en la investigación es `StockService::restoreStockForVoid()` (`app/Services/StockService.php:152-156`):
```php
if ($deliveryNote) {
    // Si hay remito, solo devolvemos lo que ya fue entregado
    $dnItem = $deliveryNote->items->firstWhere('product_id', $item->product_id);
    $qtyToRestore = $dnItem ? (float) $dnItem->quantity_delivered : 0.0;
}
```
**Efecto Secundario Detectado**: Si una venta de mostrador (con stock descontado en mostrador) tuvo un remito generado posteriormente con estado `pending` (`quantity_delivered = 0`), y luego esa venta se anula (`POST /api/sales/{id}/void`), `restoreStockForVoid()` restauraría 0 unidades en lugar de las unidades compradas.
**Corrección Armónica en `StockService`**:
```php
if ($deliveryNote && !$sale->hasDeductedStock()) {
    // Si el remito era diferido (stock no descontado en mostrador), solo se restituye lo efectivamente entregado
    $dnItem = $deliveryNote->items->firstWhere('product_id', $item->product_id);
    $qtyToRestore = $dnItem ? (float) $dnItem->quantity_delivered : 0.0;
}
```

---

### 1.5. Propuesta de Modificación de Código (P2.1)

#### A. En `app/Models/Sale.php`
Agregar método de conveniencia:
```php
/**
 * Determina si la venta ya dedujo stock físico durante el checkout.
 */
public function hasDeductedStock(): bool
{
    return \App\Models\StockMovement::where('sale_id', $this->id)
        ->where('type', 'sale')
        ->where('notes', 'like', '%Ticket #' . $this->id . '%')
        ->exists();
}
```

#### B. En `app/Http/Controllers/DeliveryNoteController.php`
Reemplazar `updateDelivery()` por una implementación transaccional con cerrojos pesimistas:
```php
    public function updateDelivery(Request $request, $id, \App\Services\StockService $stockService)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:delivery_note_items,id',
            'items.*.delivered_now' => 'required|numeric|min:0'
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id, $stockService) {
            $note = DeliveryNote::with(['items', 'sale'])->lockForUpdate()->findOrFail($id);

            // Verificar si la venta originaria ya descontó stock al crearse
            $alreadyDeductedAtSale = false;
            if ($note->sale) {
                $alreadyDeductedAtSale = $note->sale->hasDeductedStock();
            }

            // Recolectar IDs de productos afectados para bloqueo pesimista anti-deadlock
            $productIdsToLock = [];
            foreach ($request->items as $itemRequest) {
                $item = $note->items->firstWhere('id', $itemRequest['id']);
                if ($item) {
                    $productIdsToLock[] = $item->product_id;
                }
            }

            // Bloquear productos en orden ascendente mediante StockService si se requerirá descontar stock
            $lockedProducts = (!$alreadyDeductedAtSale && !empty($productIdsToLock))
                ? $stockService->lockProducts($productIdsToLock)
                : collect();

            foreach ($request->items as $itemRequest) {
                $item = $note->items->firstWhere('id', $itemRequest['id']);
                if ($item) {
                    $newDelivered = $item->quantity_delivered + $itemRequest['delivered_now'];
                    if ($newDelivered > $item->quantity_purchased) {
                        $newDelivered = $item->quantity_purchased;
                    }
                    $actualDeliveredNow = $newDelivered - $item->quantity_delivered;

                    $item->update(['quantity_delivered' => $newDelivered]);

                    // Solo descontar stock si la venta originaria NO lo descontó previamente
                    if (!$alreadyDeductedAtSale && $actualDeliveredNow > 0) {
                        $product = $lockedProducts[$item->product_id] ?? \App\Models\Product::find($item->product_id);
                        if ($product) {
                            if ($product->is_combo) {
                                foreach ($product->children as $child) {
                                    $qtyDeducted = $actualDeliveredNow * $child->pivot->quantity;
                                    $canonicalChild = $lockedProducts[$child->id] ?? $child;
                                    $canonicalChild->stock -= $qtyDeducted;
                                    $canonicalChild->save();

                                    \App\Models\StockMovement::create([
                                        'product_id'    => $canonicalChild->id,
                                        'user_id'       => $request->attributes->get('authenticated_user')?->id,
                                        'sale_id'       => $note->sale_id,
                                        'type'          => 'sale',
                                        'quantity'      => -$qtyDeducted,
                                        'notes'         => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}"
                                    ]);
                                }
                            } else {
                                $product->stock -= $actualDeliveredNow;
                                $product->save();

                                \App\Models\StockMovement::create([
                                    'product_id'    => $product->id,
                                    'user_id'       => $request->attributes->get('authenticated_user')?->id,
                                    'sale_id'       => $note->sale_id,
                                    'type'          => 'sale',
                                    'quantity'      => -$actualDeliveredNow,
                                    'notes'         => "Despacho Logístico Remito #{$note->id}"
                                ]);
                            }
                        }
                    }
                }
            }

            // Re-evaluar estado del remito
            $note->load('items');
            $allDelivered = $note->items->every(fn($i) => $i->quantity_delivered >= $i->quantity_purchased);

            $note->status = $allDelivered ? 'delivered' : 'partial';
            $note->save();

            return response()->json($note->load('items.product'));
        });
    }
```

---

## 2. Misión P2.6: Condición de Carrera en Generación de Números de Presupuesto (`Quote`)

### 2.1. Anatomía de la Condición de Carrera

El modelo `Quote` (`app/Models/Quote.php:37-45`) define la generación de su código secuencial de la siguiente manera:
```php
    public static function nextQuoteNumber(): string
    {
        $last = static::latest('id')->value('quote_number');
        if (!$last) {
            return 'PRES-0001';
        }
        $num = (int) substr($last, 5);
        return 'PRES-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
    }
```
Y en `QuoteController::store()` (`app/Http/Controllers/Api/QuoteController.php:100-118`):
```php
    DB::beginTransaction();
    try {
        $subtotal = collect($validated['items'])->sum(...);
        $quote = Quote::create([
            'quote_number'   => Quote::nextQuoteNumber(),
            'status'         => 'pending',
            ...
        ]);
        ...
        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        ...
    }
```

#### Traza de la Colisión Concurrente:
1. Petición A inicia transacción HTTP.
2. Petición B inicia transacción HTTP casi simultáneamente.
3. Petición A ejecuta `Quote::nextQuoteNumber()`. Al ser un `SELECT` simple sin cerrojo pesimista (`lockForUpdate`), lee el último registro comprometido (ej. `PRES-0005`) y calcula `PRES-0006`.
4. Petición B ejecuta `Quote::nextQuoteNumber()`. Dado el aislamiento de transacciones de MySQL/PostgreSQL (Repeatable Read o Read Committed), la Petición A aún no ha hecho commit, por lo que Petición B lee exactamente el mismo registro (`PRES-0005`) y calcula también `PRES-0006`.
5. Petición A ejecuta `Quote::create(['quote_number' => 'PRES-0006', ...])` y realiza `DB::commit()`.
6. Petición B ejecuta `Quote::create(['quote_number' => 'PRES-0006', ...])`.
7. La tabla `quotes` posee una restricción `UNIQUE` sobre `quote_number` (`2026_04_08_000002_create_quotes_tables.php:17`).
8. **Explosión**: MySQL lanza `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'PRES-0006' for key 'quotes_quote_number_unique'`.
9. Petición B aborta con HTTP 500 y mensaje de error al usuario final.

---

### 2.2. Diseño de la Solución Robusta Multi-Driver

Para resolver de raíz esta condición de carrera sin introducir deadlocks ni depender de extensiones específicas de un único motor, la solución debe contemplar:

1. **Bloqueo Pesimista en `Quote::nextQuoteNumber()`:**
   - La consulta del último registro debe incluir `lockForUpdate()`.
   - Cuando se ejecuta dentro de una transacción activa, `SELECT ... FOR UPDATE` serializa las consultas: la Petición B se pausará a nivel de base de datos hasta que la Petición A complete su transacción (commit o rollback).
   - Al liberarse el cerrojo, Petición B realiza una lectura actual (*current read* en InnoDB) y observará el `PRES-0006` ya confirmado, calculando `PRES-0007`.

2. **Manejo del Caso Borde "Cold-Start" (Tabla Vacía):**
   - En una tabla completamente vacía (0 registros), `SELECT ... LIMIT 1 FOR UPDATE` no encuentra filas sobre las cuales aplicar un row-level lock.
   - En MySQL InnoDB, dos transacciones concurrentes sobre tabla vacía obtendrán un gap lock sobre el pseudo-registro supremo, lo cual puede derivar en un conflicto al insertar el primer registro (`PRES-0001`).
   - Para blindar este caso borde al 100%, `QuoteController::store()` debe implementar un mecanismo de reintento automático (*retry loop* con backoff de milisegundos) en caso de colisión de clave única (`SQLSTATE[23000]`). Al segundo intento, la tabla ya cuenta con la fila 1 y el `lockForUpdate()` opera con eficacia total.

3. **Parsing Numérico Inmune a Desbordamiento o Prefijos Inusuales:**
   - Reemplazar el frágil `substr($last, 5)` por una expresión regular:
     ```php
     $num = preg_match('/(\d+)$/', $last, $matches) ? (int) $matches[1] : 0;
     ```
   - Esto soporta números de más de 4 dígitos (ej: `PRES-10000`) y registros creados en tests con nomenclaturas alternativas (`P-0001`, `P-EXP1`).

---

### 2.3. Propuesta de Modificación de Código (P2.6)

#### A. En `app/Models/Quote.php`
```php
    /**
     * Genera el próximo número de presupuesto (PRES-XXXX) con cerrojo pesimista anti-colisión.
     */
    public static function nextQuoteNumber(): string
    {
        $resolver = function () {
            $query = static::query()->orderBy('id', 'desc');

            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                $query->lockForUpdate();
            }

            $last = $query->value('quote_number');

            if (!$last) {
                return 'PRES-0001';
            }

            $num = preg_match('/(\d+)$/', $last, $matches) ? (int) $matches[1] : 0;
            return 'PRES-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
        };

        // Si ya estamos dentro de una transacción (ej. QuoteController::store), usarla; si no, abrir una atómica
        return \Illuminate\Support\Facades\DB::transactionLevel() > 0
            ? $resolver()
            : \Illuminate\Support\Facades\DB::transaction($resolver);
    }
```

#### B. En `app/Http/Controllers/Api/QuoteController.php`
Reestructurar `store()` con manejo de transacciones nativas y reintentos ante colisión de concurrencia:
```php
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                $subtotal = collect($validated['items'])->sum(function ($item) {
                    return round($item['unit_price'] * $item['quantity'], 2);
                });

                $quote = Quote::create([
                    'quote_number'   => Quote::nextQuoteNumber(),
                    'status'         => 'pending',
                    'subtotal'       => $subtotal,
                    'total'          => $subtotal,
                    'customer_name'  => $validated['customer_name'] ?? null,
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'notes'          => $validated['notes'] ?? null,
                    'valid_until'    => $validated['valid_until'] ?? now()->addDays(7)->toDateString(),
                    'user_id'        => $validated['user_id'] ?? null,
                    'price_list'     => $validated['price_list'] ?? 'base',
                ]);

                foreach ($validated['items'] as $item) {
                    QuoteItem::create([
                        'quote_id'     => $quote->id,
                        'product_id'   => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'],
                        'unit_price'   => $item['unit_price'],
                        'quantity'     => $item['quantity'],
                        'subtotal'     => round($item['unit_price'] * $item['quantity'], 2),
                    ]);
                }

                \Illuminate\Support\Facades\DB::commit();

                return response()->json($quote->load('items'), 201);
            } catch (\Illuminate\Database\QueryException $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                $isDuplicate = in_array($e->getCode(), [23000, '23000'])
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), 'UNIQUE constraint failed');

                if ($isDuplicate && $attempt < $maxAttempts) {
                    usleep(15000 * $attempt); // Backoff ligero de 15ms
                    continue;
                }

                \Illuminate\Support\Facades\Log::error('Database Error in QuoteController store: ' . $e->getMessage());
                return response()->json(['message' => 'Error al guardar el presupuesto: ' . $e->getMessage()], 500);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                \Illuminate\Support\Facades\Log::error('Database Error in QuoteController store: ' . $e->getMessage());
                return response()->json(['message' => 'Error al guardar el presupuesto: ' . $e->getMessage()], 500);
            }
        }
```

---

## 3. Matriz de Archivos Afectados, Líneas y Referencias

| Misión | Archivo | Líneas Actuales | Acción Requerida |
|---|---|---|---|
| **P2.1** | `app/Http/Controllers/DeliveryNoteController.php` | 67-146 | Envolver `updateDelivery()` en `DB::transaction()`, validar `hasDeductedStock()` de la venta originaria antes de decrementar productos, aplicar `lockProducts()` y registrar `sale_id` en `StockMovement`. |
| **P2.1** | `app/Models/Sale.php` | 63-67 | Agregar helper method `hasDeductedStock(): bool` consultando `StockMovement`. |
| **P2.1** | `app/Services/StockService.php` | 152-156 | Ajustar `restoreStockForVoid()` para verificar `!$sale->hasDeductedStock()` antes de limitar la restitución a `quantity_delivered`. |
| **P2.6** | `app/Models/Quote.php` | 37-45 | Agregar `lockForUpdate()` y transacción condicional en `nextQuoteNumber()`, con parsing numérico regex. |
| **P2.6** | `app/Http/Controllers/Api/QuoteController.php` | 100-139 | Implementar bucle de reintentos ante colisión concurrente en `store()`. |

---

## 4. Cobertura de Pruebas Automatizadas Existentes

1. **Pruebas de Remitos y Descuento de Stock:**
   - `tests/Feature/DeliveryNoteTest.php`: 2 tests pasando (`test_d01_generate_delivery_note_from_sale`, `test_d02_update_delivery_note_status_and_stock`).
   - `tests/Feature/SaleVoidTest.php`: 5 tests pasando, incluye caso de anulación con remito (`AN-05` / `AN-06`).
   - `tests/Feature/CatalogStockTest.php`: 5 tests pasando para gestión física de inventario.
2. **Pruebas de Presupuestos:**
   - `tests/Feature/QuoteTest.php`: 9 tests pasando (`Q-01` a `Q-07`), verificando que los presupuestos no descuentan stock, calculan subtotales en backend y son secuenciales.
