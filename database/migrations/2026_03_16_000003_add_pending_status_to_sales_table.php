<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Renombra el estado por defecto de 'active' a 'completed' y agrega 'pending'.
     * Valores válidos de status: 'pending', 'completed', 'voided'
     */
    public function up(): void
    {
        // Renombrar los registros existentes 'active' → 'completed'
        DB::table('sales')->where('status', 'active')->update(['status' => 'completed']);

        // Cambiar el valor por defecto de la columna a 'completed'
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status')->default('completed')->change();
        });
    }

    public function down(): void
    {
        // Revertir 'completed' → 'active' (no toca las 'pending' o 'voided')
        DB::table('sales')->where('status', 'completed')->update(['status' => 'active']);

        Schema::table('sales', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }
};
