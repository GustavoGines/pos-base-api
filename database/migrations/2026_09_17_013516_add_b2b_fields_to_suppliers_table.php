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
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('cuit')->nullable()->unique()->after('name');
            $table->string('tax_category')->nullable()->after('cuit');
            $table->decimal('balance', 12, 2)->default(0)->after('address')->comment('Saldo de deuda con el proveedor');
            $table->boolean('is_active')->default(true)->after('balance');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['cuit', 'tax_category', 'balance', 'is_active']);
        });
    }
};
