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
        if (Schema::hasTable('sales') && ! Schema::hasIndex('sales', 'sales_created_at_status_index')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['created_at', 'status']);
            });
        }

        if (Schema::hasTable('customer_transactions') && ! Schema::hasIndex('customer_transactions', 'customer_transactions_created_at_type_index')) {
            Schema::table('customer_transactions', function (Blueprint $table) {
                $table->index(['created_at', 'type']);
            });
        }

        if (Schema::hasTable('cash_movements') && ! Schema::hasIndex('cash_movements', 'cash_movements_created_at_type_index')) {
            Schema::table('cash_movements', function (Blueprint $table) {
                $table->index(['created_at', 'type']);
            });
        }

        if (Schema::hasTable('stock_movements') && ! Schema::hasIndex('stock_movements', 'stock_movements_created_at_index')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasIndex('sales', 'sales_created_at_status_index')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['created_at', 'status']);
            });
        }

        if (Schema::hasTable('customer_transactions') && Schema::hasIndex('customer_transactions', 'customer_transactions_created_at_type_index')) {
            Schema::table('customer_transactions', function (Blueprint $table) {
                $table->dropIndex(['created_at', 'type']);
            });
        }

        if (Schema::hasTable('cash_movements') && Schema::hasIndex('cash_movements', 'cash_movements_created_at_type_index')) {
            Schema::table('cash_movements', function (Blueprint $table) {
                $table->dropIndex(['created_at', 'type']);
            });
        }

        if (Schema::hasTable('stock_movements') && Schema::hasIndex('stock_movements', 'stock_movements_created_at_index')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }
    }
};
