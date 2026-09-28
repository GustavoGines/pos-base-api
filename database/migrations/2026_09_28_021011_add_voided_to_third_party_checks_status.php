<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE third_party_checks MODIFY COLUMN status ENUM('in_wallet', 'deposited', 'endorsed', 'rejected', 'voided') NOT NULL DEFAULT 'in_wallet'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Eliminar 'voided' si hacemos un rollback
            DB::statement("ALTER TABLE third_party_checks MODIFY COLUMN status ENUM('in_wallet', 'deposited', 'endorsed', 'rejected') NOT NULL DEFAULT 'in_wallet'");
        }
    }
};
