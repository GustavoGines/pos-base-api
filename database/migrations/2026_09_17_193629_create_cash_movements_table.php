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
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            
            // Relaciones Críticas
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->comment('Cajero que registra el movimiento');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->comment('Admin que autorizó vía PIN');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->comment('Usuario que anuló el movimiento');
            
            // Relaciones Opcionales
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->foreignId('check_id')->nullable()->constrained('third_party_checks');
            
            // Datos del Movimiento
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'transfer', 'check']);
            $table->enum('type', ['expense', 'withdrawal', 'deposit']);
            $table->string('category', 100);
            $table->text('description')->nullable();
            $table->string('receipt_number', 100)->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
