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
}
