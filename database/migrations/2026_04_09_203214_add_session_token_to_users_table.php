<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Agrega session_token: UUID único por sesión activa.
     * NULL = sin sesión activa. Se sobreescribe en cada login → invalida el anterior.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_token', 64)->nullable()->unique()->after('pin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['session_token']);
            $table->dropColumn('session_token');
        });
    }
};
