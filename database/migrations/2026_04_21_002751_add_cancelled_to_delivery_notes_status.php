<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MODIFY COLUMN is MySQL-only syntax. SQLite stores ENUMs as strings
        // and already accepts any value, so the column change is a no-op there.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE delivery_notes MODIFY COLUMN status ENUM('pending', 'partial', 'delivered', 'cancelled') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE delivery_notes MODIFY COLUMN status ENUM('pending', 'partial', 'delivered') DEFAULT 'pending'");
        }
    }
};
