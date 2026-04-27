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

    /**
     * Normaliza los addons del servidor de licencias a los nombres internos del sistema.
     * El servidor puede enviar alias distintos (ej: 'z_reports') que el sistema
     * Flutter espera bajo otro nombre ('advanced_reports').
     */
    private function normalizeAddons(array $addons): array
    {
        // Mapa: alias del servidor => nombre interno del sistema (solo renombres reales)
        $aliasMap = [
            'fast_pos'         => 'fast_pos',
            'current_accounts' => 'current_accounts',
            'multi_caja'       => 'multi_caja',
            'quotes'           => 'quotes',
            'z_reports'        => 'z_reports',        // Reportes Z de auditoría (cierre de turno)
            'advanced_reports' => 'advanced_reports', // Reportes Gerenciales (addon separado)
        ];

        $normalized = [];
        foreach ($addons as $addon) {
            $normalized[] = $aliasMap[$addon] ?? $addon;
        }

        return array_values(array_unique($normalized));
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
                $planMode = $data['plan_mode'] ?? 'saas';
                $this->setSetting('license_plan_mode', $planMode);
                $this->setSetting('license_is_lifetime', $planMode === 'lifetime' ? '1' : '0');
                
                // [feature-flag] Tipo de negocio — persiste en BD local para que el Flutter lo lea offline
                $this->setSetting('license_business_type', $data['business_type'] ?? 'retail');
                
                // Metadatos de suscripción extendidos
                $this->setSetting('license_expires_at', $data['expires_at'] ?? null);
                $this->setSetting('license_next_payment_at', $data['next_payment_at'] ?? null);
                $this->setSetting('license_manage_url', $data['manage_url'] ?? null);
                
                // [feature-flags] Nuevo Diccionario de Características
                $features = $data['features'] ?? [];
                
                // Failsafe local override: Si el servidor remoto de licencias es antiguo y no envía
                // las nuevas llaves, pero el plan es Premium/Pro, forzamos la habilitación local.
                $planLower = strtolower($data['plan'] ?? $data['plan_type'] ?? 'basic');
                if (in_array($planLower, ['premium', 'pro'])) {
                    $features['multi_caja'] = $features['multi_caja'] ?? true;
                    $features['advanced_reports'] = $features['advanced_reports'] ?? true;
                    
                    if (($data['business_type'] ?? 'retail') === 'hardware_store') {
                        $features['multiple_prices'] = $features['multiple_prices'] ?? true;
                        $features['logistics'] = $features['logistics'] ?? true;
                        $features['cheques'] = $features['cheques'] ?? true;
                        $features['predictive_alerts'] = $features['predictive_alerts'] ?? true;
                    }
                }
                $this->setSetting('license_features_dict', json_encode($features));
                
                // LIMPIEZA EXTREMA: Eliminar keys de legado de la base de datos local
                BusinessSetting::whereIn('key', ['license_addons', 'license_allowed_addons'])->delete();
                
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
        // Las licencias Lifetime nunca se bloquean por falta de conectividad.
        // Espeja la misma lógica del Flutter (_checkOfflineGrace): si es lifetime, salir.
        $planMode = $this->getSetting('license_plan_mode', 'saas');
        if ($planMode === 'lifetime') return;

        $lastCheck = $this->getSetting('last_license_check');
        
        if (!$lastCheck) {
            $this->setSetting('app_plan', 'blocked');
            return;
        }

        $lastCheckDate = Carbon::parse($lastCheck);
        $hoursSinceLastCheck = $lastCheckDate->diffInHours(now());

        if ($hoursSinceLastCheck > 72) {
            // Grace period vencido — solo aplica a planes SaaS
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
                $planMode = $data['plan_mode'] ?? 'saas';
                $this->setSetting('license_plan_mode', $planMode);
                $this->setSetting('license_is_lifetime', $planMode === 'lifetime' ? '1' : '0');

                // [feature-flag] Tipo de negocio
                $this->setSetting('license_business_type', $data['business_type'] ?? 'retail');

                // Metadatos extendidos
                $this->setSetting('license_expires_at', $data['expires_at'] ?? null);
                $this->setSetting('license_next_payment_at', $data['next_payment_at'] ?? null);
                $this->setSetting('license_manage_url', $data['manage_url'] ?? null);
                
                // [feature-flags] Nuevo Diccionario de Características
                $features = $data['features'] ?? [];
                
                // Failsafe local override: Si el servidor remoto de licencias es antiguo y no envía
                // las nuevas llaves, pero el plan es Premium/Pro, forzamos la habilitación local.
                $planLower = strtolower($data['plan'] ?? $data['plan_type'] ?? 'basic');
                if (in_array($planLower, ['premium', 'pro'])) {
                    $features['multi_caja'] = $features['multi_caja'] ?? true;
                    $features['advanced_reports'] = $features['advanced_reports'] ?? true;
                    
                    if (($data['business_type'] ?? 'retail') === 'hardware_store') {
                        $features['multiple_prices'] = $features['multiple_prices'] ?? true;
                        $features['logistics'] = $features['logistics'] ?? true;
                        $features['cheques'] = $features['cheques'] ?? true;
                        $features['predictive_alerts'] = $features['predictive_alerts'] ?? true;
                    }
                }
                $this->setSetting('license_features_dict', json_encode($features));

                // LIMPIEZA EXTREMA: Eliminar keys de legado
                BusinessSetting::whereIn('key', ['license_addons', 'license_allowed_addons'])->delete();
                
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
                
                // [feature-flags] Diccionario de Características
                $features = $data['features'] ?? [];
                
                // Failsafe local override: Si el servidor remoto de licencias es antiguo y no envía
                // las nuevas llaves, pero el plan es Premium/Pro, forzamos la habilitación local.
                $planLower = strtolower($data['plan'] ?? $data['plan_type'] ?? 'basic');
                if (in_array($planLower, ['premium', 'pro'])) {
                    $features['multi_caja'] = $features['multi_caja'] ?? true;
                    $features['advanced_reports'] = $features['advanced_reports'] ?? true;
                    
                    if (($data['business_type'] ?? 'retail') === 'hardware_store') {
                        $features['multiple_prices'] = $features['multiple_prices'] ?? true;
                        $features['logistics'] = $features['logistics'] ?? true;
                        $features['cheques'] = $features['cheques'] ?? true;
                        $features['predictive_alerts'] = $features['predictive_alerts'] ?? true;
                    }
                }
                $this->setSetting('license_features_dict', json_encode($features));

                $this->setSetting('license_key', $licenseKey);
                $this->setSetting('app_plan', $plan);
                
                $planMode = $data['plan_mode'] ?? 'saas';
                $this->setSetting('license_plan_mode', $planMode); 
                $this->setSetting('license_is_lifetime', $planMode === 'lifetime' ? '1' : '0');

                // [feature-flag] Tipo de negocio — se persiste en la BD local para modo offline
                $this->setSetting('license_business_type', $data['business_type'] ?? 'retail');

                // Metadatos extendidos
                $this->setSetting('license_expires_at', $data['expires_at'] ?? null);
                $this->setSetting('license_next_payment_at', $data['next_payment_at'] ?? null);
                $this->setSetting('license_manage_url', $data['manage_url'] ?? null);

                // LIMPIEZA EXTREMA: Eliminar keys de legado
                BusinessSetting::whereIn('key', ['license_addons', 'license_allowed_addons'])->delete();
                
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
