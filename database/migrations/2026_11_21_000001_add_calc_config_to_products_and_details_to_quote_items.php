<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'calc_config')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('calc_config')->nullable()->after('unit_type');
            });
        }

        if (Schema::hasTable('quote_items') && ! Schema::hasColumn('quote_items', 'custom_details')) {
            Schema::table('quote_items', function (Blueprint $table) {
                $table->string('custom_details', 1000)->nullable()->after('product_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'calc_config')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('calc_config');
            });
        }

        if (Schema::hasTable('quote_items') && Schema::hasColumn('quote_items', 'custom_details')) {
            Schema::table('quote_items', function (Blueprint $table) {
                $table->dropColumn('custom_details');
            });
        }
    }
};
