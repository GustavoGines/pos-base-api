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
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->decimal('total_expenses', 12, 2)->default(0)->after('status')->comment('Total de Gastos (Solo Efectivo)');
            $table->decimal('total_withdrawals', 12, 2)->default(0)->after('total_expenses')->comment('Total de Retiros (Solo Efectivo)');
            $table->decimal('total_deposits', 12, 2)->default(0)->after('total_withdrawals')->comment('Ingresos Extras (Solo Efectivo)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_expenses', 'total_withdrawals', 'total_deposits']);
        });
    }
};
