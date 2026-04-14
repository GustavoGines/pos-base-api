<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Seguridad Modular — Feature Flags.
 *
 * Uso en rutas: ->middleware(['feature:quotes'])
 *
 * Lee el array `license_addons` guardado localmente por LicenseSyncService.
 * Si el feature solicitado no está en el array, bloquea con 403.
 *
 * Esto protege la API incluso si alguien intenta usar Postman / curl
 * para eludir la interfaz gráfica de Flutter.
 */
class CheckFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Leer el JSON de features desde la BD local (persistido por LicenseSyncService)
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');

        $features = [];
        if (!empty($featuresJson)) {
            $decoded = json_decode($featuresJson, true);
            if (is_array($decoded)) {
                $features = $decoded;
            }
        }

        if (!isset($features[$feature]) || $features[$feature] !== true) {
            return response()->json([
                'message'    => "La licencia activa no incluye el módulo requerido: '{$feature}'. Actualice su plan.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required'   => $feature,
            ], 403);
        }

        return $next($request);
    }
}
