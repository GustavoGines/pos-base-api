<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\DeliveryNote;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    public function index()
    {
        $notes = DeliveryNote::with(['sale.customer', 'items.product'])
            ->whereIn('status', ['pending', 'partial'])
            ->get();
            
        return response()->json($notes);
    }

    public function generateFromSale($saleId)
    {
        $sale = Sale::with('items')->findOrFail($saleId);

        // Check if note already exists to prevent duplicates
        if ($sale->deliveryNote) {
            return response()->json(['message' => 'El remito ya existe para esta venta.'], 400);
        }

        $note = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => 'pending',
            'notes' => 'Generado a partir del comprobante nº ' . $sale->id,
        ]);

        foreach ($sale->items as $item) {
            $note->items()->create([
                'product_id' => $item->product_id,
                'quantity_purchased' => $item->quantity,
                'quantity_delivered' => 0,
            ]);
        }

        return response()->json($note->load('items.product'), 201);
    }

    public function updateDelivery(Request $request, $id)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:delivery_note_items,id',
            'items.*.delivered_now' => 'required|numeric|min:0'
        ]);

        $note = DeliveryNote::with('items')->findOrFail($id);

        $allDelivered = true;
        
        foreach ($request->items as $itemRequest) {
            $item = $note->items->where('id', $itemRequest['id'])->first();
            if ($item) {
                // Ensure we don't deliver more than purchased
                $newDelivered = $item->quantity_delivered + $itemRequest['delivered_now'];
                if ($newDelivered > $item->quantity_purchased) {
                    $newDelivered = $item->quantity_purchased;
                }
                $item->update(['quantity_delivered' => $newDelivered]);
                
                if ($newDelivered < $item->quantity_purchased) {
                    $allDelivered = false;
                }
            }
        }

        $note->status = $allDelivered ? 'delivered' : 'partial';
        $note->save();

        return response()->json($note->load('items.product'));
    }
}
