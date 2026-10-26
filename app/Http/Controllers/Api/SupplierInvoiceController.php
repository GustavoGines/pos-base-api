<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierInvoiceController extends Controller
{
    public function store(Request $request, Supplier $supplier)
    {
        // CP-06: 'type' es nullable con default 'invoice' para retrocompatibilidad con v1.8.5
        $validated = $request->validate([
            'type' => 'nullable|string|in:invoice,credit_note',
            'status' => 'nullable|string|in:pending,partial,paid',
            'amount' => 'nullable|numeric|min:0',
            'invoice_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'receipt_file_url' => 'nullable|string',
            'tax_amount' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            // Arrays para ítems
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_cost' => 'required_with:items|numeric|min:0',
            'items.*.subtotal' => 'required_with:items|numeric|min:0',
            // Actualización opcional de precios dictada por el usuario desde el frontend
            'items.*.new_selling_price' => 'nullable|numeric|min:0',
            'items.*.new_sale_price' => 'nullable|numeric|min:0',
        ]);

        $type = $validated['type'] ?? 'invoice';
        $isCreditNote = $type === 'credit_note';

        if ($request->filled('invoice_number')) {
            $exists = SupplierInvoice::where('supplier_id', $supplier->id)
                ->where('invoice_number', $request->invoice_number)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'El número de comprobante ya existe para este proveedor.',
                ], 422);
            }
        }

        // CP-10: Validar que el nuevo precio de venta no sea inferior al costo unitario en compras
        if (! $isCreditNote && ! empty($validated['items'])) {
            foreach ($validated['items'] as $idx => $itemData) {
                $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                if ($newPrice !== null && (float) $newPrice > 0 && (float) $newPrice < (float) $itemData['unit_cost']) {
                    return response()->json([
                        'message' => 'El nuevo precio de venta no puede ser menor al costo unitario de compra.',
                        'errors' => [
                            "items.{$idx}.new_selling_price" => ['El nuevo precio de venta no puede ser menor al costo unitario de compra.'],
                        ],
                    ], 422);
                }
            }
        }

        try {
            $affectedProductIds = [];

            DB::transaction(function () use ($validated, $supplier, $request, $type, $isCreditNote, &$affectedProductIds) {
                $user = $request->user() ?? $request->attributes->get('authenticated_user');

                // CP-01: Bloqueo pesimista del proveedor al inicio de la transacción
                $lockedSupplier = Supplier::where('id', $supplier->id)->lockForUpdate()->firstOrFail();

                $tax = $validated['tax_amount'] ?? 0;
                $freight = $validated['freight_amount'] ?? 0;
                $discount = $validated['discount_amount'] ?? 0;

                $subtotal = 0;
                if (! empty($validated['items'])) {
                    $subtotal = array_sum(array_column($validated['items'], 'subtotal'));
                } else {
                    $subtotal = $validated['amount'] ?? 0;
                }

                $totalAmount = $subtotal + $freight + $tax - $discount;

                // 1. Crear la cabecera de la factura
                $invoice = $lockedSupplier->invoices()->create([
                    'type' => $type,
                    'status' => $validated['status'] ?? 'pending',
                    'amount' => $totalAmount,
                    'tax_amount' => $tax,
                    'freight_amount' => $freight,
                    'discount_amount' => $discount,
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'receipt_file_url' => $validated['receipt_file_url'] ?? null,
                    'issue_date' => $validated['issue_date'] ?? now(),
                    'due_date' => $validated['due_date'] ?? null,
                    'user_id' => $user->id ?? null,
                ]);

                // 2. Procesar ítems si existen con bloqueo pesimista ordenado (CP-01 / CP-08)
                if (! empty($validated['items'])) {
                    $requestedIds = array_values(array_unique(array_column($validated['items'], 'product_id')));

                    $childIds = DB::table('product_combos')
                        ->whereIn('parent_product_id', $requestedIds)
                        ->pluck('child_product_id')
                        ->toArray();

                    $allIngredientIds = array_values(array_unique(array_merge($requestedIds, $childIds)));

                    $parentComboIds = DB::table('product_combos')
                        ->whereIn('child_product_id', $allIngredientIds)
                        ->pluck('parent_product_id')
                        ->toArray();

                    $allLockIds = array_values(array_unique(array_merge($allIngredientIds, $parentComboIds)));

                    $lockedProducts = Product::whereIn('id', $allLockIds)
                        ->with('children')
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($validated['items'] as $itemData) {
                        $invoice->items()->create([
                            'product_id' => $itemData['product_id'],
                            'quantity' => $itemData['quantity'],
                            'unit_cost' => $itemData['unit_cost'],
                            'subtotal' => $itemData['subtotal'],
                        ]);

                        $product = $lockedProducts->get($itemData['product_id']);
                        if (! $product) {
                            continue;
                        }

                        $absQty = abs((float) $itemData['quantity']);
                        // CP-04: Signo negativo para egresos por Nota de Crédito y positivo para ingresos por Compra
                        $quantityModifier = $isCreditNote ? -$absQty : $absQty;

                        // CP-08: Si se carga un Combo, propagar stock a sus ingredientes hijos
                        if ($product->is_combo && $product->children->isNotEmpty()) {
                            foreach ($product->children as $child) {
                                $lockedChild = $lockedProducts->get($child->id) ?? $child;
                                $childDelta = $quantityModifier * (float) $child->pivot->quantity;

                                StockMovement::create([
                                    'product_id' => $lockedChild->id,
                                    'user_id' => $user->id ?? null,
                                    'supplier_invoice_id' => $invoice->id,
                                    'type' => $isCreditNote ? 'out' : 'purchase',
                                    'quantity' => $childDelta,
                                    'notes' => ($isCreditNote ? 'Devolución (Hijo Combo): Nota de Crédito ' : 'Ingreso por Compra (Hijo Combo): Factura ').($validated['invoice_number'] ?? 'S/N'),
                                ]);

                                $lockedChild->stock = (float) $lockedChild->stock + $childDelta;
                                $lockedChild->save();
                                $affectedProductIds[] = $lockedChild->id;
                            }

                            if (! $isCreditNote) {
                                $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                                if ($newPrice !== null && (float) $newPrice > 0) {
                                    $product->selling_price = (float) $newPrice;
                                }
                            }
                            $product->load('children');
                            $product->cost_price = $product->calculateComboCostPrice();
                            $product->stock = $product->calculateComboAvailableStock();
                            $product->save();
                            $affectedProductIds[] = $product->id;
                        } else {
                            // Producto simple
                            StockMovement::create([
                                'product_id' => $product->id,
                                'user_id' => $user->id ?? null,
                                'supplier_invoice_id' => $invoice->id,
                                'type' => $isCreditNote ? 'out' : 'purchase',
                                'quantity' => $quantityModifier,
                                'notes' => ($isCreditNote ? 'Devolución: Nota de Crédito ' : 'Ingreso por Compra: Factura ').($validated['invoice_number'] ?? 'S/N'),
                            ]);

                            $product->stock = (float) $product->stock + $quantityModifier;

                            if (! $isCreditNote) {
                                $product->cost_price = (float) $itemData['unit_cost'];
                                $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                                if ($newPrice !== null && (float) $newPrice > 0) {
                                    $product->selling_price = (float) $newPrice;
                                }
                            }

                            $product->save();
                            $affectedProductIds[] = $product->id;
                        }
                    }

                    // CP-08: Recalcular combos padres cuyos ingredientes hayan cambiado de stock o costo
                    foreach ($parentComboIds as $comboId) {
                        $combo = $lockedProducts->get($comboId);
                        if ($combo && $combo->is_combo) {
                            $combo->load('children');
                            $combo->cost_price = $combo->calculateComboCostPrice();
                            $combo->stock = $combo->calculateComboAvailableStock();
                            $combo->save();
                            $affectedProductIds[] = $combo->id;
                        }
                    }
                }

                // 3. Aumentar o disminuir deuda global del proveedor
                if ($isCreditNote) {
                    $lockedSupplier->decrement('balance', $totalAmount);
                } else {
                    $lockedSupplier->increment('balance', $totalAmount);
                }
            });

            // CP-02: Sincronizar stock con plataformas e-commerce (MercadoLibre / Tiendanube)
            if (! empty($affectedProductIds)) {
                SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique($affectedProductIds)));
            }

            return response()->json([
                'message' => 'Factura/Remito cargado exitosamente',
                'supplier' => $supplier->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar la factura: '.$e->getMessage()], 500);
        }
    }

    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $safeName = Str::random(40).($extension ? '.'.strtolower($extension) : '');
            $path = $file->storeAs('supplier_invoices', $safeName, 'public');

            // CP-05: Devolver ambas claves ('file_url' y 'path') para compatibilidad total con v1.8.5 y v2.0.3
            return response()->json([
                'message' => 'Archivo subido correctamente',
                'file_url' => '/storage/'.$path,
                'path' => '/storage/'.$path,
            ]);
        }

        return response()->json(['message' => 'No se recibió ningún archivo.'], 400);
    }
}
