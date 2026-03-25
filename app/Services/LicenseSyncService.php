<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        return rtrim(env('CENTRAL_LICENSE_SERVER_URL', 'https://tu-servidor-render.onrender.com'), '/');
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
        $url = $this->getServerUrl() . '/api/validate-license';

        try {
            // Timeout corto para no colgar el POS si no hay internet
            $response = Http::timeout(5)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
            ]);

            if ($response->successful()) {
                // 200 OK: Licencia válida
                $data = $response->json();
                $this->setSetting('app_plan', $data['plan'] ?? 'basic');
                $this->setSetting('last_license_check', now()->toIso8601String());
                Log::info('License Sync: OK', ['plan' => $data['plan']]);
            } else if ($response->status() === 401 || $response->status() === 403) {
                // 401/403: Licencia suspendida, revocada o inválida
                $this->setSetting('app_plan', 'blocked');
                Log::warning('License Sync: Revocada o Inválida', ['status' => $response->status()]);
            } else {
                // 500 u otros errores del servidor: Tratar como "Falla de Internet"
                $this->handleOfflineGracePeriod();
            }
        } catch (\Exception $e) {
            // Timeout / Falla de red: Manejar Grace Period
            Log::error('License Sync: Error de red', ['error' => $e->getMessage()]);
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
            Log::alert('License Sync: Grace period (72h) excedido. Plan bloqueado.');
        } else {
            // Todavía en Grace Period. Mantiene el plan actual silenciosamente.
            Log::info("License Sync: Offline. En periodo de gracia. Horas sin conexión: {$hoursSinceLastCheck}");
        }
    }

    /**
     * Activación manual forzada desde el Frontend de Flutter.
     * Retorna el nuevo plan o arroja una excepción si es inválida.
     */
    public function activateManual(string $licenseKey): string
    {
        $installationId = $this->getInstallationId();
        $url = $this->getServerUrl() . '/api/validate-license';

        try {
            $response = Http::timeout(10)->post($url, [
                'license_key' => $licenseKey,
                'mac_address' => $installationId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $plan = $data['plan'] ?? 'basic';

                $this->setSetting('license_key', $licenseKey);
                $this->setSetting('app_plan', $plan);
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
