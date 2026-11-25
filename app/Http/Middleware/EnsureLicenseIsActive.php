<?php

namespace App\Http\Middleware;

use App\Models\BusinessSetting;
use App\Services\LicenseSyncService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Control de Licencia Activa en Backend (L-02, L-09).
 *
 * Impide que el backend procese ventas o mutaciones operativas cuando:
 * 1. La licencia fue suspendida/revocada, venció o superó el periodo de gracia (`app_plan === 'blocked'`).
 * 2. Se detecta atraso manual de reloj offline (`LICENSE_CLOCK_TAMPERED`).
 * 3. El sello criptográfico local (`license_integrity_hash`) fue adulterado manualmente en la BD.
 *
 * Respeta las rutas de rescate y cierre de turno para que el comercio pueda realizar el Arqueo Z
 * y cerrar sesión o reactivar su licencia sin quedar atrapado.
 */
class EnsureLicenseIsActive
{
    private const LICENSE_SETTING_KEYS = [
        'app_plan',
        'license_plan_mode',
        'license_is_trial',
        'license_block_reason',
        'license_expires_at',
        'last_license_check',
        'license_last_sync',
        'license_max_observed_at',
        'license_clock_anchor_sale_id',
        'license_clock_anchor_shift_id',
        'license_key',
        'license_api_key',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Permitir siempre el cierre de turno de caja para no atrapar efectivo del comerciante
        // (alineado con LicenseGuard.dart: /close-shift está en la whitelist)
        if ($request->is('api/shifts/*/close') || $request->is('api/shifts/current')) {
            return $next($request);
        }

        // L-09: Detectar manipulación directa en base de datos (ej. UPDATE business_settings SET value='lifetime')
        if (! LicenseSyncService::verifyLocalIntegrity()) {
            $storedSeal = BusinessSetting::where('key', 'license_integrity_hash')->value('value');
            $hasLicenseKey = ! empty(BusinessSetting::where('key', 'license_key')->value('value'))
                || ! empty(BusinessSetting::where('key', 'license_api_key')->value('value'));

            // Respaldo para instalaciones heredadas de v1.8.5 sin sello previo pero con license_key y red disponible
            if (($storedSeal === null || trim((string) $storedSeal) === '') && $hasLicenseKey) {
                try {
                    app(LicenseSyncService::class)->syncHeartbeat(timeoutSeconds: 12, lockWaitSeconds: 5);
                } catch (\Throwable) {
                }

                $recoveredPlan = strtolower((string) (BusinessSetting::where('key', 'app_plan')->value('value') ?? ''));
                if (LicenseSyncService::verifyLocalIntegrity() && $recoveredPlan !== '' && $recoveredPlan !== 'blocked') {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'Se detectó una alteración no autorizada en los registros de licencia. Por favor sincronice su licencia con el servidor.',
                'error_code' => 'LICENSE_INTEGRITY_VIOLATION',
            ], 403);
        }

        $settingsMap = BusinessSetting::whereIn('key', self::LICENSE_SETTING_KEYS)
            ->pluck('value', 'key')
            ->toArray();

        $appPlan = strtolower((string) ($settingsMap['app_plan'] ?? ''));

        if ($appPlan === 'blocked') {
            $reason = (string) ($settingsMap['license_block_reason'] ?? '');

            return $this->blockedResponse($reason);
        }

        $planMode = strtolower((string) ($settingsMap['license_plan_mode'] ?? 'saas'));
        if ($planMode !== 'lifetime' && $appPlan !== '') {
            $syncService = app(LicenseSyncService::class);
            $isClockTampered = $syncService->evaluateClockRollback($settingsMap);

            $isExpired = false;
            $expiresAt = $settingsMap['license_expires_at'] ?? null;
            if (! empty($expiresAt)) {
                try {
                    $isExpired = Carbon::parse((string) $expiresAt)->endOfDay()->isPast();
                } catch (\Throwable) {
                }
            }

            $isGraceStale = false;
            $lastCheck = $settingsMap['last_license_check'] ?? $settingsMap['license_last_sync'] ?? null;
            if (! empty($lastCheck)) {
                try {
                    $lastCheckDate = Carbon::parse((string) $lastCheck);
                    $isGraceStale = $lastCheckDate->greaterThan(now()->addHours(2))
                        || $lastCheckDate->diffInHours(now(), true) > 72;
                } catch (\Throwable) {
                }
            }

            if (! $isClockTampered && ! $isExpired && ! $isGraceStale) {
                // Camino caliente sin overhead: persistir High-Water Mark con throttle de 15 minutos.
                $this->touchMaxObservedAtIfDue($settingsMap['license_max_observed_at'] ?? null);

                return $next($request);
            }

            $isTrial = ((string) ($settingsMap['license_is_trial'] ?? '0')) === '1';
            $detectedReason = $isClockTampered
                ? LicenseSyncService::BLOCK_REASON_CLOCK_TAMPERED
                : ($isExpired
                    ? ($isTrial ? LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED : LicenseSyncService::BLOCK_REASON_SUBSCRIPTION_EXPIRED)
                    : LicenseSyncService::BLOCK_REASON_OFFLINE_GRACE);

            $hasLicenseKey = ! empty($settingsMap['license_key']) || ! empty($settingsMap['license_api_key']);

            if ($hasLicenseKey) {
                try {
                    // R3-LIC-03 / R3-LIC-04: Timeout de 12s (inferior a los 20s LAN de Flutter)
                    // y espera de hasta 5s si otra caja de la red local ya está sincronizando en vivo.
                    $syncService->syncHeartbeat(timeoutSeconds: 12, lockWaitSeconds: 5);
                } catch (\Throwable) {
                }

                $refreshed = BusinessSetting::whereIn('key', self::LICENSE_SETTING_KEYS)
                    ->pluck('value', 'key')
                    ->toArray();

                $refreshedPlan = strtolower((string) ($refreshed['app_plan'] ?? ''));
                $refreshedMode = strtolower((string) ($refreshed['license_plan_mode'] ?? 'saas'));
                if ($refreshedMode === 'lifetime') {
                    return $next($request);
                }
                if ($refreshedPlan === 'blocked') {
                    $refreshedReason = (string) ($refreshed['license_block_reason'] ?? $detectedReason);

                    return $this->blockedResponse($refreshedReason);
                }

                $stillClockTampered = $syncService->evaluateClockRollback($refreshed);
                $stillExpired = false;
                if (! empty($refreshed['license_expires_at'])) {
                    try {
                        $stillExpired = Carbon::parse((string) $refreshed['license_expires_at'])->endOfDay()->isPast();
                    } catch (\Throwable) {
                    }
                }

                $stillStale = false;
                $newLastCheck = $refreshed['last_license_check'] ?? $refreshed['license_last_sync'] ?? null;
                if (! empty($newLastCheck)) {
                    try {
                        $newCheckDate = Carbon::parse((string) $newLastCheck);
                        $stillStale = $newCheckDate->greaterThan(now()->addHours(2))
                            || $newCheckDate->diffInHours(now(), true) > 72;
                    } catch (\Throwable) {
                    }
                }

                if (! $stillClockTampered && ! $stillExpired && ! $stillStale) {
                    return $next($request);
                }

                $finalTrial = ((string) ($refreshed['license_is_trial'] ?? '0')) === '1';
                $detectedReason = $stillClockTampered
                    ? LicenseSyncService::BLOCK_REASON_CLOCK_TAMPERED
                    : ($stillExpired
                        ? ($finalTrial ? LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED : LicenseSyncService::BLOCK_REASON_SUBSCRIPTION_EXPIRED)
                        : LicenseSyncService::BLOCK_REASON_OFFLINE_GRACE);
            }

            return $this->blockedResponse($detectedReason);
        }

        return $next($request);
    }

    private function touchMaxObservedAtIfDue(mixed $currentRaw): void
    {
        $now = now();
        $shouldWrite = true;

        if (! empty($currentRaw)) {
            try {
                $current = Carbon::parse((string) $currentRaw);
                if ($current->diffInMinutes($now, false) < 15) {
                    $shouldWrite = false;
                }
            } catch (\Throwable) {
            }
        }

        if ($shouldWrite) {
            BusinessSetting::updateOrCreate(
                ['key' => 'license_max_observed_at'],
                ['value' => $now->toIso8601String()]
            );
        }
    }

    private function blockedResponse(string $reason): Response
    {
        if ($reason === LicenseSyncService::BLOCK_REASON_CLOCK_TAMPERED) {
            return response()->json([
                'message' => 'Se detectó un cambio en la fecha/hora del equipo. Corrija el reloj de Windows y sincronice su licencia con internet.',
                'error_code' => 'LICENSE_CLOCK_TAMPERED',
                'license_block_reason' => $reason,
            ], 403);
        }

        $message = match ($reason) {
            LicenseSyncService::BLOCK_REASON_TRIAL_EXPIRED => 'Tu período de prueba gratuita ha finalizado. Activá tu licencia para continuar operando.',
            LicenseSyncService::BLOCK_REASON_SUBSCRIPTION_EXPIRED => 'Tu suscripción mensual se encuentra vencida. Renová tu abono para continuar operando.',
            LicenseSyncService::BLOCK_REASON_OFFLINE_GRACE => 'El periodo de gracia sin conexión (72 horas) ha expirado. Conecte su terminal a internet para validar la licencia.',
            default => 'Su licencia se encuentra vencida o suspendida. Active o sincronice su licencia para continuar operando.',
        };

        return response()->json([
            'message' => $message,
            'error_code' => 'LICENSE_BLOCKED',
            'license_block_reason' => $reason !== '' ? $reason : null,
        ], 403);
    }
}
