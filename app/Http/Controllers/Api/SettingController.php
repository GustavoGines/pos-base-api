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
        $settings = BusinessSetting::all()->pluck('value', 'key');
        
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
            BusinessSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }

        return response()->json([
            'message' => 'Configuración actualizada correctamente.',
            'settings' => BusinessSetting::all()->pluck('value', 'key')
        ]);
    }
}
