<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_combo')->default(false)->after('active');
        });

        Schema::create('product_combos', function (Blueprint $table) {
            $table->id();
            // Producto padre (el combo)
            $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
            // Producto hijo (el ingrediente unitario)
            $table->foreignId('child_product_id')->constrained('products')->cascadeOnDelete();
            // Cantidad que aporta al combo (e.g., 6 bollos)
            $table->decimal('quantity', 10, 3)->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_combos');
        
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_combo');
        });
    }
};
