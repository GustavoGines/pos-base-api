<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'is_service')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_service')->default(false)->after('is_combo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'is_service')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('is_service');
            });
        }
    }
};
