<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade la columna `price_list` a la tabla quotes.
     * Almacena el identificador de la Lista de Precios aplicada al presupuesto.
     * Ejemplos: 'base', 'wholesale', 'card', 'Jubilados -10%', 'Gremio -5%'
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('price_list')->nullable()->default('base')
                  ->comment('Lista de precios aplicada al presupuesto. base = Contado/Efectivo.')
                  ->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('price_list');
        });
    }
};
