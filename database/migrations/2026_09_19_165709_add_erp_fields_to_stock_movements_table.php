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
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('supplier_invoice_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        // Add 'purchase' to type enum
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale', 'purchase') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale') NOT NULL");

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['supplier_invoice_id']);
            $table->dropColumn('supplier_invoice_id');
        });
    }
};
