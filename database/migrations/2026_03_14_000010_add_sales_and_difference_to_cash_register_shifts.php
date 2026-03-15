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
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->decimal('total_sales', 12, 2)->default(0)->after('closing_balance');
            $table->decimal('difference', 12, 2)->default(0)->after('total_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_sales', 'difference']);
        });
    }
};
