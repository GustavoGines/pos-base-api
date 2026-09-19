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
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('issue_date');
            $table->enum('status', ['pending', 'partial', 'paid'])->default('paid')->after('type');
            $table->string('receipt_file_url')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'status', 'receipt_file_url']);
        });
    }
};
