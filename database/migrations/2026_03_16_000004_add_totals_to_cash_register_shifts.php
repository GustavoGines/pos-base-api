<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add total_sales and difference columns to cash_register_shifts.
     */
    public function up(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            // Only add if they don't exist (safe to re-run)
            if (!Schema::hasColumn('cash_register_shifts', 'total_sales')) {
                $table->decimal('total_sales', 12, 2)->default(0)->after('closing_balance');
            }
            if (!Schema::hasColumn('cash_register_shifts', 'difference')) {
                $table->decimal('difference', 12, 2)->default(0)->after('total_sales');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_sales', 'difference']);
        });
    }
};
