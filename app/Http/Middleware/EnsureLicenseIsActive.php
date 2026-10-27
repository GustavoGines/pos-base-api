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

        // FINDING-LIC-04: Verificar expiración local o vencimiento de periodo de gracia (72h) en planes SaaS sin cron.
        // Si se detecta vencimiento local o >72h sin check pero existe license_key configurada, intentamos primero
        // un syncHeartbeat() en vivo para no bloquear falsamente terminales con internet activa pero sin Cron de Windows.
        $planMode = strtolower((string) (BusinessSetting::where('key', 'license_plan_mode')->value('value') ?? 'saas'));
        if ($planMode !== 'lifetime' && ! empty($appPlan)) {
            $needsLiveSync = false;
            $blockMessage = null;

            $expiresAt = BusinessSetting::where('key', 'license_expires_at')->value('value');
            if (! empty($expiresAt)) {
                try {
                    if (\Carbon\Carbon::parse($expiresAt)->endOfDay()->isPast()) {
                        $needsLiveSync = true;
                        $blockMessage = 'Su licencia se encuentra vencida. Active o sincronice su licencia para continuar operando.';
                    }
                } catch (\Throwable) {
                }
            }

            if (! $needsLiveSync) {
                $lastCheck = BusinessSetting::where('key', 'last_license_check')->value('value')
                    ?: BusinessSetting::where('key', 'license_last_sync')->value('value');
                if ($lastCheck) {
                    try {
                        $lastCheckDate = \Carbon\Carbon::parse($lastCheck);
                        if ($lastCheckDate->greaterThan(now()->addHours(2)) || $lastCheckDate->diffInHours(now(), true) > 72) {
                            $needsLiveSync = true;
                            $blockMessage = 'El periodo de gracia sin conexión (72 horas) ha expirado. Conecte su terminal a internet para validar la licencia.';
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            if ($needsLiveSync) {
                $hasLicenseKey = ! empty(BusinessSetting::where('key', 'license_key')->value('value'))
                    || ! empty(BusinessSetting::where('key', 'license_api_key')->value('value'));

                if ($hasLicenseKey) {
                    try {
                        app(LicenseSyncService::class)->syncHeartbeat();
                    } catch (\Throwable) {
                    }

                    $refreshedPlan = strtolower((string) (BusinessSetting::where('key', 'app_plan')->value('value') ?? ''));
                    $refreshedMode = strtolower((string) (BusinessSetting::where('key', 'license_plan_mode')->value('value') ?? 'saas'));
                    if ($refreshedMode === 'lifetime') {
                        return $next($request);
                    }
                    if ($refreshedPlan === 'blocked') {
                        return response()->json([
                            'message' => $blockMessage ?? 'Su licencia se encuentra vencida o suspendida. Active o sincronice su licencia para continuar operando.',
                            'error_code' => 'LICENSE_BLOCKED',
                        ], 403);
                    }

                    $stillExpired = false;
                    $newExpiresAt = BusinessSetting::where('key', 'license_expires_at')->value('value');
                    if (! empty($newExpiresAt)) {
                        try {
                            $stillExpired = \Carbon\Carbon::parse($newExpiresAt)->endOfDay()->isPast();
                        } catch (\Throwable) {
                        }
                    }

                    $newLastCheck = BusinessSetting::where('key', 'last_license_check')->value('value')
                        ?: BusinessSetting::where('key', 'license_last_sync')->value('value');
                    $stillStale = false;
                    if ($newLastCheck) {
                        try {
                            $newCheckDate = \Carbon\Carbon::parse($newLastCheck);
                            $stillStale = $newCheckDate->greaterThan(now()->addHours(2)) || $newCheckDate->diffInHours(now(), true) > 72;
                        } catch (\Throwable) {
                        }
                    }

                    if (! $stillExpired && ! $stillStale) {
                        return $next($request);
                    }
                }

                return response()->json([
                    'message' => $blockMessage ?? 'Su licencia se encuentra vencida o suspendida. Active o sincronice su licencia para continuar operando.',
                    'error_code' => 'LICENSE_BLOCKED',
                ], 403);
            }
        }

        return $next($request);
    }
}
