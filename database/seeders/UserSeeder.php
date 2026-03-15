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
        User::updateOrCreate(
            ['email' => 'admin@pos.com'],
            [
                'name' => 'Administrador',
                'role' => 'admin',
                'pin' => Hash::make('1234'),
                'password' => Hash::make('secret123') // Por si acaso algún día implementan web login
            ]
        );

        User::updateOrCreate(
            ['email' => 'cajero@pos.com'],
            [
                'name' => 'Cajero Turno 1',
                'role' => 'cashier',
                'pin' => Hash::make('5678'),
                'password' => Hash::make('secret123')
            ]
        );
    }
}
