<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_invoices')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('supplier_invoices', 'edit_count')) {
                    $table->unsignedInteger('edit_count')
                        ->default(0)
                        ->after('user_id')
                        ->comment('Cantidad de ediciones realizadas sobre el comprobante');
                }
                if (! Schema::hasColumn('supplier_invoices', 'last_edited_at')) {
                    $table->timestamp('last_edited_at')
                        ->nullable()
                        ->after('edit_count');
                }
                if (! Schema::hasColumn('supplier_invoices', 'last_edited_by_id')) {
                    $table->unsignedBigInteger('last_edited_by_id')
                        ->nullable()
                        ->after('last_edited_at');
                }
                if (! Schema::hasColumn('supplier_invoices', 'deleted_by_id')) {
                    $table->unsignedBigInteger('deleted_by_id')
                        ->nullable()
                        ->after('last_edited_by_id');
                }
                if (! Schema::hasColumn('supplier_invoices', 'deletion_reason')) {
                    $table->string('deletion_reason', 255)
                        ->nullable()
                        ->after('deleted_by_id');
                }
            });
        }

        if (! Schema::hasTable('supplier_invoice_audits')) {
            Schema::create('supplier_invoice_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_invoice_id')
                    ->constrained('supplier_invoices')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('authorized_by_id')->nullable();
                $table->string('action', 32)->comment('created, updated, deleted, restored');
                $table->string('reason', 255)->nullable();
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot')->nullable();
                $table->json('changes_summary')->nullable();
                $table->timestamps();

                $table->index(['supplier_invoice_id', 'created_at'], 'idx_supp_inv_audit_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_audits');

        if (Schema::hasTable('supplier_invoices')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                $cols = [];
                foreach ([
                    'edit_count',
                    'last_edited_at',
                    'last_edited_by_id',
                    'deleted_by_id',
                    'deletion_reason',
                ] as $col) {
                    if (Schema::hasColumn('supplier_invoices', $col)) {
                        $cols[] = $col;
                    }
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
