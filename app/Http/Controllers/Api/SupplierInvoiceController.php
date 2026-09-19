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
            'amount' => 'required|numeric|min:0.01',
            'invoice_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'receipt_file_url' => 'nullable|string',
            // Arrays para ítems
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_cost' => 'required_with:items|numeric|min:0',
            'items.*.subtotal' => 'required_with:items|numeric|min:0',
            // Actualización opcional de precios dictada por el usuario desde el frontend
            'items.*.new_selling_price' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($validated, $supplier, $request) {
                $user = $request->attributes->get('authenticated_user');
                
                // 1. Crear la cabecera de la factura
                $invoice = $supplier->invoices()->create([
                    'type' => $validated['type'],
                    'status' => $validated['status'] ?? 'paid',
                    'amount' => $validated['amount'],
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
                    $supplier->decrement('balance', $validated['amount']);
                } else {
                    $supplier->increment('balance', $validated['amount']);
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
}
