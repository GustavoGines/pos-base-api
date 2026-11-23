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
        if (! Schema::hasTable('categories') || ! Schema::hasColumn('categories', 'rubro_id')) {
            return;
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropForeign(['rubro_id']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreign('rubro_id')
                    ->references('id')
                    ->on('rubros')
                    ->restrictOnDelete();
            });
        } catch (\Throwable) {
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasColumn('categories', 'rubro_id')) {
            return;
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropForeign(['rubro_id']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreign('rubro_id')
                    ->references('id')
                    ->on('rubros')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
        }
    }
};
