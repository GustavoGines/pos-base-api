<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ValidateSessionToken
 *
 * Middleware de Single Active Session.
 *
 * Extrae el header X-Session-Token de cada request y verifica que exista
 * en la tabla users. Si no existe (fue sobrescrito por otro login o nullificado
 * por logout), responde 401 con un mensaje específico para que el cliente Flutter
 * pueda distinguirlo de otros errores y forzar la pantalla de Login.
 *
 * Si el header no viene (ej: rutas públicas que usan este middleware por error
 * de configuración), también responde 401.
 */
class ValidateSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Session-Token');

        if (!$token) {
            return response()->json([
                'message'    => 'No autenticado. Por favor inicie sesión.',
                'error_code' => 'SESSION_MISSING',
            ], 401);
        }

        $user = User::where('session_token', $token)->first();

        if (!$user) {
            return response()->json([
                'message'    => 'Tu sesión fue cerrada porque otro dispositivo inició sesión con tu usuario.',
                'error_code' => 'SESSION_EXPIRED',
            ], 401);
        }

        // Adjuntar el usuario resuelto al request para que los controladores
        // puedan usarlo si lo necesitan (ej: auditoría, logs)
        $request->attributes->set('authenticated_user', $user);

        return $next($request);
    }
}
