<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Services\LicenseSyncService;
use Illuminate\Http\Request;

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

        if (!empty($settings['logo_path'])) {
            $settings['logo_url'] = asset('storage/' . $settings['logo_path']);
        }

        // Agregar metadata dinámica para el DRM Heartbeat
        $settings['server_time'] = now()->toIso8601String();
        $settings['grace_period_hours'] = 72;

        return response()->json($settings);
    }

    /**
     * Actualiza o crea múltiples configuraciones.
     * Encripta claves sensibles y previene la sobreescritura destructiva de tokens enmascarados.
     */
    public function update(Request $request)
    {
        // Validación explícita para los campos de porcentaje del motor global de precios.
        // El resto de claves son libres (arquitectura genérica key-value).
        $request->validate([
            'card_percentage' => 'sometimes|numeric|between:-100,100',
            'wholesale_percentage' => 'sometimes|numeric|between:-100,100',
        ]);

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

        $hasCert = false;
        $hasKey = false;
        $certExpiresAt = BusinessSetting::where('key', 'afip_cert_expires_at')->value('value');

        if (!empty($afipCuit)) {
            $certPath = storage_path("app/private/afip/{$afipCuit}/cert.crt");
            $keyPath = storage_path("app/private/afip/{$afipCuit}/cert.key");
            $hasCert = file_exists($certPath);
            $hasKey = file_exists($keyPath);

            if ($hasCert && empty($certExpiresAt)) {
                $certContent = @file_get_contents($certPath);
                if ($certContent) {
                    $parsed = @openssl_x509_parse($certContent);
                    if (!empty($parsed['validTo_time_t'])) {
                        $certExpiresAt = date('Y-m-d H:i:s', $parsed['validTo_time_t']);
                    }
                }
            }
        }

        $maskedMpToken = $this->maskToken($rawMpToken);
        $maskedMpSecret = $this->maskToken($rawMpSecret);

        $payload = [
            'mp_qr_enabled' => (bool) $mpQrEnabled,
            'mp_point_device_id' => $mpPointDeviceId,
            'mp_access_token' => $maskedMpToken,
            'mp_webhook_secret' => $maskedMpSecret,
            'mp_has_access_token' => !empty($rawMpToken),
            'mp_has_webhook_secret' => !empty($rawMpSecret),
            'afip_enabled' => (bool) $afipEnabled,
            'afip_cuit' => $afipCuit,
            'afip_pto_vta' => $afipPtoVta !== null ? (int) $afipPtoVta : null,
            'afip_environment' => $afipEnvironment,
            'afip_has_cert' => $hasCert,
            'afip_has_key' => $hasKey,
            'afip_cert_expires_at' => $certExpiresAt,
            'mercado_pago' => [
                'mp_qr_enabled' => (bool) $mpQrEnabled,
                'mp_point_device_id' => $mpPointDeviceId,
                'mp_access_token' => $maskedMpToken,
                'mp_webhook_secret' => $maskedMpSecret,
                'mp_has_access_token' => !empty($rawMpToken),
                'mp_has_webhook_secret' => !empty($rawMpSecret),
            ],
            'afip' => [
                'afip_enabled' => (bool) $afipEnabled,
                'afip_cuit' => $afipCuit,
                'afip_pto_vta' => $afipPtoVta !== null ? (int) $afipPtoVta : null,
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
    public function updateIntegrations(Request $request)
    {
        $request->validate([
            'mp_qr_enabled' => 'sometimes',
            'mp_point_device_id' => 'nullable|string|max:100',
            'mp_access_token' => 'nullable|string',
            'mp_webhook_secret' => 'nullable|string',
            'afip_enabled' => 'sometimes',
            'afip_cuit' => 'nullable|string|max:20',
            'afip_pto_vta' => 'nullable|integer',
            'afip_environment' => 'nullable|in:testing,production',
        ]);

        if ($request->has('mp_access_token')) {
            $token = $request->input('mp_access_token');
            if ($token !== null && str_contains($token, '****')) {
                // Preservar secreto existente
            } elseif (!empty($token)) {
                BusinessSetting::setSecret('mp_access_token', $token);
            } else {
                BusinessSetting::setSecret('mp_access_token', null);
            }
        }

        if ($request->has('mp_webhook_secret')) {
            $secret = $request->input('mp_webhook_secret');
            if ($secret !== null && str_contains($secret, '****')) {
                // Preservar secreto existente
            } elseif (!empty($secret)) {
                BusinessSetting::setSecret('mp_webhook_secret', $secret);
            } else {
                BusinessSetting::setSecret('mp_webhook_secret', null);
            }
        }

        if ($request->has('mp_qr_enabled')) {
            $val = $request->boolean('mp_qr_enabled') ? '1' : '0';
            BusinessSetting::updateOrCreate(['key' => 'mp_qr_enabled'], ['value' => $val]);
        }

        if ($request->has('mp_point_device_id')) {
            BusinessSetting::updateOrCreate(
                ['key' => 'mp_point_device_id'],
                ['value' => $request->input('mp_point_device_id')]
            );
        }

        if ($request->has('afip_enabled')) {
            $val = $request->boolean('afip_enabled') ? '1' : '0';
            BusinessSetting::updateOrCreate(['key' => 'afip_enabled'], ['value' => $val]);
        }

        if ($request->has('afip_cuit')) {
            BusinessSetting::updateOrCreate(
                ['key' => 'afip_cuit'],
                ['value' => $request->input('afip_cuit')]
            );
        }

        if ($request->has('afip_pto_vta')) {
            BusinessSetting::updateOrCreate(
                ['key' => 'afip_pto_vta'],
                ['value' => (string) $request->input('afip_pto_vta')]
            );
        }

        if ($request->has('afip_environment')) {
            BusinessSetting::updateOrCreate(
                ['key' => 'afip_environment'],
                ['value' => $request->input('afip_environment')]
            );
        }

        return response()->json([
            'message' => 'Configuración de integraciones actualizada correctamente.',
        ]);
    }

    /**
     * Valida y almacena los certificados X.509 y claves privadas para ARCA (AFIP).
     * Verifica criptográficamente que la clave privada coincida con el certificado.
     */
    public function uploadAfipCertificates(Request $request)
    {
        $request->validate([
            'cuit' => 'required|string',
            'cert_file' => 'nullable|file',
            'key_file' => 'nullable|file',
            'cert_content' => 'nullable|string',
            'key_content' => 'nullable|string',
            'cert' => 'nullable',
            'key' => 'nullable',
            'key_passphrase' => 'nullable|string',
        ]);

        $cuit = preg_replace('/\D/', '', $request->input('cuit') ?? '');
        if (empty($cuit)) {
            return response()->json([
                'message' => 'El CUIT es obligatorio y debe contener dígitos numéricos.',
                'error_code' => 'INVALID_CUIT',
            ], 422);
        }

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

        if (empty($certContent) || empty($keyContent)) {
            return response()->json([
                'message' => 'Debe adjuntar tanto el certificado (.crt) como la clave privada (.key).',
                'error_code' => 'MISSING_CERT_OR_KEY',
            ], 422);
        }

        $x509 = @openssl_x509_read($certContent);
        if (! $x509) {
            return response()->json([
                'message' => 'El certificado X.509 es inválido o no pudo ser interpretado.',
                'error_code' => 'INVALID_CERTIFICATE',
            ], 422);
        }

        $certData = @openssl_x509_parse($x509);
        $passphrase = $request->input('key_passphrase', '') ?? '';
        $pkey = @openssl_pkey_get_private($keyContent, $passphrase);
        if (! $pkey) {
            return response()->json([
                'message' => 'La clave privada es inválida o la contraseña de desbloqueo es incorrecta.',
                'error_code' => 'INVALID_PRIVATE_KEY',
            ], 422);
        }

        if (! @openssl_x509_check_private_key($x509, $pkey)) {
            return response()->json([
                'message' => 'El par criptográfico no coincide: el certificado no corresponde a la clave privada.',
                'error_code' => 'CERT_KEY_MISMATCH',
            ], 422);
        }

        $targetDir = storage_path('app/private/afip/' . $cuit);
        if (! file_exists($targetDir)) {
            mkdir($targetDir, 0700, true);
        }

        $certPath = $targetDir . DIRECTORY_SEPARATOR . 'cert.crt';
        $keyPath = $targetDir . DIRECTORY_SEPARATOR . 'cert.key';

        file_put_contents($certPath, $certContent);
        file_put_contents($keyPath, $keyContent);

        @chmod($certPath, 0600);
        @chmod($keyPath, 0600);

        $expiresAt = null;
        if (! empty($certData['validTo_time_t'])) {
            $expiresAt = date('Y-m-d H:i:s', $certData['validTo_time_t']);
        }

        BusinessSetting::updateOrCreate(['key' => 'afip_cuit'], ['value' => $cuit]);
        BusinessSetting::updateOrCreate(['key' => 'afip_cert_path'], ['value' => "afip/{$cuit}/cert.crt"]);
        BusinessSetting::updateOrCreate(['key' => 'afip_key_path'], ['value' => "afip/{$cuit}/cert.key"]);
        if ($expiresAt) {
            BusinessSetting::updateOrCreate(['key' => 'afip_cert_expires_at'], ['value' => $expiresAt]);
        }

        return response()->json([
            'message' => 'Certificados de AFIP guardados y validados correctamente.',
            'afip_cuit' => $cuit,
            'afip_has_cert' => true,
            'afip_has_key' => true,
            'afip_cert_expires_at' => $expiresAt,
        ]);
    }

    /**
     * Sube y almacena el logotipo del negocio.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        $file = $request->file('logo');
        $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('business', $filename, 'public');

        BusinessSetting::updateOrCreate(
            ['key' => 'logo_path'],
            ['value' => $path]
        );

        return response()->json([
            'message' => 'Logotipo guardado correctamente.',
            'logo_path' => asset('storage/' . $path)
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
        if (empty($token)) {
            return null;
        }

        $length = strlen($token);
        if ($length <= 8) {
            return '****';
        }

        if (str_starts_with($token, 'APP_USR-')) {
            $prefix = 'APP_USR-';
            $suffix = substr($token, -4);
            return $prefix . '****' . $suffix;
        }

        $prefix = substr($token, 0, 4);
        $suffix = substr($token, -4);
        return $prefix . '****' . $suffix;
    }
}

