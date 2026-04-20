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
        if (!Schema::hasColumn('sales', 'shipping_cost')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('shipping_cost', 12, 2)->default(0)->after('total');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('sales', 'shipping_cost')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('shipping_cost');
            });
        }
    }
};
