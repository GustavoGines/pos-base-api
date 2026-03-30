<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LicenseSyncService
{
    private function getSetting(string $key, $default = null)
    {
        $setting = BusinessSetting::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    private function setSetting(string $key, $value)
    {
        BusinessSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    private function getInstallationId(): string
    {
        $id = $this->getSetting('installation_id');
        if (!$id) {
            $id = (string) Str::uuid();
            $this->setSetting('installation_id', $id);
        }
        return $id;
    }

    private function getServerUrl(): string
    {
        // Fallback para pruebas locales si no está en el .env
        return rtrim(env('LICENSE_SERVER_URL', 'https://pos-license-server-2jma.onrender.com'), '/');
    }

    /**
     * Sincronización silenciosa (Heartbeat) a ejecutar por el Cron Diario.
     */
    public function syncHeartbeat(): void
    {
        $licenseKey = $this->getSetting('license_key');
        if (!$licenseKey) {
            $this->setSetting('app_plan', 'basic'); // Sin clave = básico/restringido
            return;
        }

        $installationId = $this->getInstallationId();
        $url = $this->getServerUrl() . '/api/validate';

        try {
            // Timeout 120s para permitir Cold Starts del server remoto
            $response = Http::timeout(240)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
                'installation_id' => $installationId,
            ]);

            if ($response->successful()) {
                // 200 OK: Licencia válida
                $data = $response->json();
                $this->setSetting('app_plan', $data['plan'] ?? $data['plan_type'] ?? 'basic');
                
                // Determinar si es SaaS o Lifetime (DRM Heartbeat)
                $planMode = $data['plan_mode'] ?? 'saas'; // Default to saas if not specified
                $this->setSetting('license_plan_mode', $planMode);
                
                // Mapear addons (si el usuario compra solo módulos específicos)
                $addons = $data['allowed_addons'] ?? $data['addons'] ?? [];
                $this->setSetting('license_allowed_addons', is_array($addons) ? json_encode($addons) : $addons);
                
                $this->setSetting('last_license_check', now()->toIso8601String());
            } else if ($response->status() === 401 || $response->status() === 403) {
                // 401/403: Licencia suspendida, revocada o inválida
                $this->setSetting('app_plan', 'blocked');
            } else {
                // 500 u otros errores del servidor: Tratar como "Falla de Internet"
                $this->handleOfflineGracePeriod();
            }
        } catch (\Exception $e) {
            // Timeout / Falla de red: Manejar Grace Period silenciosamente
            $this->handleOfflineGracePeriod();
        }
    }

    /**
     * Maneja el periodo de gracia de 72 horas si falla la conexión.
     */
    private function handleOfflineGracePeriod()
    {
        $lastCheck = $this->getSetting('last_license_check');
        
        if (!$lastCheck) {
            $this->setSetting('app_plan', 'blocked');
            return;
        }

        $lastCheckDate = Carbon::parse($lastCheck);
        $hoursSinceLastCheck = $lastCheckDate->diffInHours(now());

        if ($hoursSinceLastCheck > 72) {
            // Grace period vencido
            $this->setSetting('app_plan', 'blocked');
        } else {
            // Todavía en Grace Period. Mantiene el plan actual silenciosamente.
        }
    }

    /**
     * Sincronización Manual (Forzada). Arroja excepción si falla.
     */
    public function syncManualForce(): void
    {
        $licenseKey = $this->getSetting('license_key');
        if (!$licenseKey) {
            throw new \Exception('No hay ninguna clave de licencia activa configurada para sincronizar.');
        }

        $installationId = $this->getInstallationId();
        $url = $this->getServerUrl() . '/api/validate';

        try {
            $response = Http::timeout(240)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
                'installation_id' => $installationId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->setSetting('app_plan', $data['plan'] ?? $data['plan_type'] ?? 'basic');
                
                // ✅ FIX: Sincronizar plan_mode para que los cambios SaaS↔Lifetime
                // propaguen correctamente en cada heartbeat del cliente Flutter.
                $this->setSetting('license_plan_mode', $data['plan_mode'] ?? 'saas');
                
                $addons = $data['allowed_addons'] ?? $data['addons'] ?? [];
                $this->setSetting('license_allowed_addons', is_array($addons) ? json_encode($addons) : $addons);
                
                $this->setSetting('last_license_check', now()->toIso8601String());
            } else if ($response->status() === 401 || $response->status() === 403) {
                // Si está revocada, actualizamos a blocked y tiramos error
                $this->setSetting('app_plan', 'blocked');
                throw new \Exception('La licencia ha sido suspendida, revocada o es inválida.');
            } else {
                throw new \Exception('El servidor remoto de licencias no respondió correctamente (HTTP ' . $response->status() . ').');
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('Error de red: No se pudo contactar al servidor de licencias. Verifique su conexión y vuelva a intentar.');
        }
    }


    /**
     * Activación manual forzada desde el Frontend de Flutter.
     * Retorna el nuevo plan o arroja una excepción si es inválida.
     */
    public function activateManual(string $licenseKey): string
    {
        $installationId = $this->getInstallationId();
        $url = $this->getServerUrl() . '/api/validate';

        try {
            $response = Http::timeout(240)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
                'installation_id' => $installationId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $plan = $data['plan'] ?? $data['plan_type'] ?? 'basic';
                
                // Addons support
                $addons = $data['allowed_addons'] ?? $data['addons'] ?? [];
                $addonsEncoded = is_array($addons) ? json_encode($addons) : $addons;

                $this->setSetting('license_key', $licenseKey);
                $this->setSetting('app_plan', $plan);
                $this->setSetting('license_plan_mode', $data['plan_mode'] ?? 'saas'); 
                $this->setSetting('license_allowed_addons', $addonsEncoded);
                $this->setSetting('last_license_check', now()->toIso8601String());
                
                return $plan;
            }

            if ($response->status() === 403 || $response->status() === 401) {
                throw new \Exception('La clave de licencia es inválida o está en uso en otra sucursal.');
            }

            throw new \Exception('Error del servidor de licencias. Intente más tarde.');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No hay conexión a internet para validar la licencia.');
        }
    }
}
