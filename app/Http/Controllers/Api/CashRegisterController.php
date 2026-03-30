<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Services\CashShiftService;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    protected CashShiftService $cashShiftService;

    public function __construct(CashShiftService $cashShiftService)
    {
        $this->cashShiftService = $cashShiftService;
    }

    public function index(Request $request)
    {
        $isPro = $this->cashShiftService->hasMultiCajaPermission();

        if (!$isPro) {
            // Seguridad: Leakage Prevention. Plan Básico siempre retorna Caja 1 ignorando BD.
            $primaryRegister = CashRegister::firstOrCreate(
                ['id' => 1],
                ['name' => 'Caja Principal', 'is_active' => true]
            );
            // Retorna un Array con 1 solo objeto simulando respuesta múltiple limpia
            return response()->json([$primaryRegister]);
        }

        // Plan Pro o Multi Caja: Retorno transparente de la BD real
        $registers = CashRegister::where('is_active', true)->get();
        return response()->json($registers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:cash_registers,name',
        ]);

        $register = CashRegister::create([
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return response()->json($register, 201);
    }

    public function update(Request $request, $id)
    {
        $register = CashRegister::findOrFail($id);

        if ($register->id === 1) {
            return response()->json(['message' => 'La Caja Principal no puede ser modificada ni desactivada.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:cash_registers,name,' . $id,
            'is_active' => 'sometimes|boolean',
        ]);

        $register->update($validated);

        return response()->json($register);
    }

    public function destroy($id)
    {
        $register = CashRegister::findOrFail($id);

        if ($register->id === 1) {
            return response()->json(['message' => 'La Caja Principal es obligatoria y no puede eliminarse.'], 403);
        }

        // En lugar de borrar físicamente, podríamos forzar soft delete si hubiera, 
        // pero preferible desactivar si hay turnos vinculados.
        if ($register->shifts()->count() > 0) {
            $register->delete(); // Soft delete because of the trait
            return response()->json(['message' => 'Caja desactivada correctamente (Soft delete aplicado).']);
        }

        $register->forceDelete();
        return response()->json(['message' => 'Caja eliminada con éxito.']);
    }
}
