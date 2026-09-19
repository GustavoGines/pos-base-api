<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->string('category', 100)->nullable()->change(); // Make old category nullable
            $table->string('receipt_file_url')->nullable()->after('expense_category_id');
        });

        // Safe way to update ENUM in MySQL without DBAL
        DB::statement("ALTER TABLE cash_movements MODIFY COLUMN type ENUM('expense', 'withdrawal', 'deposit', 'supplier_payment') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE cash_movements MODIFY COLUMN type ENUM('expense', 'withdrawal', 'deposit') NOT NULL");

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['expense_category_id']);
            $table->dropColumn(['expense_category_id', 'receipt_file_url']);
            $table->string('category', 100)->nullable(false)->change();
        });
    }
};
