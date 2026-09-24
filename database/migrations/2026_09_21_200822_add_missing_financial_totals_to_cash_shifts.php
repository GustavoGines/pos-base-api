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
            $table->decimal('total_supplier_payments', 12, 2)->default(0)->after('total_deposits')->comment('Total pagado a proveedores en este turno');
            $table->decimal('total_refunds', 12, 2)->default(0)->after('total_supplier_payments')->comment('Total de dinero devuelto a clientes en este turno');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_supplier_payments', 'total_refunds']);
        });
    }
};
