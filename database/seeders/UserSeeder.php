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
                'permissions' => ['void_sales', 'manage_catalog', 'adjust_stock', 'view_global_history'],
            ]
        );

        // Administrador Móvil para App (solicitado por cliente)
        // PIN: 5678 | Password: adminmovil5678
        User::updateOrCreate(
            ['email' => 'adminmovil@pos.com'],
            [
                'name'        => 'Admin Movil',
                'role'        => 'admin',
                'pin'         => Hash::make('5678'),
                'password'    => Hash::make('adminmovil5678'),
                'permissions' => ['void_sales', 'manage_catalog', 'adjust_stock', 'view_global_history'],
            ]
        );
    }
}
