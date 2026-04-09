<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega los campos de Listas de Precio a la tabla de productos.
     * Solo se usan cuando business_type = 'hardware_store'.
     * Son nullable para retrocompatibilidad total con licencias retail existentes.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price_wholesale', 10, 2)->nullable()->default(null)->after('selling_price')
                  ->comment('Precio Mayorista — solo activo en modo Ferretería');
            $table->decimal('price_card', 10, 2)->nullable()->default(null)->after('price_wholesale')
                  ->comment('Precio Tarjeta — solo activo en modo Ferretería');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price_wholesale', 'price_card']);
        });
    }
};
