<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return response()->json(ExpenseCategory::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:expense_categories',
            'is_active' => 'boolean',
        ]);

        $category = ExpenseCategory::create($validated);
        return response()->json($category, 201);
    }

    public function show(ExpenseCategory $expenseCategory)
    {
        return response()->json($expenseCategory);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:expense_categories,name,' . $expenseCategory->id,
            'is_active' => 'boolean',
        ]);

        $expenseCategory->update($validated);
        return response()->json($expenseCategory);
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        // Verificar si tiene movimientos asociados
        $hasMovements = \App\Models\CashMovement::where('expense_category_id', $expenseCategory->id)->exists();
        
        if ($hasMovements) {
            // Soft delete
            $expenseCategory->delete();
            return response()->json(['message' => 'Categoría archivada (soft delete) porque tiene movimientos asociados.'], 200);
        }

        $expenseCategory->forceDelete();
        return response()->json(['message' => 'Categoría eliminada permanentemente.'], 200);
    }
}
