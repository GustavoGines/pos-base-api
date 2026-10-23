<?php

namespace App\Http\Middleware;

use App\Models\BusinessSetting;
use App\Services\LicenseSyncService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Control de Licencia Activa en Backend (L-02, L-09).
 *
 * Impide que el backend procese ventas o mutaciones operativas cuando:
 * 1. La licencia fue suspendida/revocada o venció su periodo de gracia (`app_plan === 'blocked'`).
 * 2. El sello criptográfico local (`license_integrity_hash`) fue adulterado manualmente en la BD
 *    (ej. intentando forzar `license_plan_mode = 'lifetime'` o `app_plan = 'pro'` por SQL).
 *
 * Respeta las rutas de rescate y cierre de turno para que el comercio pueda realizar el Arqueo Z
 * y cerrar sesión o reactivar su licencia sin quedar atrapado.
 */
class EnsureLicenseIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Permitir siempre el cierre de turno de caja para no atrapar efectivo del comerciante
        // (alineado con LicenseGuard.dart: /close-shift está en la whitelist)
        if ($request->is('api/shifts/*/close') || $request->is('api/shifts/current')) {
            return $next($request);
        }

        // L-09: Detectar manipulación directa en base de datos (ej. UPDATE business_settings SET value='lifetime')
        if (! LicenseSyncService::verifyLocalIntegrity()) {
            return response()->json([
                'message' => 'Se detectó una alteración no autorizada en los registros de licencia. Por favor sincronice su licencia con el servidor.',
                'error_code' => 'LICENSE_INTEGRITY_VIOLATION',
            ], 403);
        }

        $appPlan = strtolower((string) (BusinessSetting::where('key', 'app_plan')->value('value') ?? ''));

        if ($appPlan === 'blocked') {
            return response()->json([
                'message' => 'Su licencia se encuentra vencida o suspendida. Active o sincronice su licencia para continuar operando.',
                'error_code' => 'LICENSE_BLOCKED',
            ], 403);
        }

        return $next($request);
    }
}
