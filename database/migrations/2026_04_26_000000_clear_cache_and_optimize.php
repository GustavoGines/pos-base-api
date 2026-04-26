<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Solución "Huevo y Gallina":
        // Como los clientes actuales tienen el updater viejo (que solo hace migrate --force),
        // usamos esta migración para forzar la limpieza de caché al momento de actualizar.
        // Las futuras actualizaciones ya usarán el nuevo updater.
        
        Artisan::call('optimize:clear');
        Artisan::call('optimize');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No aplicable
    }
};
