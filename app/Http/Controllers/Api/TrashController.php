<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Product;

class TrashController extends Controller
{
    /**
     * Obtener el modelo correspondiente basado en el parámetro de la ruta.
     */
    private function getModelQuery($modelType)
    {
        switch ($modelType) {
            case 'customers':
                return Customer::onlyTrashed();
            case 'products':
                return Product::onlyTrashed();
            default:
                abort(404, 'Modelo no soportado para la papelera de reciclaje.');
        }
    }

    /**
     * Listar elementos eliminados.
     */
    public function index(Request $request, $model)
    {
        $query = $this->getModelQuery($model);

        // Búsqueda simple
        if ($search = $request->query('search')) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like, $model) {
                $q->where('name', 'like', $like);
                if ($model === 'customers') {
                    $q->orWhere('document_number', 'like', $like);
                } elseif ($model === 'products') {
                    $q->orWhere('barcode', 'like', $like)
                      ->orWhere('internal_code', 'like', $like);
                }
            });
        }

        // Ordenar por fecha de eliminación descendente
        return response()->json($query->orderBy('deleted_at', 'desc')->paginate(50));
    }

    /**
     * Restaurar un elemento eliminado.
     */
    public function restore($model, $id)
    {
        $item = $this->getModelQuery($model)->findOrFail($id);
        $item->restore();

        return response()->json([
            'message' => 'Elemento restaurado exitosamente.',
            'data' => $item
        ]);
    }

    /**
     * Eliminar permanentemente (Destruir) un elemento.
     */
    public function forceDelete($model, $id)
    {
        $item = $this->getModelQuery($model)->findOrFail($id);
        $item->forceDelete();

        return response()->json([
            'message' => 'Elemento destruido permanentemente.'
        ], 204);
    }
}
