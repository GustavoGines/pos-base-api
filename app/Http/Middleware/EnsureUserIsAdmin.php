<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // El middleware session.validate ya debió ejecutarse y establecer el usuario en request attributes
        $user = $request->attributes->get('authenticated_user');

        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Acceso denegado: Se requieren permisos de administrador'
            ], 403);
        }

        return $next($request);
    }
}
