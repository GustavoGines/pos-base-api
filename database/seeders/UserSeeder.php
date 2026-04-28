<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Usuario Administrador por defecto
        // PIN: 1234  |  Password: admin1234
        // ⚠️ El cliente DEBE cambiar el PIN desde Ajustes > Usuarios al primer inicio.
        User::updateOrCreate(
            ['email' => 'admin@pos.com'],
            [
                'name'        => 'Administrador',
                'role'        => 'admin',
                'pin'         => Hash::make('1234'),
                'password'    => Hash::make('admin1234'),
                'permissions' => json_encode(['void_sales', 'manage_catalog', 'adjust_stock', 'view_global_history']),
            ]
        );
    }
}
