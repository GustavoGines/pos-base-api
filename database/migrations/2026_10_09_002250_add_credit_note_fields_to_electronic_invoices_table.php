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
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->string('credit_note_cae')->nullable()->after('issued_at')->comment('CAE de la Nota de Crédito');
            $table->date('credit_note_expiration')->nullable()->after('credit_note_cae');
            $table->string('credit_note_number')->nullable()->after('credit_note_expiration');
            $table->timestamp('credit_note_issued_at')->nullable()->after('credit_note_number');
            $table->integer('credit_note_voucher_type')->nullable()->after('credit_note_issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'credit_note_cae',
                'credit_note_expiration',
                'credit_note_number',
                'credit_note_issued_at',
                'credit_note_voucher_type',
            ]);
        });
    }
};
