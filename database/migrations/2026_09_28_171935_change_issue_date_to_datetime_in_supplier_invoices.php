<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cambiar issue_date de DATE a DATETIME para preservar la hora del registro
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dateTime('issue_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->date('issue_date')->nullable()->change();
        });
    }
};
