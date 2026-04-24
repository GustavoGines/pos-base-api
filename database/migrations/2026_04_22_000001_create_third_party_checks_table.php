<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_party_checks', function (Blueprint $table) {
            $table->id();

            // ── Datos del Cartón ─────────────────────────────────────
            $table->string('bank_name');
            $table->string('check_number');
            $table->decimal('amount', 10, 2);
            $table->date('issue_date');
            $table->date('payment_date');
            $table->string('issuer_name');
            $table->string('issuer_cuit');

            // ── Trazabilidad ──────────────────────────────────────────
            // NOTA DE SEGURIDAD: restrictOnDelete() en todas las FK.
            // Un cheque físico es un documento contable independiente;
            // eliminar su venta/cliente de origen NO debe destruirlo.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->restrictOnDelete();

            $table->foreignId('sale_id')
                ->nullable()
                ->constrained('sales')
                ->restrictOnDelete();

            // Fase 2 – Endoso a proveedor (nullable hasta entonces)
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->restrictOnDelete();

            // ── Ciclo de Vida ─────────────────────────────────────────
            $table->enum('status', ['in_wallet', 'deposited', 'endorsed', 'rejected'])
                ->default('in_wallet');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_checks');
    }
};
