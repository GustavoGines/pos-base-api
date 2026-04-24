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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('delivery_address', 500)->nullable()->after('phone');
        });
        
        Schema::table('sales', function (Blueprint $table) {
            $table->string('delivery_address', 500)->nullable()->after('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('delivery_address');
        });
        
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('delivery_address');
        });
    }
};
