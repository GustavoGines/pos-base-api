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
     * Listar todas las ofertas (historial y gestión).
     */
    public function index(Request $request): JsonResponse
    {
        $offers = Offer::with(['products:id,name,barcode,internal_code,selling_price'])
            ->orderBy('id', 'desc')
            ->get();

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
            ->with(['products:id,name,barcode,internal_code,selling_price'])
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
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
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
