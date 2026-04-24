<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_party_checks', function (Blueprint $table) {
            if (!Schema::hasColumn('third_party_checks', 'cash_shift_id')) {
                $table->foreignId('cash_shift_id')->nullable()->after('supplier_id')->constrained('cash_shifts')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('third_party_checks', function (Blueprint $table) {
            $table->dropForeign(['cash_shift_id']);
            $table->dropColumn('cash_shift_id');
        });
    }
};
