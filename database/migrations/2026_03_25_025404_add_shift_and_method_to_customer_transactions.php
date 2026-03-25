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
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->foreignId('cash_register_shift_id')->nullable()->constrained('cash_register_shifts')->onDelete('set null');
            $table->string('payment_method')->default('cash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_shift_id']);
            $table->dropColumn(['cash_register_shift_id', 'payment_method']);
        });
    }
};
