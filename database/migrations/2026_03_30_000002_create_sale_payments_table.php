<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();
            $table->foreignId('payment_method_id')
                ->constrained('payment_methods');

            $table->decimal('base_amount', 12, 2);          // Monto asignado ANTES del recargo
            $table->decimal('surcharge_amount', 12, 2)->default(0); // Recargo congelado en momento de venta
            $table->decimal('total_amount', 12, 2);         // base_amount + surcharge_amount

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
