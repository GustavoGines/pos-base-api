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
            $table->decimal('cc_sales', 10, 2)->default(0)->after('transfer_sales')
                  ->comment('Total de ventas registradas en Cuenta Corriente (deuda, no efectivo)');
            $table->unsignedInteger('cc_sales_count')->default(0)->after('cc_sales')
                  ->comment('Cantidad de transacciones en Cuenta Corriente en este turno');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn(['cc_sales', 'cc_sales_count']);
        });
    }
};
