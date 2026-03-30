<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\CashShiftService;

class CheckAddonPermission
{
    protected CashShiftService $cashShiftService;

    public function __construct(CashShiftService $cashShiftService)
    {
        $this->cashShiftService = $cashShiftService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $addon): Response
    {
        // En nuestro dominio, validamos si existe el permiso específico
        if ($addon === 'multi_caja' && !$this->cashShiftService->hasMultiCajaPermission()) {
            return response()->json([
                'message' => 'Acceso Denegado. Se requiere el Addon "Multi-Caja".',
                'error_code' => 'LICENSE_UPGRADE_REQUIRED'
            ], 403);
        }

        // Futuro: agregar otros addons como "cloud_sync", "advanced_reports"

        return $next($request);
    }
}
