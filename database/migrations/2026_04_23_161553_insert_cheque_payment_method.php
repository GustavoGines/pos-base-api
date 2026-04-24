<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('payment_methods')->updateOrInsert(
            ['code' => 'cheque'],
            [
                'name' => 'Cheque de Terceros',
                'surcharge_type' => 'none',
                'surcharge_value' => 0.0,
                'is_cash' => false,
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payment_methods')->where('code', 'cheque')->delete();
    }
};
