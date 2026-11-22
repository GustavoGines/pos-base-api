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
            if (! Schema::hasColumn('supplier_invoices', 'voucher_letter')) {
                $table->string('voucher_letter', 2)->nullable()->after('type')->comment('Letra de comprobante: A, B, C, M, X');
            }
            if (! Schema::hasColumn('supplier_invoices', 'point_of_sale')) {
                $table->unsignedInteger('point_of_sale')->nullable()->after('voucher_letter')->comment('Punto de venta fiscal emisor (1 a 99999)');
            }
            if (! Schema::hasColumn('supplier_invoices', 'voucher_number')) {
                $table->string('voucher_number', 50)->nullable()->after('point_of_sale')->comment('Número de comprobante secuencial');
            }
            if (! Schema::hasColumn('supplier_invoices', 'net_21')) {
                $table->decimal('net_21', 12, 2)->default(0)->after('amount');
            }
            if (! Schema::hasColumn('supplier_invoices', 'iva_21')) {
                $table->decimal('iva_21', 12, 2)->default(0)->after('net_21');
            }
            if (! Schema::hasColumn('supplier_invoices', 'net_105')) {
                $table->decimal('net_105', 12, 2)->default(0)->after('iva_21');
            }
            if (! Schema::hasColumn('supplier_invoices', 'iva_105')) {
                $table->decimal('iva_105', 12, 2)->default(0)->after('net_105');
            }
            if (! Schema::hasColumn('supplier_invoices', 'net_27')) {
                $table->decimal('net_27', 12, 2)->default(0)->after('iva_105');
            }
            if (! Schema::hasColumn('supplier_invoices', 'iva_27')) {
                $table->decimal('iva_27', 12, 2)->default(0)->after('net_27');
            }
            if (! Schema::hasColumn('supplier_invoices', 'non_taxed_amount')) {
                $table->decimal('non_taxed_amount', 12, 2)->default(0)->after('iva_27')->comment('Conceptos no gravados');
            }
            if (! Schema::hasColumn('supplier_invoices', 'exempt_amount')) {
                $table->decimal('exempt_amount', 12, 2)->default(0)->after('non_taxed_amount')->comment('Operaciones exentas');
            }
            if (! Schema::hasColumn('supplier_invoices', 'iva_perception')) {
                $table->decimal('iva_perception', 12, 2)->default(0)->after('exempt_amount')->comment('Percepción de IVA');
            }
            if (! Schema::hasColumn('supplier_invoices', 'iibb_perception')) {
                $table->decimal('iibb_perception', 12, 2)->default(0)->after('iva_perception')->comment('Percepción de Ingresos Brutos');
            }
            if (! Schema::hasColumn('supplier_invoices', 'internal_taxes')) {
                $table->decimal('internal_taxes', 12, 2)->default(0)->after('iibb_perception')->comment('Impuestos internos');
            }
            if (! Schema::hasColumn('supplier_invoices', 'affects_vat_book')) {
                $table->boolean('affects_vat_book')->default(true)->after('internal_taxes')->comment('Si computa para el Libro IVA Compras');
            }

            if (! Schema::hasIndex('supplier_invoices', 'idx_supp_inv_vat_book')) {
                $table->index(['affects_vat_book', 'issue_date'], 'idx_supp_inv_vat_book');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            try {
                $table->dropIndex('idx_supp_inv_vat_book');
            } catch (Throwable) {
                // Ignore if index does not exist
            }

            $columnsToDrop = [
                'voucher_letter',
                'point_of_sale',
                'voucher_number',
                'net_21',
                'iva_21',
                'net_105',
                'iva_105',
                'net_27',
                'iva_27',
                'non_taxed_amount',
                'exempt_amount',
                'iva_perception',
                'iibb_perception',
                'internal_taxes',
                'affects_vat_book',
            ];

            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('supplier_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
