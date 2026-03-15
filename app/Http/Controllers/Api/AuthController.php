<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Verifica el PIN de un usuario y retorna sus datos si es correcto.
     */
    public function verifyPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:10',
        ]);

        $pin = $request->input('pin');

        // Buscar todos los usuarios (en un sistema con pocos cajeros esto es válido para verificar el hash,
        // aunque de forma óptima el usuario seleccionaría su nombre antes. 
        // Asumiendo que el PIN es como una credencial maestra única)
        $users = User::all();

        foreach ($users as $user) {
            if ($user->pin && Hash::check($pin, $user->pin)) {
                return response()->json([
                    'user' => [
                        'id'          => $user->id,
                        'name'        => $user->name,
                        'email'       => $user->email,
                        'role'        => $user->role,
                        'permissions' => $user->permissions ?? [],
                    ]
                ]);
            }
        }

        return response()->json(['message' => 'PIN incorrecto o usuario no encontrado.'], 401);
    }
}
