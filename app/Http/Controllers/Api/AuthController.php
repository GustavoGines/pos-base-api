<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/auth/verify-pin
     *
     * Login completo: valida el PIN, genera un nuevo session_token UUID,
     * lo guarda en BD (sobreescribiendo el anterior -> mata la sesión vieja
     * en cualquier otra terminal), y lo devuelve al cliente.
     *
     * Este es el ÚNICO endpoint que emite tokens y que invalida sesiones previas.
     */
    public function verifyPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:10',
        ]);

        $pin = $request->input('pin');

        // FIX BUG A-1: Busca usuario por PIN hasheado sin cargar todos a memoria.
        // Iteramos solo los usuarios con PIN registrado para minimizar surface de ataque.
        $user = User::withoutGlobalScope('visible')
            ->whereNotNull('pin')
            ->get()
            ->first(fn ($u) => Hash::check($pin, $u->pin));

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'PIN incorrecto o usuario no encontrado.'], 401);
        }

        // Generar token único de sesión (64 chars hex = 256 bits de entropía)
        $token = Str::random(64);

        // Guardar en BD: sobrescribe la sesión anterior -> Single Active Session
        $user->update(['session_token' => $token]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
            ],
            'session_token' => $token,
            'requires_pin_change' => false,   // <- Login normal
        ]);
    }

    /**
     * POST /api/auth/authorize-pin
     *
     * Verificación de PIN para AUTORIZACIÓN PUNTUAL (ej: AdminPinDialog para anulaciones).
     * NO genera token. NO toca la columna session_token en BD.
     * NO invalida ninguna sesión activa.
     *
     * Solo confirma: "¿Existe un admin con este PIN?" y devuelve sus permisos.
     * Usar exclusivamente para flujos de autorización in-app, nunca para login.
     */
    public function authorizePin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:10',
        ]);

        $pin = $request->input('pin');

        // FIX BUG A-1: Busca solo entre usuarios con PIN registrado.
        $user = User::withoutGlobalScope('visible')
            ->whereNotNull('pin')
            ->get()
            ->first(fn ($u) => Hash::check($pin, $u->pin));

        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'authorized' => false,
                'message' => 'PIN incorrecto o permisos insuficientes.',
            ], 401);
        }

        return response()->json([
            'authorized' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
            ],
        ]);
    }

    /**
     * GET /api/auth/me
     *
     * Valida que el token restaurado desde SharedPreferences todavía sea válido
     * en la BD. Se llama al arranque de la app para evitar operar con un token
     * stale (de una sesión anterior ya invalidada por otro login).
     *
     * Responde 200 + datos del usuario si el token es válido.
     * Responde 401 SESSION_EXPIRED si el token no existe en BD.
     * NO requiere middleware: funciona con token en header X-Session-Token.
     */
    public function me(Request $request)
    {
        $token = $request->header('X-Session-Token');

        if (! $token) {
            return response()->json([
                'message' => 'No autenticado.',
                'error_code' => 'SESSION_MISSING',
            ], 401);
        }

        $user = User::withoutGlobalScope('visible')->where('session_token', $token)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Tu sesión fue cerrada porque otro dispositivo inició sesión con tu usuario.',
                'error_code' => 'SESSION_EXPIRED',
            ], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     *
     * Invalida la sesión activa del usuario: pone session_token = NULL en BD.
     * El cliente debe mandar su token actual en el header X-Session-Token.
     * Si el token no existe (ya fue invalidado), igualmente responde 200 (idempotente).
     */
    public function logout(Request $request)
    {
        $token = $request->header('X-Session-Token');

        if ($token) {
            User::withoutGlobalScope('visible')->where('session_token', $token)->update(['session_token' => null]);
        }

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
