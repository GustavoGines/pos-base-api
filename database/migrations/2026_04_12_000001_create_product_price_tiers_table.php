<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo: Tramos de Precio por Volumen (Mayorista)
 *
 * Lógica: Al vender una cantidad Q de un producto con tramos,
 * el sistema busca el tramo activo donde min_quantity <= Q y aplica
 * el unit_price más favorable (el del tramo más alto alcanzado).
 *
 * Ejemplo — Bandeja descartable:
 *   min_quantity=1,   unit_price=200  -> precio minorista
 *   min_quantity=50,  unit_price=170  -> precio mayorista
 *   min_quantity=100, unit_price=140  -> precio distribuidor
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete(); // Si se borra el producto, se borran sus tramos
            $table->decimal('min_quantity', 10, 3)->unsigned();  // Soporta kg/lt también
            $table->decimal('unit_price', 12, 2)->unsigned();
            $table->timestamps();

            // Un producto no puede tener dos tramos con el mismo min_quantity
            $table->unique(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
