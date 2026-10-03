<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermissionOrPin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permissions  Uno o más permisos requeridos (condición OR) o 'admin_only'
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        // 1. Obtener usuario previamente autenticado por ValidateSessionToken o Sanctum
        /** @var \App\Models\User|null $user */
        $user = $request->user() ?? $request->attributes->get('authenticated_user');

        if (! $user && $request->header('X-Session-Token')) {
            $user = User::withoutGlobalScope('visible')
                ->where('session_token', $request->header('X-Session-Token'))
                ->first();
            if ($user) {
                $request->attributes->set('authenticated_user', $user);
                $request->setUserResolver(fn () => $user);
                Auth::setUser($user);
            }
        }

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado. Se requiere una sesión válida.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // 2. Si el usuario es Administrador o posee el comodín maestro 'all', autorizar inmediatamente
        if ($user->isAdmin() || $user->hasPermission('all')) {
            return $next($request);
        }

        // 3. Evaluar si el usuario cuenta con alguno de los permisos granulares requeridos
        $requiresStrictAdmin = in_array('admin_only', $permissions, true) || empty($permissions);
        if (! $requiresStrictAdmin) {
            foreach ($permissions as $perm) {
                if ($user->hasPermission($perm)) {
                    // Autorizado por perfil legítimo: no requiere PIN de supervisor
                    return $next($request);
                }
            }
        }

        $rawHeaderPin = $request->header('X-Admin-Pin');
        $rawBodyPin = $request->input('admin_pin');
        $adminPin = (is_string($rawHeaderPin) && trim($rawHeaderPin) !== '')
            ? trim($rawHeaderPin)
            : ((is_string($rawBodyPin) && trim($rawBodyPin) !== '') ? trim($rawBodyPin) : null);

        if ($adminPin !== null) {
            // Protección contra Fuerza Bruta y DoS por Bcrypt (Compound Rate Limiting)
            // Se aísla el throttle por ID de usuario autenticado e IP.
            // Si múltiples terminales POS operan tras la misma red NAT/router,
            // 5 intentos fallidos en Terminal 1 NO bloquean la Terminal 2 ni al resto del local.
            $throttleKey = 'pin_attempts:' . ($user ? $user->id : 'guest') . ':' . $request->ip();
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);

                return response()->json([
                    'message' => "Demasiados intentos erróneos de PIN. Operación bloqueada temporalmente por {$seconds} segundos.",
                    'error_code' => 'PIN_LOCKED_TEMPORARILY',
                    'retry_after' => $seconds,
                ], 429);
            }

            // CRÍTICO: withoutGlobalScope('visible') asegura que el usuario soporte (is_system=true) sea verificado
            $admins = User::withoutGlobalScope('visible')
                ->where('role', 'admin')
                ->whereNotNull('pin')
                ->get();

            foreach ($admins as $admin) {
                if (Hash::check($adminPin, $admin->pin)) {
                    RateLimiter::clear($throttleKey);

                    // Inyectar metadatos forenses en el Request
                    $request->attributes->set('authorized_by_admin_id', $admin->id);
                    $request->attributes->set('authorized_by_admin', $admin);
                    $request->merge(['authorized_by_admin_id' => $admin->id]);

                    return $next($request);
                }
            }

            // Registro de intento fallido (penalización de 5 minutos tras 5 fallos consecutivos)
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'message' => 'El PIN de administrador ingresado es incorrecto.',
                'error_code' => 'INVALID_ADMIN_PIN',
            ], 403);
        }

        // 5. Rechazo ordenado e instructivo para el cliente Flutter
        $requiredLabel = empty($permissions)
            ? 'admin'
            : (count($permissions) === 1 ? $permissions[0] : implode('|', $permissions));

        return response()->json([
            'message' => "Acceso denegado: Se requiere el permiso '{$requiredLabel}' o autorización mediante PIN de administrador.",
            'error_code' => 'PIN_REQUIRED',
            'required_permissions' => $permissions,
        ], 403);
    }
}
