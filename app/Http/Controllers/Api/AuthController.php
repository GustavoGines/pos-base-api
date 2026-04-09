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

        $users = User::all();

        foreach ($users as $user) {
            if ($user->pin && Hash::check($request->input('pin'), $user->pin)) {

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
        }

        return response()->json(['message' => 'PIN incorrecto o usuario no encontrado.'], 401);
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

        $users = User::all();

        foreach ($users as $user) {
            if ($user->pin && Hash::check($request->input('pin'), $user->pin)) {
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
        }

        return response()->json([
            'authorized' => false,
            'message'    => 'PIN incorrecto o usuario no encontrado.',
        ], 401);
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
