<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Rubro;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(
            Category::with('rubro')
                ->withCount(['products' => fn ($q) => $q->where('active', true)])
                ->get()
        );
    }

    public function store(StoreCategoryRequest $request)
    {
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');
        $features = $featuresJson ? json_decode($featuresJson, true) : [];
        $isPremium = ! empty($features['multi_rubro']);

        $validated = $request->validated();

        $defaultRubroId = Rubro::where('is_system', true)->value('id') ?? Rubro::value('id');

        if (! $isPremium) {
            $validated['rubro_id'] = $defaultRubroId;
        } else {
            $validated['rubro_id'] = $validated['rubro_id'] ?? $defaultRubroId;
        }

        $category = Category::create($validated);

        return response()->json($category->load('rubro'), 201);
    }

    public function show(Category $category)
    {
        return response()->json($category->load('rubro'));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');
        $features = $featuresJson ? json_decode($featuresJson, true) : [];
        $isPremium = ! empty($features['multi_rubro']);

        $validated = $request->validated();

        $defaultRubroId = Rubro::where('is_system', true)->value('id') ?? Rubro::value('id');

        if (! $isPremium) {
            unset($validated['rubro_id']);
            if (empty($category->rubro_id) && $defaultRubroId) {
                $validated['rubro_id'] = $defaultRubroId;
            }
        } else {
            if (array_key_exists('rubro_id', $validated) && empty($validated['rubro_id']) && $defaultRubroId) {
                $validated['rubro_id'] = $defaultRubroId;
            }
        }

        $category->update($validated);

        return response()->json($category->load('rubro'));
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la categoría "'.$category->name.'" porque tiene '.$category->products()->count().' producto(s) asociado(s). Reasigne los productos antes de eliminar.',
            ], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
