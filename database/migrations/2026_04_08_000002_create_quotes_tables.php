<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas de Presupuestos (Módulo Ferretería — business_type = 'hardware_store').
     * CRÍTICO: Guardar un presupuesto NUNCA descuenta stock.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_number')->unique()->comment('Número de presupuesto autoincremental, ej: PRES-0001');
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable()->comment('Notas o condiciones del presupuesto');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->date('valid_until')->nullable()->comment('Fecha de validez del presupuesto');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->string('product_name')->comment('Snapshot del nombre al momento del presupuesto');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
