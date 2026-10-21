<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Http\Requests\Settings\UpdateIntegrationsRequest;
use App\Http\Requests\Settings\UploadAfipCertificatesRequest;
use App\Http\Requests\Settings\UploadLogoRequest;
use App\Models\BusinessSetting;
use App\Services\Afip\AfipCertificateService;
use App\Services\LicenseSyncService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SettingController extends Controller
{
    /**
     * Devuelve todas las configuraciones públicas del negocio como un mapa key-value.
     * Filtra estrictamente claves sensibles para prevenir fuga de credenciales.
     */
    public function index()
    {
        $settings = BusinessSetting::whereIn('key', BusinessSetting::PUBLIC_KEYS)
            ->pluck('value', 'key')
            ->toArray();

        // Blindaje estricto de defensa en profundidad: eliminar cualquier clave sensible
        foreach (BusinessSetting::SENSITIVE_KEYS as $sensitiveKey) {
            unset($settings[$sensitiveKey]);
        }

        if (! empty($settings['logo_path'])) {
            $settings['logo_url'] = asset('storage/'.$settings['logo_path']);
        }

        // Agregar metadata dinámica para el DRM Heartbeat
        $settings['server_time'] = now()->toIso8601String();
        $settings['grace_period_hours'] = 72;

        // Valores por defecto para el motor de Acceso Rápido POS
        $settings['pos_popularity_days'] = $settings['pos_popularity_days'] ?? '30';
        $settings['pos_popularity_metric'] = $settings['pos_popularity_metric'] ?? 'tickets';
        $settings['pos_demote_out_of_stock'] = $settings['pos_demote_out_of_stock'] ?? '0';

        return response()->json($settings);
    }

    /**
     * Actualiza o crea múltiples configuraciones.
     * Encripta claves sensibles y previene la sobreescritura destructiva de tokens enmascarados.
     */
    public function update(UpdateBusinessSettingsRequest $request)
    {

        // ── Guard SaaS: enable_advanced_price_tiers ───────────────────────────────
        if ($request->has('enable_advanced_price_tiers')) {
            $requestedValue = $request->input('enable_advanced_price_tiers');
            $wantsToEnable = $requestedValue === '1' || $requestedValue === true || $requestedValue === 1;

            if ($wantsToEnable) {
                $featuresJson = BusinessSetting::where('key', 'license_features_dict')->value('value');
                $features = [];
                if (! empty($featuresJson)) {
                    $decoded = json_decode($featuresJson, true);
                    if (is_array($decoded)) {
                        $features = $decoded;
                    }
                }

                if (empty($features['multiple_prices']) || $features['multiple_prices'] !== true) {
                    return response()->json([
                        'message' => 'El plan de licencia activo no incluye el módulo "Múltiples Listas de Precios" (multiple_prices). Actualice su plan para activar esta función.',
                        'error_code' => 'FEATURE_NOT_LICENSED',
                        'required' => 'multiple_prices',
                    ], 403);
                }
            }
        }

        $data = $request->all();

        foreach ($data as $key => $value) {
            // afip_key_path y afip_cert_path son gestionados exclusivamente por uploadAfipCertificates
            if ($key === 'afip_key_path' || $key === 'afip_cert_path') {
                continue;
            }

            // Proteger claves críticas de licencia y sistema contra modificación no autorizada vía update general
            if (
                str_starts_with($key, 'license_') ||
                in_array($key, ['app_plan', 'installation_id', 'last_license_check'], true)
            ) {
                continue;
            }

            if (in_array($key, BusinessSetting::SENSITIVE_KEYS, true)) {
                // Si el valor contiene asteriscos de enmascaramiento, preservar secreto existente
                if (is_string($value) && str_contains($value, '****')) {
                    continue;
                }

                if ($value === null || $value === '') {
                    BusinessSetting::setSecret($key, null);
                } else {
                    BusinessSetting::setSecret($key, (string) $value);
                }

                continue;
            }

            // Si el valor es un array u objeto (ej: custom_price_tiers), serializar a JSON string
            $storedValue = is_array($value) ? json_encode($value) : $value;

            BusinessSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue]
            );
        }

        $sanitizedSettings = BusinessSetting::whereNotIn('key', BusinessSetting::SENSITIVE_KEYS)
            ->pluck('value', 'key')
            ->toArray();

        return response()->json([
            'message' => 'Configuración actualizada correctamente.',
            'settings' => $sanitizedSettings,
        ]);
    }

    /**
     * Devuelve el estado de las integraciones (Mercado Pago y AFIP/ARCA)
     * con tokens enmascarados y verificación de certificados en disco privado.
     */
    public function integrations()
    {
        $rawMpToken = BusinessSetting::getSecret('mp_access_token');
        $rawMpSecret = BusinessSetting::getSecret('mp_webhook_secret');
        $mpQrEnabled = BusinessSetting::where('key', 'mp_qr_enabled')->value('value');
        $mpPointDeviceId = BusinessSetting::where('key', 'mp_point_device_id')->value('value');

        $afipEnabled = BusinessSetting::where('key', 'afip_enabled')->value('value');
        $afipCuit = BusinessSetting::where('key', 'afip_cuit')->value('value');
        $afipPtoVta = BusinessSetting::where('key', 'afip_pto_vta')->value('value');
        $afipEnvironment = BusinessSetting::where('key', 'afip_environment')->value('value') ?? 'testing';

        $storedCertPath = BusinessSetting::getSecret('afip_cert_path');
        $storedKeyPath = BusinessSetting::getSecret('afip_key_path');
        $cleanCuit = ! empty($afipCuit) ? preg_replace('/\D/', '', (string) $afipCuit) : null;

        $certPath = null;
        $keyPath = null;
        $certExpiresAt = null;

        if (! empty($cleanCuit)) {
            $defaultCert = storage_path("app/private/afip/{$cleanCuit}/cert.crt");
            $defaultKey = storage_path("app/private/afip/{$cleanCuit}/cert.key");

            if (file_exists($defaultCert)) {
                $certPath = $defaultCert;
            } elseif (! empty($storedCertPath) && str_contains($storedCertPath, $cleanCuit) && file_exists(storage_path('app/private/'.$storedCertPath))) {
                $certPath = storage_path('app/private/'.$storedCertPath);
            }

            if (file_exists($defaultKey)) {
                $keyPath = $defaultKey;
            } elseif (! empty($storedKeyPath) && str_contains($storedKeyPath, $cleanCuit) && file_exists(storage_path('app/private/'.$storedKeyPath))) {
                $keyPath = storage_path('app/private/'.$storedKeyPath);
            }
        }

        $hasCert = ! empty($certPath);
        $hasKey = ! empty($keyPath);

        if ($hasCert) {
            $certExpiresAt = BusinessSetting::where('key', 'afip_cert_expires_at')->value('value');
            if (empty($certExpiresAt) && ! empty($certPath)) {
                $certContent = @file_get_contents($certPath);
                if ($certContent) {
                    $parsed = @openssl_x509_parse($certContent);
                    if (! empty($parsed['validTo_time_t'])) {
                        $certExpiresAt = date('Y-m-d H:i:s', $parsed['validTo_time_t']);
                    }
                }
            }
        }

        $hasMpToken = ! empty($rawMpToken) && trim($rawMpToken) !== '';
        $hasMpSecret = ! empty($rawMpSecret) && trim($rawMpSecret) !== '';

        $maskedMpToken = $hasMpToken ? $this->maskToken(trim($rawMpToken)) : null;
        $maskedMpSecret = $hasMpSecret ? $this->maskToken(trim($rawMpSecret)) : null;

        $parsedPtoVta = (! empty($afipPtoVta) && is_numeric($afipPtoVta) && (int) $afipPtoVta > 0)
            ? (int) $afipPtoVta
            : null;

        $payload = [
            'mp_qr_enabled' => (bool) $mpQrEnabled,
            'mp_point_device_id' => $mpPointDeviceId,
            'mp_access_token' => $maskedMpToken,
            'mp_webhook_secret' => $maskedMpSecret,
            'mp_has_access_token' => $hasMpToken,
            'mp_has_webhook_secret' => $hasMpSecret,
            'mp_webhook_url' => url('/api/webhooks/mercadopago'),
            'afip_enabled' => (bool) $afipEnabled,
            'afip_cuit' => $afipCuit,
            'afip_pto_vta' => $parsedPtoVta,
            'afip_environment' => $afipEnvironment,
            'afip_has_cert' => $hasCert,
            'afip_has_key' => $hasKey,
            'afip_cert_expires_at' => $certExpiresAt,
            'mercado_pago' => [
                'mp_qr_enabled' => (bool) $mpQrEnabled,
                'mp_point_device_id' => $mpPointDeviceId,
                'mp_access_token' => $maskedMpToken,
                'mp_webhook_secret' => $maskedMpSecret,
                'mp_has_access_token' => $hasMpToken,
                'mp_has_webhook_secret' => $hasMpSecret,
                'mp_webhook_url' => url('/api/webhooks/mercadopago'),
            ],
            'afip' => [
                'afip_enabled' => (bool) $afipEnabled,
                'afip_cuit' => $afipCuit,
                'afip_pto_vta' => $parsedPtoVta,
                'afip_environment' => $afipEnvironment,
                'afip_has_cert' => $hasCert,
                'afip_has_key' => $hasKey,
                'afip_cert_expires_at' => $certExpiresAt,
            ],
        ];

        return response()->json($payload);
    }

    /**
     * Guarda la configuración de integraciones, encriptando secretos y preservando tokens enmascarados.
     */
    public function updateIntegrations(UpdateIntegrationsRequest $request)
    {

        if ($request->has('mp_access_token')) {
            $token = $request->input('mp_access_token');
            if ($token !== null && str_contains($token, '****')) {
                // Preservar secreto existente
            } elseif ($token !== null && trim($token) !== '') {
                BusinessSetting::setSecret('mp_access_token', trim($token));
            } else {
                BusinessSetting::setSecret('mp_access_token', null);
            }
        }

        if ($request->has('mp_webhook_secret')) {
            $secret = $request->input('mp_webhook_secret');
            if ($secret !== null && str_contains($secret, '****')) {
                // Preservar secreto existente
            } elseif ($secret !== null && trim($secret) !== '') {
                BusinessSetting::setSecret('mp_webhook_secret', trim($secret));
            } else {
                BusinessSetting::setSecret('mp_webhook_secret', null);
            }
        }

        if ($request->has('mp_qr_enabled')) {
            $val = $request->boolean('mp_qr_enabled') ? '1' : '0';
            BusinessSetting::updateOrCreate(['key' => 'mp_qr_enabled'], ['value' => $val]);
        }

        if ($request->has('mp_point_device_id')) {
            $deviceId = $request->input('mp_point_device_id');
            BusinessSetting::updateOrCreate(
                ['key' => 'mp_point_device_id'],
                ['value' => ($deviceId !== null && trim($deviceId) !== '') ? trim($deviceId) : null]
            );
        }

        if ($request->has('afip_enabled')) {
            $val = $request->boolean('afip_enabled') ? '1' : '0';
            BusinessSetting::updateOrCreate(['key' => 'afip_enabled'], ['value' => $val]);
        }

        if ($request->has('afip_cuit')) {
            $cuit = $request->input('afip_cuit');
            $cleanCuit = ($cuit !== null && trim($cuit) !== '') ? preg_replace('/\D/', '', trim($cuit)) : null;
            $oldCuit = BusinessSetting::where('key', 'afip_cuit')->value('value');

            BusinessSetting::updateOrCreate(
                ['key' => 'afip_cuit'],
                ['value' => ($cleanCuit !== null && $cleanCuit !== '') ? $cleanCuit : null]
            );

            // Si el CUIT cambió, sincronizar rutas y certificados con el nuevo CUIT
            if ($cleanCuit !== $oldCuit) {
                if ($cleanCuit && file_exists(storage_path("app/private/afip/{$cleanCuit}/cert.crt"))) {
                    BusinessSetting::setSecret('afip_cert_path', "afip/{$cleanCuit}/cert.crt");
                    BusinessSetting::setSecret('afip_key_path', "afip/{$cleanCuit}/cert.key");
                    $certContent = @file_get_contents(storage_path("app/private/afip/{$cleanCuit}/cert.crt"));
                    if ($certContent && ($parsed = @openssl_x509_parse($certContent)) && ! empty($parsed['validTo_time_t'])) {
                        BusinessSetting::updateOrCreate(['key' => 'afip_cert_expires_at'], ['value' => date('Y-m-d H:i:s', $parsed['validTo_time_t'])]);
                    }
                } else {
                    BusinessSetting::whereIn('key', ['afip_cert_path', 'afip_key_path', 'afip_cert_expires_at'])->delete();
                }
            }
        }

        if ($request->has('afip_pto_vta')) {
            $ptoVta = $request->input('afip_pto_vta');
            BusinessSetting::updateOrCreate(
                ['key' => 'afip_pto_vta'],
                ['value' => ($ptoVta !== null && $ptoVta !== '' && is_numeric($ptoVta) && (int) $ptoVta > 0) ? (string) (int) $ptoVta : null]
            );
        }

        if ($request->has('afip_environment')) {
            $env = $request->input('afip_environment');
            BusinessSetting::updateOrCreate(
                ['key' => 'afip_environment'],
                ['value' => in_array($env, ['testing', 'production']) ? $env : 'testing']
            );
        }

        return response()->json([
            'message' => 'Configuración de integraciones actualizada correctamente.',
        ]);
    }

    /**
     * Valida la conectividad con la API de Mercado Pago usando el access token guardado o proporcionado.
     * Retorna el estado sin exponer secretos ni logs crudos de error.
     */
    public function testMercadoPagoConnection(Request $request)
    {
        $request->validate([
            'mp_access_token' => 'nullable|string',
        ]);

        $inputToken = $request->input('mp_access_token');

        // Si se provee un token en el request:
        // - Si contiene máscara de asteriscos, preservar y usar el secreto guardado en BD.
        // - Si no está enmascarado, usar el token del request (limpiando espacios).
        // - Si no se incluyó en el request (omitido), usar el secreto guardado en BD.
        if ($request->has('mp_access_token') && ! str_contains((string) $inputToken, '****')) {
            $token = trim((string) $inputToken);
        } else {
            $token = BusinessSetting::getSecret('mp_access_token');
        }

        if (empty($token) || trim($token) === '') {
            return response()->json([
                'success' => false,
                'message' => 'No hay un Access Token de Mercado Pago configurado.',
            ], 422);
        }

        $token = trim($token);

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://api.mercadopago.com/users/me');

            if ($response->successful()) {
                $userData = $response->json();

                return response()->json([
                    'success' => true,
                    'message' => 'Conexión con Mercado Pago exitosa.',
                    'collector_id' => $userData['id'] ?? null,
                    'nickname' => $userData['nickname'] ?? null,
                ]);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return response()->json([
                    'success' => false,
                    'message' => 'El Access Token de Mercado Pago es inválido o no tiene permisos suficientes.',
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Mercado Pago respondió con error (Código: '.$response->status().').',
            ], 400);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con los servidores de Mercado Pago.',
            ], 400);
        }
    }

    /**
     * Valida y almacena los certificados X.509 y claves privadas para ARCA (AFIP).
     * Verifica criptográficamente que la clave privada coincida con el certificado.
     */
    public function uploadAfipCertificates(UploadAfipCertificatesRequest $request, AfipCertificateService $certService)
    {
        $certContent = null;
        if ($request->hasFile('cert_file')) {
            $certContent = file_get_contents($request->file('cert_file')->getRealPath());
        } elseif ($request->hasFile('cert')) {
            $certContent = file_get_contents($request->file('cert')->getRealPath());
        } elseif ($request->filled('cert_content')) {
            $certContent = $request->input('cert_content');
        } elseif ($request->filled('cert')) {
            $certContent = $request->input('cert');
        }

        $keyContent = null;
        if ($request->hasFile('key_file')) {
            $keyContent = file_get_contents($request->file('key_file')->getRealPath());
        } elseif ($request->hasFile('key')) {
            $keyContent = file_get_contents($request->file('key')->getRealPath());
        } elseif ($request->filled('key_content')) {
            $keyContent = $request->input('key_content');
        } elseif ($request->filled('key')) {
            $keyContent = $request->input('key');
        }

        $passphrase = $request->input('key_passphrase', '') ?? '';

        try {
            $result = $certService->validateAndStore(
                $request->input('cuit'),
                $certContent,
                $keyContent,
                $passphrase
            );

            return response()->json(array_merge([
                'message' => 'Certificados de AFIP guardados y validados correctamente.',
            ], $result));
        } catch (DomainException $e) {
            [$code, $message] = explode(':', $e->getMessage(), 2);

            return response()->json([
                'message' => $message,
                'error_code' => $code,
            ], 422);
        }
    }

    /**
     * Sube y almacena el logotipo del negocio.
     */
    public function uploadLogo(UploadLogoRequest $request)
    {
        $file = $request->file('logo');
        $filename = 'logo_'.time().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('business', $filename, 'public');

        // Eliminar logo previo huérfano si existía (F2-H01)
        $oldLogo = BusinessSetting::where('key', 'logo_path')->value('value');
        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        BusinessSetting::updateOrCreate(
            ['key' => 'logo_path'],
            ['value' => $path]
        );

        return response()->json([
            'message' => 'Logotipo guardado correctamente.',
            'logo_path' => asset('storage/'.$path),
        ]);
    }

    /**
     * Validar y activar una clave de licencia manualmente (Render/Supabase)
     */
    public function updateLicense(Request $request, LicenseSyncService $licenseService)
    {
        $request->validate([
            'license_key' => 'required|string|max:50',
        ]);

        try {
            $plan = $licenseService->activateManual($request->license_key);

            return response()->json([
                'message' => 'Licencia validada correctamente. Plan activado: '.strtoupper($plan),
                'plan' => $plan,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400); // 400 Bad Request if invalid or no connection
        }
    }

    /**
     * Sincronización manual forzada desde la interfaz
     */
    public function syncLicense(LicenseSyncService $licenseService)
    {
        try {
            $licenseService->syncManualForce();

            return response()->json([
                'message' => 'Permisos de licencia sincronizados correctamente.',
                'settings' => BusinessSetting::whereNotIn('key', BusinessSetting::SENSITIVE_KEYS)->pluck('value', 'key'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400); // 400 Bad Request
        }
    }

    /**
     * Enmascara un token sensible para no exponerlo en plano (ej: APP_USR-****...a1b2)
     */
    protected function maskToken(?string $token): ?string
    {
        if (empty($token) || trim($token) === '') {
            return null;
        }

        $token = trim($token);
        $length = strlen($token);
        if ($length <= 8) {
            return '****';
        }

        if (str_starts_with($token, 'APP_USR-')) {
            $prefix = 'APP_USR-';
            $suffix = substr($token, -4);

            return $prefix.'****'.$suffix;
        }

        $prefix = substr($token, 0, 4);
        $suffix = substr($token, -4);

        return $prefix.'****'.$suffix;
    }
}
