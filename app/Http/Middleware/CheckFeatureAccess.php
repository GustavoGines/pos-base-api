<?php

namespace App\Http\Middleware;

use App\Services\LicenseSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Seguridad Modular — Feature Flags.
 *
 * Uso en rutas: ->middleware(['feature:quotes'])
 *
 * Lee el array `license_features_dict` guardado localmente por LicenseSyncService.
 * Si la licencia está bloqueada, adulterada o el feature solicitado no está activo, bloquea con 403.
 *
 * Esto protege la API incluso si alguien intenta usar Postman / curl
 * para eludir la interfaz gráfica de Flutter.
 */
class CheckFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // L-09: Verificar integridad criptográfica local si existe sello
        if (! LicenseSyncService::verifyLocalIntegrity()) {
            return response()->json([
                'message' => 'Se detectó una alteración no autorizada en los permisos de licencia.',
                'error_code' => 'LICENSE_INTEGRITY_VIOLATION',
            ], 403);
        }

        // L-02 / FN-02: Si el plan general está bloqueado, ningún feature modular debe pasar
        $appPlan = strtolower((string) (DB::table('business_settings')->where('key', 'app_plan')->value('value') ?? ''));
        if ($appPlan === 'blocked') {
            return response()->json([
                'message' => 'Su licencia se encuentra bloqueada o vencida.',
                'error_code' => 'LICENSE_BLOCKED',
            ], 403);
        }

        // Leer el JSON de features desde la BD local (persistido por LicenseSyncService)
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');

        $features = [];
        if (! empty($featuresJson)) {
            $decoded = json_decode($featuresJson, true);
            if (is_array($decoded)) {
                $features = $decoded;
            }
        }

        if (! isset($features[$feature]) || $features[$feature] !== true) {
            return response()->json([
                'message' => "La licencia activa no incluye el módulo requerido: '{$feature}'. Actualice su plan.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
