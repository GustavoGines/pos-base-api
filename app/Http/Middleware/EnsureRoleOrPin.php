<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleOrPin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Obtener usuario autenticado (del token de sesión principal)
        $user = $request->attributes->get('authenticated_user');
        
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // 2. Si es admin directamente, pasa sin problema
        if ($user->role === 'admin') {
            return $next($request);
        }

        // 3. Si no es admin, buscar el header X-Admin-Pin
        $adminPin = $request->header('X-Admin-Pin');
        
        if ($adminPin) {
            // Validar que el PIN corresponda a algun administrador
            // NOTA: Como la validación en Flutter no pasa el ID del admin, 
            // buscamos el primer admin que coincida. En un sistema muy grande 
            // sería mejor enviar X-Admin-Id y X-Admin-Pin.
            $admins = User::where('role', 'admin')->whereNotNull('pin')->get();
            
            foreach ($admins as $admin) {
                if (Hash::check($adminPin, $admin->pin)) {
                    // PIN válido. Inyectamos al autorizador en los atributos
                    $request->attributes->set('authorized_by_admin_id', $admin->id);
                    return $next($request);
                }
            }
        }

        // 4. Si llegó acá, es cajero y no tiene PIN o el PIN es inválido
        return response()->json([
            'message' => 'Acceso denegado: Se requieren permisos de administrador o PIN válido.'
        ], 403);
    }
}
