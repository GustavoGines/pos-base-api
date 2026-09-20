<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\SupplierInvoice;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceController extends Controller
{
    public function store(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:invoice,credit_note',
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
        ]);

        if ($request->filled('invoice_number')) {
            $exists = SupplierInvoice::where('supplier_id', $supplier->id)
                ->where('invoice_number', $request->invoice_number)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'El número de comprobante ya existe para este proveedor.'
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($validated, $supplier, $request) {
                $user = $request->attributes->get('authenticated_user');
                
                $tax = $validated['tax_amount'] ?? 0;
                $freight = $validated['freight_amount'] ?? 0;
                $discount = $validated['discount_amount'] ?? 0;
                
                $subtotal = 0;
                if (!empty($validated['items'])) {
                    $subtotal = array_sum(array_column($validated['items'], 'subtotal'));
                } else {
                    $subtotal = $validated['amount'] ?? 0;
                }

                $totalAmount = $subtotal + $freight + $tax - $discount;

                // 1. Crear la cabecera de la factura
                $invoice = $supplier->invoices()->create([
                    'type' => $validated['type'],
                    'status' => $validated['status'] ?? 'paid',
                    'amount' => $totalAmount,
                    'tax_amount' => $tax,
                    'freight_amount' => $freight,
                    'discount_amount' => $discount,
                    'invoice_number' => $validated['invoice_number'],
                    'description' => $validated['description'],
                    'receipt_file_url' => $validated['receipt_file_url'] ?? null,
                    'issue_date' => $validated['issue_date'] ?? now(),
                    'due_date' => $validated['due_date'] ?? null,
                    'user_id' => $user->id ?? null,
                ]);

                // 2. Procesar ítems si existen
                if (!empty($validated['items']) && $validated['type'] === 'invoice') {
                    foreach ($validated['items'] as $itemData) {
                        // Crear el detalle
                        $invoice->items()->create([
                            'product_id' => $itemData['product_id'],
                            'quantity'   => $itemData['quantity'],
                            'unit_cost'  => $itemData['unit_cost'],
                            'subtotal'   => $itemData['subtotal'],
                        ]);

                        $product = Product::find($itemData['product_id']);
                        
                        // Generar movimiento de stock
                        StockMovement::create([
                            'product_id' => $product->id,
                            'user_id'    => $user->id ?? null,
                            'supplier_invoice_id' => $invoice->id,
                            'type'       => 'purchase',
                            'quantity'   => $itemData['quantity'],
                            'notes'      => 'Ingreso por Compra: Factura ' . ($validated['invoice_number'] ?? 'S/N'),
                        ]);

                        // Incrementar stock físico
                        $product->increment('stock', $itemData['quantity']);

                        // Actualizar precio de costo SIEMPRE a la última factura (decisión de negocio minorista)
                        $updateData = ['cost_price' => $itemData['unit_cost']];

                        // Si el usuario decidió cambiar el PVP en el modal de alertas, actualizarlo
                        if (isset($itemData['new_selling_price'])) {
                            $updateData['selling_price'] = $itemData['new_selling_price'];
                        }

                        $product->update($updateData);
                    }
                }

                // 3. Aumentar o disminuir deuda global del proveedor
                if ($validated['type'] === 'credit_note') {
                    $supplier->decrement('balance', $totalAmount);
                } else {
                    $supplier->increment('balance', $totalAmount);
                }
            });

            return response()->json([
                'message' => 'Factura/Remito cargado exitosamente',
                'supplier' => $supplier->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar la factura: ' . $e->getMessage()], 500);
        }
    }

    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // Max 10MB
        ]);

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('supplier_invoices', 'public');
            return response()->json([
                'message' => 'Archivo subido correctamente',
                'file_url' => '/storage/' . $path
            ]);
        }

        return response()->json(['message' => 'No se recibió ningún archivo.'], 400);
    }
}
