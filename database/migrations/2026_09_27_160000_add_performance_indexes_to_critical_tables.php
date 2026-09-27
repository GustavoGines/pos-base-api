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
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['created_at', 'status']);
        });

        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->index(['created_at', 'type']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->index(['created_at', 'type']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'status']);
        });

        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'type']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'type']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
