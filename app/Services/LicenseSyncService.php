<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LicenseSyncService
{
    private function getSetting(string $key, $default = null)
    {
        $setting = BusinessSetting::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    private function setSetting(string $key, $value): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Alinea el Rubro principal del sistema con el tipo de negocio de la licencia.
     * Un fallo aquí jamás debe interrumpir la sincronización de licencia y flags.
     */
    private function syncSystemRubro(?string $businessType): void
    {
        try {
            app(SystemRubroSyncService::class)->sync($businessType);
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar el rubro principal con el tipo de negocio: '.$e->getMessage());
        }
    }

    /**
     * Computa el sello criptográfico HMAC-SHA256 sobre el estado local de la licencia (L-09).
     */
    public static function computeLocalIntegritySeal(
        string $plan,
        string $planMode,
        string $isLifetime,
        string $featuresJson
    ): string {
        $secret = (string) (config('app.key') ?: 'pos-local-integrity-fallback');
        $canonical = implode('|', [
            strtolower(trim($plan)),
            strtolower(trim($planMode)),
            trim($isLifetime),
            trim($featuresJson),
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Verifica que los ajustes críticos de licencia en BD no hayan sido adulterados manualmente (ej. SQL directo a 'lifetime').
     * Si aún no existe sello en BD (clientes en producción pre-actualización), retorna true (retrocompatible).
     */
    public static function verifyLocalIntegrity(): bool
    {
        $storedSeal = BusinessSetting::where('key', 'license_integrity_hash')->value('value');
        if ($storedSeal === null || $storedSeal === '') {
            return true;
        }

        $plan = (string) (BusinessSetting::where('key', 'app_plan')->value('value') ?? 'basic');
        $planMode = (string) (BusinessSetting::where('key', 'license_plan_mode')->value('value') ?? 'saas');
        $isLifetime = (string) (BusinessSetting::where('key', 'license_is_lifetime')->value('value') ?? '0');
        $featuresJson = (string) (BusinessSetting::where('key', 'license_features_dict')->value('value') ?? '{}');

        $expectedSeal = self::computeLocalIntegritySeal($plan, $planMode, $isLifetime, $featuresJson);

        return hash_equals($expectedSeal, (string) $storedSeal);
    }

    /**
     * Verifica la firma remota del servidor de licencias cuando LICENSE_SIGNING_SECRET está configurado (L-01, L-17).
     */
    private function verifyServerSignature(array $data, string $installationId, string $sentNonce): void
    {
        $signingSecret = (string) config('app.license_signing_secret', '');
        if ($signingSecret === '') {
            return;
        }

        $signature = $data['signature'] ?? null;
        $returnedNonce = $data['nonce'] ?? null;

        if (! is_string($signature) || $signature === '') {
            throw new \Exception('Respuesta del servidor de licencias rechazada: firma criptográfica ausente.');
        }

        if ($returnedNonce !== $sentNonce) {
            throw new \Exception('Respuesta del servidor de licencias rechazada: nonce de desafío no coincide.');
        }

        $features = is_array($data['features'] ?? null) ? $data['features'] : [];
        ksort($features);

        $canonical = implode('|', [
            (string) ($data['status'] ?? ''),
            (string) ($data['plan'] ?? ''),
            (string) ($data['plan_type'] ?? ''),
            (string) ($data['business_type'] ?? ''),
            $installationId,
            (string) ($data['expires_at'] ?? ''),
            (string) ($data['server_time'] ?? ''),
            (string) ($data['nonce'] ?? ''),
            json_encode($features, JSON_UNESCAPED_SLASHES),
        ]);

        $expected = hash_hmac('sha256', $canonical, $signingSecret);
        if (! hash_equals($expected, $signature)) {
            throw new \Exception('Respuesta del servidor de licencias rechazada: firma criptográfica inválida.');
        }
    }

    private function getInstallationId(): string
    {
        $id = $this->getSetting('installation_id');
        if ($id === null || trim((string) $id) === '') {
            $id = (string) Str::uuid();
            $this->setSetting('installation_id', $id);
        }

        return (string) $id;
    }

    private function getServerUrl(): string
    {
        $url = config('app.license_server_url') ?: env('LICENSE_SERVER_URL', 'https://pos-license-server-2jma.onrender.com');

        return rtrim((string) $url, '/');
    }

    /**
     * Aplica de manera uniforme los datos validados por el servidor de licencias (FN-01, L-08, L-09, L-18).
     */
    private function applyValidatedLicenseData(array $data, ?string $licenseKey = null): string
    {
        $plan = (string) ($data['plan'] ?? $data['plan_type'] ?? 'basic');
        $planMode = (string) ($data['plan_mode'] ?? $data['plan_type'] ?? 'saas');
        $isLifetime = $planMode === 'lifetime' ? '1' : '0';
        $businessType = (string) ($data['business_type'] ?? 'retail');

        $rawFeatures = is_array($data['features'] ?? null) ? $data['features'] : [];
        $features = $rawFeatures;

        // Failsafe local override: Si el servidor remoto de licencias es antiguo y no envía
        // las nuevas llaves, pero el plan es Premium/Pro, forzamos la habilitación local.
        $planLower = strtolower($plan);
        if (in_array($planLower, ['premium', 'pro', 'enterprise'], true)) {
            $features['multi_caja'] = $features['multi_caja'] ?? true;
            $features['current_accounts'] = $features['current_accounts'] ?? true;
            $features['suppliers'] = $features['suppliers'] ?? true;
            $features['expenses'] = $features['expenses'] ?? true;
            $features['advanced_reports'] = $features['advanced_reports'] ?? true;
            $features['multiple_prices'] = $features['multiple_prices'] ?? true;
            $features['checks'] = $features['checks'] ?? $features['cheques'] ?? true;
            $features['cheques'] = $features['checks'];
            $features['predictive_alerts'] = $features['predictive_alerts'] ?? true;
            $features['multi_rubro'] = $features['multi_rubro'] ?? true;

            $isHardwareStore = $businessType === 'hardware_store';
            if ($isHardwareStore) {
                $features['quotes'] = $features['quotes'] ?? true;
                $features['logistics'] = $features['logistics'] ?? true;
            } else {
                if (! isset($rawFeatures['quotes']) || $rawFeatures['quotes'] !== true) {
                    $features['quotes'] = false;
                }
                if (! isset($rawFeatures['logistics']) || $rawFeatures['logistics'] !== true) {
                    $features['logistics'] = false;
                }
            }
        } else {
            // Sincronizar alias checks <-> cheques si alguno viene definido
            if (isset($features['checks']) && ! isset($features['cheques'])) {
                $features['cheques'] = (bool) $features['checks'];
            } elseif (isset($features['cheques']) && ! isset($features['checks'])) {
                $features['checks'] = (bool) $features['cheques'];
            }
        }

        $featuresJson = json_encode($features);

        if ($licenseKey !== null) {
            $this->setSetting('license_key', $licenseKey);
        }

        $this->setSetting('app_plan', $plan);
        $this->setSetting('license_plan_mode', $planMode);
        $this->setSetting('license_is_lifetime', $isLifetime);
        $this->setSetting('license_business_type', $businessType);
        $this->syncSystemRubro($businessType);

        // L-08: Fecha de expiración real enviada por el servidor
        $this->setSetting('license_expires_at', $data['expires_at'] ?? $data['expiration_date'] ?? null);
        $this->setSetting('license_next_payment_at', $data['next_payment_at'] ?? null);
        $this->setSetting('license_manage_url', $data['manage_url'] ?? null);
        $this->setSetting('license_features_dict', $featuresJson);

        // L-18: Preservar el server_time oficial del servidor remoto cuando esté disponible
        $referenceTime = now()->toIso8601String();
        if (! empty($data['server_time']) && is_string($data['server_time'])) {
            try {
                $referenceTime = Carbon::parse($data['server_time'])->toIso8601String();
                $this->setSetting('license_server_time', $referenceTime);
            } catch (\Throwable $e) {
                $referenceTime = now()->toIso8601String();
            }
        }
        $this->setSetting('last_license_check', $referenceTime);

        // L-09: Guardar sello de integridad criptográfica local
        $seal = self::computeLocalIntegritySeal($plan, $planMode, $isLifetime, $featuresJson);
        $this->setSetting('license_integrity_hash', $seal);

        // LIMPIEZA EXTREMA: Eliminar keys de legado de la base de datos local
        BusinessSetting::whereIn('key', ['license_addons', 'license_allowed_addons'])->delete();

        return $plan;
    }

    /**
     * Marca la licencia local como bloqueada y actualiza su sello de integridad.
     */
    private function markLicenseBlocked(): void
    {
        $this->setSetting('app_plan', 'blocked');
        $planMode = (string) ($this->getSetting('license_plan_mode', 'saas') ?? 'saas');
        $isLifetime = (string) ($this->getSetting('license_is_lifetime', '0') ?? '0');
        $featuresJson = (string) ($this->getSetting('license_features_dict', '{}') ?? '{}');

        $seal = self::computeLocalIntegritySeal('blocked', $planMode, $isLifetime, $featuresJson);
        $this->setSetting('license_integrity_hash', $seal);
    }

    /**
     * Sincronización silenciosa (Heartbeat) a ejecutar por el Cron Diario.
     */
    public function syncHeartbeat(): void
    {
        $licenseKey = $this->getSetting('license_key');
        if (! $licenseKey) {
            $this->setSetting('app_plan', 'basic'); // Sin clave = básico/restringido

            return;
        }

        $installationId = $this->getInstallationId();
        $nonce = Str::random(32);
        $url = $this->getServerUrl().'/api/validate';

        try {
            $response = Http::timeout(60)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
                'installation_id' => $installationId,
                'nonce' => $nonce,
            ]);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $this->verifyServerSignature($data, $installationId, $nonce);
                $this->applyValidatedLicenseData($data);
            } elseif ($response->status() === 401 || $response->status() === 403 || $response->status() === 404) {
                // 401/403/404: Licencia suspendida, revocada o inválida
                $this->markLicenseBlocked();
                throw new \Exception('La licencia ha sido suspendida, revocada o es inválida.');
            } else {
                // 500 u otros errores del servidor: Tratar como "Falla de Internet"
                $this->handleOfflineGracePeriod();
            }
        } catch (ConnectionException $e) {
            // Timeout / Falla de red: Manejar Grace Period silenciosamente
            $this->handleOfflineGracePeriod();
        }
    }

    /**
     * Maneja el periodo de gracia de 72 horas si falla la conexión.
     */
    private function handleOfflineGracePeriod(): void
    {
        // L-09: Si la base de datos local fue manipulada (ej. forzando lifetime por SQL), bloquear de inmediato.
        if (! self::verifyLocalIntegrity()) {
            $this->markLicenseBlocked();

            return;
        }

        // Las licencias Lifetime legítimas nunca se bloquean por falta de conectividad.
        $planMode = $this->getSetting('license_plan_mode', 'saas');
        if ($planMode === 'lifetime') {
            return;
        }

        // L-08: Si existe fecha de expiración contractual conocida y ya venció, bloquear.
        $expiresAt = $this->getSetting('license_expires_at');
        if (! empty($expiresAt)) {
            try {
                if (Carbon::parse($expiresAt)->endOfDay()->isPast()) {
                    $this->markLicenseBlocked();

                    return;
                }
            } catch (\Throwable $e) {
                // Ignorar error de formato y evaluar last_license_check
            }
        }

        $lastCheck = $this->getSetting('last_license_check');

        if (! $lastCheck) {
            $this->markLicenseBlocked();

            return;
        }

        $lastCheckDate = Carbon::parse($lastCheck);
        $hoursSinceLastCheck = $lastCheckDate->diffInHours(now());

        if ($hoursSinceLastCheck > 72) {
            // Grace period vencido — solo aplica a planes SaaS
            $this->markLicenseBlocked();
        }
    }

    /**
     * Sincronización Manual (Forzada). Arroja excepción si falla.
     */
    public function syncManualForce(): void
    {
        $licenseKey = $this->getSetting('license_key');
        if (! $licenseKey) {
            throw new \Exception('No hay ninguna clave de licencia activa configurada para sincronizar.');
        }

        // L-04: Evitar estampida de workers concurrentes hacia Render en cold-start
        $lock = Cache::lock('pos_license_sync_lock', 65);
        if (! $lock->get()) {
            return;
        }

        try {
            $installationId = $this->getInstallationId();
            $nonce = Str::random(32);
            $url = $this->getServerUrl().'/api/validate';

            try {
                $response = Http::timeout(60)->post($url, [
                    'license_key' => $licenseKey,
                    'mac_address' => $installationId,
                    'installation_id' => $installationId,
                    'nonce' => $nonce,
                ]);

                if ($response->successful()) {
                    $data = $response->json() ?? [];
                    $this->verifyServerSignature($data, $installationId, $nonce);
                    $this->applyValidatedLicenseData($data);
                } elseif ($response->status() === 401 || $response->status() === 403 || $response->status() === 404) {
                    $this->markLicenseBlocked();
                    throw new \Exception('La licencia ha sido suspendida, revocada o es inválida.');
                } else {
                    throw new \Exception('El servidor remoto de licencias no respondió correctamente (HTTP '.$response->status().').');
                }
            } catch (ConnectionException $e) {
                throw new \Exception('Error de red: No se pudo contactar al servidor de licencias. Verifique su conexión y vuelva a intentar.');
            }
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Activación manual forzada desde el Frontend de Flutter.
     * Retorna el nuevo plan o arroja una excepción si es inválida.
     */
    public function activateManual(string $licenseKey): string
    {
        $installationId = $this->getInstallationId();
        $nonce = Str::random(32);
        $url = $this->getServerUrl().'/api/validate';

        try {
            $response = Http::timeout(60)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
                'installation_id' => $installationId,
                'nonce' => $nonce,
            ]);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $this->verifyServerSignature($data, $installationId, $nonce);

                return $this->applyValidatedLicenseData($data, $licenseKey);
            }

            if ($response->status() === 403 || $response->status() === 401 || $response->status() === 404) {
                throw new \Exception('La clave de licencia es inválida o está en uso en otra sucursal.');
            }

            @file_put_contents(storage_path('logs/license_sync_error.html'), $response->body());
            throw new \Exception('URL: '.$url.' | Status: '.$response->status().' | Body: '.substr($response->body(), 0, 100));
        } catch (ConnectionException $e) {
            Log::error('Error de red al validar licencia: '.$e->getMessage());
            throw new \Exception('No se pudo conectar con el servidor de licencias. Detalle: '.$e->getMessage());
        }
    }
}
