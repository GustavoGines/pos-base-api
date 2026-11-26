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
    /**
     * @param  string  ...$requiredFeatures  Uno o más features aceptados en condición OR (ej. `feature:expenses,suppliers`)
     */
    public function handle(Request $request, Closure $next, string ...$requiredFeatures): Response
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

        $candidates = [];
        foreach ($requiredFeatures as $raw) {
            foreach (explode(',', $raw) as $part) {
                $clean = trim($part);
                if ($clean !== '') {
                    $candidates[] = $clean;
                }
            }
        }

        $isEnabled = false;
        foreach ($candidates as $candidate) {
            if ($this->isSingleFeatureEnabled($candidate, $features, $appPlan)) {
                $isEnabled = true;
                break;
            }
        }

        $primaryFeature = $candidates[0] ?? '';

        if (! $isEnabled) {
            return response()->json([
                'message' => "La licencia activa no incluye el módulo requerido: '{$primaryFeature}'. Actualice su plan.",
                'error_code' => 'FEATURE_NOT_LICENSED',
                'required' => $primaryFeature,
            ], 403);
        }

        return $next($request);
    }

    private function isSingleFeatureEnabled(string $feature, array $features, string $appPlan): bool
    {
        $isEnabled = isset($features[$feature]) && ($features[$feature] === true || $features[$feature] === 1);

        // Opción 1A: si el diccionario no trae aún `batch_expiration` (pre-heartbeat / tests legados),
        // habilitarlo para Retail/Carnicería y mantenerlo apagado en Ferretería/Indumentaria/Tech/General;
        // activar `production` siempre lo habilita.
        if ($feature === 'batch_expiration' && ! $isEnabled) {
            if (! empty($features['production']) && ($features['production'] === true || $features['production'] === 1)) {
                $isEnabled = true;
            } elseif (! array_key_exists('batch_expiration', $features)) {
                $businessType = (string) (DB::table('business_settings')
                    ->where('key', 'license_business_type')
                    ->value('value') ?? 'retail');
                if (\App\Models\BusinessSetting::hasNativeBatchExpiration(
                    $businessType,
                    \App\Models\BusinessSetting::getSecondaryBusinessTypes()
                )) {
                    $isEnabled = true;
                }
            }
        }

        // Si el plan activo es Premium/Pro (Retail o Ferretería), los módulos incluidos en Premium están habilitados
        if (! $isEnabled && in_array($appPlan, ['pro', 'premium'], true)) {
            $premiumIncludedFeatures = [
                'current_accounts',
                'multi_caja',
                'predictive_alerts',
                'checks',
                'suppliers',
                'expenses',
                'multi_rubro',
                'ecommerce_sync',
                'quotes',
                'multiple_prices',
                'advanced_reports',
            ];
            if (in_array($feature, $premiumIncludedFeatures, true)) {
                $isEnabled = true;
            }
        }

        return $isEnabled;
    }
}
