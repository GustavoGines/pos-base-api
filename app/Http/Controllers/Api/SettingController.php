<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Devuelve todas las configuraciones del negocio como un mapa key-value
     */
    public function index()
    {
        $settings = BusinessSetting::all()->pluck('value', 'key')->toArray();
        
        // Agregar metadata dinámica para el DRM Heartbeat
        $settings['server_time'] = now()->toIso8601String();
        $settings['grace_period_hours'] = 72;
        
        return response()->json($settings);
    }

    /**
     * Actualiza o crea múltiples configuraciones
     * Recibe un JSON tipo: {"company_name": "MyR", "printer_type": "usb", ...}
     */
    public function update(Request $request)
    {
        $data = $request->all();

        foreach ($data as $key => $value) {
            // Si el valor es un array u objeto (ej: custom_price_tiers), serializar a JSON string
            $storedValue = is_array($value) ? json_encode($value) : $value;
            
            BusinessSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue]
            );
        }

        return response()->json([
            'message' => 'Configuración actualizada correctamente.',
            'settings' => BusinessSetting::all()->pluck('value', 'key')
        ]);
    }

    /**
     * Validar y activar una clave de licencia manualmente (Render/Supabase)
     */
    public function updateLicense(Request $request, \App\Services\LicenseSyncService $licenseService)
    {
        $request->validate([
            'license_key' => 'required|string|max:50',
        ]);

        try {
            $plan = $licenseService->activateManual($request->license_key);
            return response()->json([
                'message' => "Licencia validada correctamente. Plan activado: " . strtoupper($plan),
                'plan' => $plan
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400); // 400 Bad Request if invalid or no connection
        }
    }

    /**
     * Sincronización manual forzada desde la interfaz
     */
    public function syncLicense(\App\Services\LicenseSyncService $licenseService)
    {
        try {
            $licenseService->syncManualForce();
            return response()->json([
                'message' => "Permisos de licencia sincronizados correctamente.",
                'settings' => BusinessSetting::all()->pluck('value', 'key')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400); // 400 Bad Request
        }
    }
}
