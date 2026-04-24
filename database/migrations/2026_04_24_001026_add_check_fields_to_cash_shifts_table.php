<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->decimal('check_sales', 10, 2)->default(0)->after('transfer_sales');
            $table->unsignedInteger('check_count')->default(0)->after('check_sales');
            $table->json('check_details')->nullable()->after('check_count');
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn(['check_sales', 'check_count', 'check_details']);
        });
    }
};
