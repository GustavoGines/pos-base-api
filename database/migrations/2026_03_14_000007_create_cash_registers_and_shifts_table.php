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
        // 1. Cajas Físicas
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Turnos de Caja (Sesiones operativas)
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers');
            $table->foreignId('user_id')->constrained('users');
            
            // Fechas
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            
            // Saldos (en decimal 10,2) para precisión contable
            $table->decimal('opening_balance', 10, 2)->default(0);
            $table->decimal('expected_balance', 10, 2)->nullable();
            $table->decimal('actual_balance', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            
            // Totales por medios de pago (generados dinámicamente)
            $table->decimal('cash_sales', 10, 2)->default(0);
            $table->decimal('card_sales', 10, 2)->default(0);
            $table->decimal('transfer_sales', 10, 2)->default(0);
            
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('cash_registers');
    }
};
