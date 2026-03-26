<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade campos de seguimiento de deuda por ticket a la tabla sales.
     * - payment_status: 'paid' | 'pending' | 'partial'
     * - amount_due: saldo pendiente de este ticket específico
     *
     * Nota: payment_method ya existe en la tabla. Solo necesitamos
     * el soporte para 'cuenta_corriente' como valor válido.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_status')->default('paid')->after('payment_method');
            $table->decimal('amount_due', 10, 2)->default(0)->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'amount_due']);
        });
    }
};
