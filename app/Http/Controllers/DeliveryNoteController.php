<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryNoteController extends Controller
{
    public function __construct(
        protected StockService $stockService
    ) {}

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
            $query->where(function ($q) use ($search) {
                // Buscar por ID de remito
                $q->where('id', 'like', "%{$search}%")
                  // O buscar por cliente asociado a la venta
                    ->orWhereHas('sale.customer', function ($qCust) use ($search) {
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
            'notes' => 'Generado a partir del comprobante nº '.$sale->id.($status === 'delivered' ? ' (Entregado en mostrador)' : ''),
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
            'items.*.delivered_now' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $note = DeliveryNote::with(['items', 'sale'])->lockForUpdate()->findOrFail($id);

            $alreadyDeducted = $note->sale && $note->sale->hasDeductedStock();

            $lockedProducts = null;
            if (! $alreadyDeducted) {
                $productIds = $note->items->pluck('product_id')->filter()->unique()->toArray();
                $lockedProducts = $this->stockService->lockProducts($productIds);
            }

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

                    // Lógica de Descuento de Stock en diferido (solo si la venta origen NO dedujo stock en checkout)
                    if (! $alreadyDeducted && $actualDeliveredNow > 0 && $lockedProducts) {
                        $product = $lockedProducts[$item->product_id] ?? null;
                        if ($product) {
                            $user = $request->user() ?? $request->attributes->get('authenticated_user');
                            $userId = $user?->id;

                            if ($product->is_combo) {
                                foreach ($product->children as $child) {
                                    $qtyDeducted = $actualDeliveredNow * $child->pivot->quantity;
                                    $canonicalChild = $lockedProducts[$child->id] ?? $child;
                                    $canonicalChild->stock -= $qtyDeducted;
                                    $canonicalChild->save();

                                    StockMovement::create([
                                        'product_id' => $canonicalChild->id,
                                        'user_id' => $userId,
                                        'sale_id' => $note->sale_id,
                                        'type' => 'sale',
                                        'quantity' => -$qtyDeducted,
                                        'notes' => "Despacho Logístico (Hijo del Combo: {$product->name}) Remito #{$note->id}",
                                    ]);
                                }
                            } else {
                                $product->stock -= $actualDeliveredNow;
                                $product->save();

                                StockMovement::create([
                                    'product_id' => $product->id,
                                    'user_id' => $userId,
                                    'sale_id' => $note->sale_id,
                                    'type' => 'sale',
                                    'quantity' => -$actualDeliveredNow,
                                    'notes' => "Despacho Logístico Remito #{$note->id}",
                                ]);
                            }
                        }
                    }
                }
            }

            // Re-evaluar el estado iterando sobre TODOS los ítems del remito, no solo los enviados en el request
            $allDelivered = true;
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
        });
    }
}
