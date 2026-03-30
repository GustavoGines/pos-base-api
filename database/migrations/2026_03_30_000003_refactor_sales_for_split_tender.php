<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Eliminar columna legacy de método único
            $table->dropColumn('payment_method');

            // Sumatoria de recargos cobrados en esta venta (suma de sale_payments.surcharge_amount)
            $table->decimal('total_surcharge', 12, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('total_surcharge');
            $table->string('payment_method')->nullable()->after('total');
        });
    }
};
