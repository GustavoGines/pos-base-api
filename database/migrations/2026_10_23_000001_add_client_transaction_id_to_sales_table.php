<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'client_transaction_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('client_transaction_id', 64)->nullable()->unique()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'client_transaction_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique(['client_transaction_id']);
                $table->dropColumn('client_transaction_id');
            });
        }
    }
};
