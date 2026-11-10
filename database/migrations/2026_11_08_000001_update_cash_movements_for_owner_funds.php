<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_shift_id')->nullable()->change();
            if (! Schema::hasColumn('cash_movements', 'funding_source')) {
                $table->string('funding_source', 30)->default('cash_register')->after('payment_method');
            }
            if (DB::getDriverName() === 'sqlite') {
                $table->string('payment_method', 30)->default('cash')->change();
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN payment_method ENUM('cash', 'transfer', 'check', 'card', 'owner_funds') NOT NULL DEFAULT 'cash'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("UPDATE cash_movements SET payment_method = 'cash' WHERE payment_method IN ('owner_funds', 'card')");
            DB::statement("ALTER TABLE cash_movements MODIFY COLUMN payment_method ENUM('cash', 'transfer', 'check') NOT NULL");
        }

        Schema::table('cash_movements', function (Blueprint $table) {
            if (Schema::hasColumn('cash_movements', 'funding_source')) {
                $table->dropColumn('funding_source');
            }
        });
    }
};
