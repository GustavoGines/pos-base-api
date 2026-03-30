<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Días de vida útil del producto (Shelf Life).
            // NULL = sin vencimiento (ej: utensilios, moldes).
            // Ej: 90 = el producto vence 90 días después de la fecha de envasado.
            $table->unsignedSmallInteger('vencimiento_dias')->nullable()->after('is_sold_by_weight');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('vencimiento_dias');
        });
    }
};
