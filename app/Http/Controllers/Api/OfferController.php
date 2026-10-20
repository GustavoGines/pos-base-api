<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfferController extends Controller
{
    /**
     * Listar todas las ofertas (historial y gestión con filtros).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Offer::with(['products:id,name,barcode,internal_code,selling_price,cost_price']);

        if ($request->filled('status')) {
            $query->byStatus($request->query('status'));
        }

        if ($request->filled('q')) {
            $search = trim($request->query('q'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('products', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%")
                            ->orWhere('internal_code', 'like', "%{$search}%");
                    });
            });
        }

        $offers = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $offers,
        ]);
    }

    /**
     * Listar ofertas que están vigentes HOY para la terminal POS.
     */
    public function active(Request $request): JsonResponse
    {
        $activeOffers = Offer::active()
            ->with(['products:id,name,barcode,internal_code,selling_price,cost_price'])
            ->orderBy('id', 'desc')
            ->get()
            ->filter(function (Offer $offer) {
                return $offer->isCurrentDayValid();
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $activeOffers,
        ]);
    }

    /**
     * Crear una nueva oferta desde el POS o el Catálogo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percentage,fixed_price',
            'discount_percentage' => 'required_if:type,percentage|nullable|numeric|gt:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'period_type' => 'required|in:daily,weekly,monthly,custom',
            'days_of_week' => 'nullable|array',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.special_price' => 'nullable|numeric|min:0',
        ]);

        $now = Carbon::now();
        $startDate = ! empty($validated['start_date']) ? Carbon::parse($validated['start_date']) : $now->copy();
        $endDate = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;

        // Auto-calcular rango si es diario, semanal o mensual y no se proveyó end_date explícito
        if (! $endDate) {
            switch ($validated['period_type']) {
                case 'daily':
                    $startDate = $now->copy()->startOfDay();
                    $endDate = $now->copy()->endOfDay();
                    break;
                case 'weekly':
                    $endDate = $now->copy()->addDays(7)->endOfDay();
                    break;
                case 'monthly':
                    $endDate = $now->copy()->addDays(30)->endOfDay();
                    break;
                default:
                    $endDate = $now->copy()->addDay()->endOfDay();
                    break;
            }
        }

        DB::beginTransaction();
        try {
            $offer = Offer::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'],
                'discount_percentage' => $validated['discount_percentage'] ?? null,
                'discount_amount' => $validated['discount_amount'] ?? null,
                'period_type' => $validated['period_type'],
                'days_of_week' => $validated['days_of_week'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => true,
            ]);

            $syncData = [];
            foreach ($validated['products'] as $p) {
                $syncData[$p['product_id']] = [
                    'special_price' => $p['special_price'] ?? null,
                ];
            }
            $offer->products()->sync($syncData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Oferta creada y activada correctamente',
                'data' => $offer->load('products:id,name,barcode,internal_code,selling_price'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la oferta: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar una oferta existente.
     */
    public function update(Request $request, Offer $offer): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percentage,fixed_price',
            'discount_percentage' => 'required_if:type,percentage|nullable|numeric|gt:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'period_type' => 'required|in:daily,weekly,monthly,custom',
            'days_of_week' => 'nullable|array',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.special_price' => 'nullable|numeric|min:0',
        ]);

        $now = Carbon::now();
        $originalPeriodType = $offer->period_type;
        $startDate = ! empty($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;
        $endDate = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;

        // Si no se proporcionaron fechas explícitas y el period_type no cambió, conservar las fechas originales
        if ($startDate === null && $endDate === null && $validated['period_type'] === $originalPeriodType) {
            $startDate = $offer->start_date;
            $endDate = $offer->end_date;
        } else {
            // Reiniciar fechas solo si se cambió period_type o se proporcionaron fechas explícitas
            if ($startDate === null) {
                $startDate = $now->copy();
            }
            if ($endDate === null) {
                switch ($validated['period_type']) {
                    case 'daily':
                        $startDate = $now->copy()->startOfDay();
                        $endDate = $now->copy()->endOfDay();
                        break;
                    case 'weekly':
                        $endDate = $now->copy()->addDays(7)->endOfDay();
                        break;
                    case 'monthly':
                        $endDate = $now->copy()->addDays(30)->endOfDay();
                        break;
                    default:
                        $endDate = $now->copy()->addDay()->endOfDay();
                        break;
                }
            }
        }

        DB::beginTransaction();
        try {
            $offer->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'],
                'discount_percentage' => $validated['discount_percentage'] ?? null,
                'discount_amount' => $validated['discount_amount'] ?? null,
                'period_type' => $validated['period_type'],
                'days_of_week' => $validated['days_of_week'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => true,
            ]);

            $syncData = [];
            foreach ($validated['products'] as $p) {
                $syncData[$p['product_id']] = [
                    'special_price' => $p['special_price'] ?? null,
                ];
            }
            $offer->products()->sync($syncData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Oferta actualizada correctamente',
                'data' => $offer->load('products:id,name,barcode,internal_code,selling_price'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la oferta: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Relanzar una oferta (reactivar existente o duplicar como nueva).
     */
    public function relaunch(Request $request, Offer $offer): JsonResponse
    {
        $validated = $request->validate([
            'period_type' => 'required|in:daily,weekly,monthly,custom',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'duplicate' => 'nullable|boolean',
        ]);

        $duplicate = $request->boolean('duplicate', false);
        $now = Carbon::now();
        $startDate = ! empty($validated['start_date']) ? Carbon::parse($validated['start_date']) : $now->copy();
        $endDate = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;

        if (! $endDate) {
            switch ($validated['period_type']) {
                case 'daily':
                    $startDate = $now->copy()->startOfDay();
                    $endDate = $now->copy()->endOfDay();
                    break;
                case 'weekly':
                    $endDate = $now->copy()->addDays(7)->endOfDay();
                    break;
                case 'monthly':
                    $endDate = $now->copy()->addDays(30)->endOfDay();
                    break;
                default:
                    $endDate = $now->copy()->addDay()->endOfDay();
                    break;
            }
        }

        DB::beginTransaction();
        try {
            if ($duplicate) {
                $targetOffer = Offer::create([
                    'name' => $offer->name,
                    'description' => $offer->description,
                    'type' => $offer->type,
                    'discount_percentage' => $offer->discount_percentage,
                    'discount_amount' => $offer->discount_amount,
                    'period_type' => $validated['period_type'],
                    'days_of_week' => $offer->days_of_week,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => true,
                ]);

                $syncData = [];
                foreach ($offer->products as $p) {
                    $syncData[$p->id] = [
                        'special_price' => $p->pivot->special_price ?? null,
                    ];
                }
                $targetOffer->products()->sync($syncData);
            } else {
                $offer->update([
                    'period_type' => $validated['period_type'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => true,
                ]);
                $targetOffer = $offer;
            }

            DB::commit();

            // Evaluar alertas de margen de costo
            $warnings = [];
            foreach ($targetOffer->products as $product) {
                $selling = (float) $product->selling_price;
                $cost = (float) $product->cost_price;
                $discounted = $selling;

                if ($targetOffer->type === 'percentage' && $targetOffer->discount_percentage > 0) {
                    $discounted = $selling * (1 - ($targetOffer->discount_percentage / 100));
                } elseif ($targetOffer->type === 'fixed_price') {
                    if ($product->pivot->special_price !== null && (float) $product->pivot->special_price > 0) {
                        $discounted = (float) $product->pivot->special_price;
                    } elseif ($targetOffer->discount_amount > 0) {
                        $discounted = max(0, $selling - (float) $targetOffer->discount_amount);
                    }
                }

                if ($cost > 0 && $discounted < $cost) {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'cost_price' => $cost,
                        'discounted_price' => round($discounted, 2),
                        'message' => "El precio de oferta (\${$discounted}) es inferior al costo de reposición (\${$cost}).",
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => $duplicate ? 'Oferta duplicada y relanzada correctamente' : 'Oferta relanzada correctamente',
                'data' => $targetOffer->load('products:id,name,barcode,internal_code,selling_price,cost_price'),
                'margin_warnings' => $warnings,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al relanzar la oferta: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alternar estado activo/pausado de una oferta.
     */
    public function toggleStatus(Offer $offer): JsonResponse
    {
        $offer->is_active = ! $offer->is_active;
        $offer->save();

        return response()->json([
            'success' => true,
            'message' => $offer->is_active ? 'Oferta reanudada y activada' : 'Oferta pausada correctamente',
            'data' => $offer->load('products:id,name,barcode,internal_code,selling_price,cost_price'),
        ]);
    }

    /**
     * Eliminar o desactivar una oferta.
     */
    public function destroy(Offer $offer): JsonResponse
    {
        $offer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Oferta eliminada correctamente',
        ]);
    }
}
