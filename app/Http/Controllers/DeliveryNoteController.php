<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryNote::with(['sale.customer', 'items.product']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', ['pending', 'partial']);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                // Buscar por ID de remito
                $q->where('id', 'like', "%{$search}%")
                  // O buscar por cliente asociado a la venta
                  ->orWhereHas('sale.customer', function($qCust) use ($search) {
                      $qCust->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $notes = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 50));
            
        return response()->json($notes);
    }

    public function generateFromSale(Request $request, $saleId)
    {
        $sale = Sale::with('items')->findOrFail($saleId);

        // Check if note already exists to prevent duplicates - return existing if found
        $existingNote = DeliveryNote::where('sale_id', $sale->id)->first();
        if ($existingNote) {
            return response()->json($existingNote->load(['items.product', 'sale.payments.paymentMethod', 'sale.customer']), 200);
        }

        $status = $request->input('status', 'pending');

        $note = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => $status,
            'notes' => 'Generado a partir del comprobante nº ' . $sale->id . ($status === 'delivered' ? ' (Entregado en mostrador)' : ''),
        ]);

        foreach ($sale->items as $item) {
            $note->items()->create([
                'product_id' => $item->product_id,
                'quantity_purchased' => $item->quantity,
                'quantity_delivered' => $status === 'delivered' ? $item->quantity : 0,
            ]);
        }

        return response()->json($note->load(['items.product', 'sale.payments.paymentMethod', 'sale.customer']), 201);
    }

    public function updateDelivery(Request $request, $id)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:delivery_note_items,id',
            'items.*.delivered_now' => 'required|numeric|min:0'
        ]);

        $note = DeliveryNote::with('items')->findOrFail($id);


        foreach ($request->items as $itemRequest) {
            $item = $note->items->where('id', $itemRequest['id'])->first();
            if ($item) {
                // Ensure we don't deliver more than purchased
                $newDelivered = $item->quantity_delivered + $itemRequest['delivered_now'];
                if ($newDelivered > $item->quantity_purchased) {
                    $newDelivered = $item->quantity_purchased;
                }
                $actualDeliveredNow = $newDelivered - $item->quantity_delivered;

                $item->update(['quantity_delivered' => $newDelivered]);
                
                // Lógica de Descuento de Stock en diferido (Fase 3 Logística)
                if ($actualDeliveredNow > 0) {
                    $product = \App\Models\Product::find($item->product_id);
                    if ($product) {
                        if ($product->is_combo) {
                            $combos = \Illuminate\Support\Facades\DB::table('product_combos')
                                        ->where('parent_product_id', $product->id)->get();
                            foreach ($combos as $combo) {
                                $childProd = \App\Models\Product::find($combo->child_product_id);
                                if ($childProd) {
                                    $qtyDeducted = $actualDeliveredNow * $combo->quantity;
                                    $childProd->stock -= $qtyDeducted;
                                    $childProd->save();

                                    \App\Models\StockMovement::create([
                                        'product_id' => $childProd->id,
                                        'user_id'    => $request->attributes->get('authenticated_user')?->id ?? 1,
                                        'type'       => 'sale', // o 'dispatch'
                                        'quantity'   => -$qtyDeducted,
                                        'notes'      => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}"
                                    ]);
                                }
                            }
                        } else {
                            $product->stock -= $actualDeliveredNow;
                            $product->save();

                            \App\Models\StockMovement::create([
                                'product_id' => $product->id,
                                'user_id'    => $request->attributes->get('authenticated_user')?->id ?? 1,
                                'type'       => 'sale', // o 'dispatch'
                                'quantity'   => -$actualDeliveredNow,
                                'notes'      => "Despacho Logístico Remito #{$note->id}"
                            ]);
                        }
                    }
                }

            }
        }

        // Re-evaluar el estado iterando sobre TODOS los ítems del remito, no solo los enviados en el request
        $allDelivered = true;
        // Recargar los ítems para asegurar que tenemos los valores actualizados
        $note->load('items');
        foreach ($note->items as $item) {
            if ($item->quantity_delivered < $item->quantity_purchased) {
                $allDelivered = false;
                break;
            }
        }

        $note->status = $allDelivered ? 'delivered' : 'partial';
        $note->save();

        return response()->json($note->load('items.product'));
    }
}
