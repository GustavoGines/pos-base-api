<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Trazabilidad en Ventas (Anulaciones)
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales', 'void_authorized_by_admin_id')) {
                $table->foreignId('void_authorized_by_admin_id')->nullable()->after('voided_by_user_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('void_authorized_by_admin_id');
            }
            if (! Schema::hasColumn('sales', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_at');
            }
        });

        // 2. Trazabilidad en Movimientos de Stock (Ajustes Manuales)
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'authorized_by_admin_id')) {
                $table->foreignId('authorized_by_admin_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }
        });

        // 3. Trazabilidad en Turnos de Caja (Autorización de Faltantes/Sobrantes)
        Schema::table('cash_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_shifts', 'difference_authorized_by_admin_id')) {
                $table->foreignId('difference_authorized_by_admin_id')->nullable()->after('closed_by_user_id')->constrained('users')->nullOnDelete();
            }
        });

        // 4. Trazabilidad en Cheques de Terceros (Endosos/Depósitos)
        Schema::table('third_party_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('third_party_checks', 'status_changed_by_user_id')) {
                $table->foreignId('status_changed_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('third_party_checks', 'status_authorized_by_admin_id')) {
                $table->foreignId('status_authorized_by_admin_id')->nullable()->after('status_changed_by_user_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'voided_by_user_id')) {
                $table->dropForeign(['voided_by_user_id']);
                $table->dropColumn('voided_by_user_id');
            }
            if (Schema::hasColumn('sales', 'void_authorized_by_admin_id')) {
                $table->dropForeign(['void_authorized_by_admin_id']);
                $table->dropColumn('void_authorized_by_admin_id');
            }
            if (Schema::hasColumn('sales', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
            if (Schema::hasColumn('sales', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'authorized_by_admin_id')) {
                $table->dropForeign(['authorized_by_admin_id']);
                $table->dropColumn('authorized_by_admin_id');
            }
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('cash_shifts', 'difference_authorized_by_admin_id')) {
                $table->dropForeign(['difference_authorized_by_admin_id']);
                $table->dropColumn('difference_authorized_by_admin_id');
            }
        });

        Schema::table('third_party_checks', function (Blueprint $table) {
            if (Schema::hasColumn('third_party_checks', 'status_changed_by_user_id')) {
                $table->dropForeign(['status_changed_by_user_id']);
                $table->dropColumn('status_changed_by_user_id');
            }
            if (Schema::hasColumn('third_party_checks', 'status_authorized_by_admin_id')) {
                $table->dropForeign(['status_authorized_by_admin_id']);
                $table->dropColumn('status_authorized_by_admin_id');
            }
        });
    }
};
