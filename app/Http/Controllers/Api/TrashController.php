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
            case 'suppliers':
                return \App\Models\Supplier::onlyTrashed();
            case 'cash_movements':
                return \App\Models\CashMovement::with(['user', 'authorizer', 'deletedBy', 'supplier', 'check'])->onlyTrashed();
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
                if ($model === 'customers') {
                    $q->where('name', 'like', $like)->orWhere('document_number', 'like', $like);
                } elseif ($model === 'products') {
                    $q->where('name', 'like', $like)
                      ->orWhere('barcode', 'like', $like)
                      ->orWhere('internal_code', 'like', $like);
                } elseif ($model === 'suppliers') {
                    $q->where('name', 'like', $like)
                      ->orWhere('cuit', 'like', $like)
                      ->orWhere('contact_name', 'like', $like);
                } elseif ($model === 'cash_movements') {
                    $q->where('category', 'like', $like)
                      ->orWhere('description', 'like', $like)
                      ->orWhere('receipt_number', 'like', $like);
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
        if ($model === 'cash_movements') {
            return response()->json([
                'message' => 'Por seguridad contable, los movimientos de dinero anulados no se pueden restaurar. Debe registrar un movimiento nuevo.'
            ], 422);
        }

        $item = $this->getModelQuery($model)->findOrFail($id);

        \Illuminate\Support\Facades\DB::transaction(function () use ($item) {
            $item->restore();
        });

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
        
        try {
            $item->forceDelete();
            return response()->json([
                'message' => 'Elemento destruido permanentemente.'
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return response()->json([
                    'message' => 'No se puede destruir permanentemente porque tiene historial u otros registros asociados en el sistema (por ej. pagos o facturas). Manténgalo en la papelera por seguridad contable.'
                ], 422);
            }
            return response()->json([
                'message' => 'Error al intentar destruir el elemento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
