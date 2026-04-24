<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /api/users — Lista todos los usuarios (sin PIN ni password)
     */
    public function index()
    {
        $users = User::select('id', 'name', 'email', 'role', 'permissions')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * POST /api/users — Crear nuevo empleado
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'role'        => 'required|in:admin,cashier',
            'pin'         => 'required|string|size:4|regex:/^[0-9]{4}$/',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:void_sales,manage_catalog,adjust_stock,view_global_history',
        ]);

        // Generar email único interno basado en el nombre
        $baseEmail = strtolower(str_replace(' ', '.', $validated['name'])) . '@pos.internal';
        $email = $baseEmail;
        $i = 1;
        while (User::where('email', $email)->exists()) {
            $email = $baseEmail . '.' . $i++;
        }

        // Verificar que el PIN no exista en ningún otro usuario
        $pinExists = User::whereNotNull('pin')->get()->first(function ($u) use ($validated) {
            return Hash::check($validated['pin'], $u->pin);
        });

        if ($pinExists) {
            return response()->json([
                'message' => 'El PIN ya está en uso por otro empleado. Por favor elegí uno diferente.',
                'errors'  => ['pin' => ['El PIN ya está en uso.']],
            ], 422);
        }

        $user = User::create([
            'name'        => $validated['name'],
            'email'       => $email,
            'password'    => Hash::make('pos_internal_' . $validated['pin']),
            'role'        => $validated['role'],
            'pin'         => Hash::make($validated['pin']),
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return response()->json([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'permissions' => $user->permissions ?? [],
        ], 201);
    }

    /**
     * PUT /api/users/{id} — Actualizar empleado
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'role'        => 'required|in:admin,cashier',
            'pin'         => 'nullable|string|size:4|regex:/^[0-9]{4}$/',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:void_sales,manage_catalog,adjust_stock,view_global_history',
        ]);

        $data = [
            'name'        => $validated['name'],
            'role'        => $validated['role'],
            'permissions' => $validated['permissions'] ?? [],
        ];

        // Si viene nuevo PIN, verificar que no lo use ya otro usuario distinto
        if (!empty($validated['pin'])) {
            $pinExists = User::where('id', '!=', $user->id)->whereNotNull('pin')->get()->first(function ($u) use ($validated) {
                return Hash::check($validated['pin'], $u->pin);
            });

            if ($pinExists) {
                return response()->json([
                    'message' => 'El PIN ya está en uso por otro empleado. Por favor elegí uno diferente.',
                    'errors'  => ['pin' => ['El PIN ya está en uso.']],
                ], 422);
            }

            $data['pin'] = Hash::make($validated['pin']);
        }

        $user->update($data);

        return response()->json([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'permissions' => $user->permissions ?? [],
        ]);
    }

    /**
     * DELETE /api/users/{id} — Eliminar empleado
     */
    public function destroy(Request $request, User $user)
    {
        // FIX U-3: El current_user_id venía del body del request y era bypasseable.
        // Ahora usamos el token del header para identificar fehacientemente al actor.
        $actingUser = User::where('session_token', $request->header('X-Session-Token'))->first();

        if ($actingUser && $actingUser->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propio perfil.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Empleado eliminado correctamente.']);
    }
}
