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

        // IMPORTANTE: Saltear completamente en SQLite (entorno de testing).
        // `optimize:clear` y `optimize` vacían el config cache, lo que resetea
        // todas las conexiones de BD al .env real (MySQL), rompiendo el entorno
        // in-memory de los tests. En SQLite de testing esta migración es un no-op.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            return;
        }

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
