<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRubroRequest;
use App\Http\Requests\UpdateRubroRequest;
use App\Models\Rubro;

class RubroController extends Controller
{
    public function index()
    {
        $rubros = Rubro::withCount('categories')
            ->orderBy('name')
            ->get();

        return response()->json($rubros);
    }

    public function store(StoreRubroRequest $request)
    {
        $validated = $request->validated();
        $validated['is_system'] = false;

        $rubro = Rubro::create($validated);

        return response()->json($rubro, 201);
    }

    public function show(Rubro $rubro)
    {
        return response()->json($rubro->load('categories'));
    }

    public function update(UpdateRubroRequest $request, Rubro $rubro)
    {
        $validated = $request->validated();
        unset($validated['is_system']);

        $rubro->update($validated);

        return response()->json($rubro);
    }

    public function destroy(Rubro $rubro)
    {
        if ($rubro->is_system) {
            return response()->json([
                'message' => 'No se puede eliminar el rubro principal del sistema.',
            ], 422);
        }

        if ($rubro->categories()->exists()) {
            $count = $rubro->categories()->count();
            return response()->json([
                'message' => "No se puede eliminar el rubro '{$rubro->name}' porque tiene {$count} categoría(s) asociada(s). Reasigne las categorías antes de continuar.",
            ], 422);
        }

        $rubro->delete();

        return response()->json([
            'message' => "Rubro '{$rubro->name}' eliminado correctamente.",
        ], 200);
    }
}
