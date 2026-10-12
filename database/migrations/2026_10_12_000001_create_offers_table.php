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
        if (! Schema::hasTable('offers')) {
            Schema::create('offers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('description', 255)->nullable();
                $table->enum('type', ['percentage', 'fixed_price'])->default('percentage');
                $table->decimal('discount_percentage', 5, 2)->nullable()->comment('Porcentaje de descuento si el tipo es percentage');
                $table->decimal('discount_amount', 12, 2)->nullable()->comment('Monto fijo de descuento si aplica');
                $table->enum('period_type', ['daily', 'weekly', 'monthly', 'custom'])->default('daily');
                $table->json('days_of_week')->nullable()->comment('Días de la semana aplicables ej: ["monday","friday"] o null para todos');
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offer_products')) {
            Schema::create('offer_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offer_id')->constrained('offers')->onDelete('cascade');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->decimal('special_price', 12, 2)->nullable()->comment('Precio especial fijo opcional para este producto específico');
                $table->timestamps();

                $table->unique(['offer_id', 'product_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_products');
        Schema::dropIfExists('offers');
    }
};
