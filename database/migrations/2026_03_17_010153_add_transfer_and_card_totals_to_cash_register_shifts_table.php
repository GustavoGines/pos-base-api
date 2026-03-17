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
            if (!Schema::hasColumn('cash_register_shifts', 'total_transfers')) {
                $table->decimal('total_transfers', 12, 2)->default(0)->after('difference');
            }
            if (!Schema::hasColumn('cash_register_shifts', 'total_cards')) {
                $table->decimal('total_cards', 12, 2)->default(0)->after('total_transfers');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_transfers', 'total_cards']);
        });
    }
};
