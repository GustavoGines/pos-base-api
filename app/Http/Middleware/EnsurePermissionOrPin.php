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
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$permissions  Uno o más permisos requeridos (condición OR) o 'admin_only'
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        // 1. Obtener usuario previamente autenticado por ValidateSessionToken o Sanctum
        /** @var User|null $user */
        $user = $request->user() ?? $request->attributes->get('authenticated_user');

        $fallbackToken = $request->header('X-Session-Token') ?? $request->bearerToken();
        if (! $fallbackToken && $request->isMethod('GET') && (
            $request->is('api/catalog/products/import-template') ||
            $request->is('api/catalog/products/export') ||
            $request->is('api/cash-movements/export') ||
            $request->is('api/sales/*/ticket-pdf') ||
            $request->is('api/reports/*/export') ||
            $request->is('api/reports/*/pdf') ||
            $request->is('api/system/backup')
        )) {
            $fallbackToken = $request->query('token');
        }

        if (! $user && $fallbackToken) {
            $user = User::withoutGlobalScope('visible')
                ->where('session_token', $fallbackToken)
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

            // FINDING-SALES-01: Permitir rollback automático inmediato al cajero ante rechazo duro 422 de AFIP
            if (in_array('void_sales', $permissions, true) && $this->isAuthorizedAutomaticFiscalRollback($request, $user)) {
                return $next($request);
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
            $throttleKey = 'pin_attempts:'.($user ? $user->id : 'guest').':'.$request->ip();
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

    /**
     * FINDING-SALES-01: Verifica si la solicitud de anulación corresponde a un rollback automático
     * inmediato tras un rechazo fiscal 422 de AFIP (invoice_status === 'failed', sin CAE y dentro de
     * los 180s posteriores a la creación de la venta).
     */
    private function isAuthorizedAutomaticFiscalRollback(Request $request, User $user): bool
    {
        if (! $request->is('api/sales/*/void') || ! $request->boolean('fiscal_rollback')) {
            return false;
        }

        $routeSale = $request->route('sale');
        $sale = $routeSale instanceof \App\Models\Sale
            ? $routeSale
            : (is_numeric($routeSale) ? \App\Models\Sale::with('electronicInvoice')->find((int) $routeSale) : null);

        if (! $sale) {
            return false;
        }

        $sale->loadMissing('electronicInvoice');

        if ($sale->status !== 'completed' || $sale->invoice_status !== 'failed') {
            return false;
        }

        if ($sale->electronicInvoice && ! empty($sale->electronicInvoice->cae)) {
            return false;
        }

        // Se mide desde created_at (inmutable) y no desde updated_at: de lo contrario, re-facturar una
        // venta antigua hasta que falle (POST /sales/{sale}/invoice no exige permiso) reabriría la
        // ventana y permitiría anular ventas viejas sin PIN. 180s cubre el peor caso WSAA+WSFE (~50s).
        $referenceTime = $sale->created_at;
        if (! $referenceTime || abs($referenceTime->diffInSeconds(now())) > 180) {
            return false;
        }

        $matchesUser = (int) $sale->cashier_id === (int) $user->id || (int) $sale->user_id === (int) $user->id;
        $matchesShift = $request->filled('cash_shift_id') && (int) $sale->cash_shift_id === (int) $request->input('cash_shift_id');

        return $matchesUser || $matchesShift;
    }
}
