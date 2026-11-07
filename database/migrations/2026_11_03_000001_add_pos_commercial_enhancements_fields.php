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
        // 1. Productos creados al vuelo desde el POS (pendientes de revisión) y Alertas de Vencimiento en Cascada
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'needs_catalog_review')) {
                    $table->boolean('needs_catalog_review')
                        ->default(false)
                        ->after('active')
                        ->comment('Indica si fue creado al vuelo desde el POS y requiere revisión de catálogo');
                }
                if (! Schema::hasColumn('products', 'expiration_date')) {
                    $table->date('expiration_date')
                        ->nullable()
                        ->after('vencimiento_dias')
                        ->comment('Fecha de vencimiento explícita del lote actual en góndola');
                }
                if (! Schema::hasColumn('products', 'expiry_alert_days')) {
                    $table->integer('expiry_alert_days')
                        ->nullable()
                        ->default(15)
                        ->after('expiration_date')
                        ->comment('Días de anticipación para pre-alerta inicial de vencimiento');
                }
                if (! Schema::hasColumn('products', 'expiry_alert_snoozed_until')) {
                    $table->timestamp('expiry_alert_snoozed_until')
                        ->nullable()
                        ->after('expiry_alert_days')
                        ->comment('Fecha hasta la cual se pospuso la alerta de vencimiento');
                }
                if (! Schema::hasColumn('products', 'expiry_alert_last_stage')) {
                    $table->integer('expiry_alert_last_stage')
                        ->nullable()
                        ->after('expiry_alert_snoozed_until')
                        ->comment('Etapa de cascada en la que se pospuso la alerta (15, 7, 3, 0)');
                }
            });
        }

        // 2. Ítems temporales y renombrado personalizado por venta (Alias de Facturación / Ticket)
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                if (! Schema::hasColumn('sale_items', 'is_temporary')) {
                    $table->boolean('is_temporary')
                        ->default(false)
                        ->after('product_id')
                        ->comment('Verdadero si es un ítem eventual que no descuenta stock de catálogo');
                }
                if (! Schema::hasColumn('sale_items', 'custom_description')) {
                    $table->string('custom_description', 255)
                        ->nullable()
                        ->after('product_name')
                        ->comment('Sobrescritura temporal de descripción solo para este comprobante');
                }
                if (! Schema::hasColumn('sale_items', 'custom_details')) {
                    $table->text('custom_details')
                        ->nullable()
                        ->after('custom_description')
                        ->comment('Notas o detalles adicionales del ítem para ticket y PDF');
                }
            });
        }

        // 3. Trazabilidad de Transferencias Bancarias / Alias en pagos de venta
        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('sale_payments', 'transfer_account_alias')) {
                    $table->string('transfer_account_alias', 100)
                        ->nullable()
                        ->after('reference_id')
                        ->comment('Alias o cuenta destino donde se acreditó la transferencia');
                }
                if (! Schema::hasColumn('sale_payments', 'transfer_sender_name')) {
                    $table->string('transfer_sender_name', 150)
                        ->nullable()
                        ->after('transfer_account_alias')
                        ->comment('Nombre del titular/emisor de la transferencia');
                }
            });
        }

        // 4. Índices de auditoría para comprobantes de proveedor (invoice, delivery_note, credit_note)
        if (Schema::hasTable('supplier_invoices')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                if (! Schema::hasIndex('supplier_invoices', 'idx_supp_inv_date')) {
                    $table->index(['supplier_id', 'issue_date'], 'idx_supp_inv_date');
                }
                if (! Schema::hasIndex('supplier_invoices', 'idx_supp_inv_num')) {
                    $table->index('invoice_number', 'idx_supp_inv_num');
                }
            });
        }

        // 5. Trazabilidad histórica de costos y precios de venta en líneas de comprobantes de proveedor
        if (Schema::hasTable('supplier_invoice_items')) {
            Schema::table('supplier_invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('supplier_invoice_items', 'previous_cost')) {
                    $table->decimal('previous_cost', 12, 2)
                        ->nullable()
                        ->after('quantity')
                        ->comment('Costo unitario del producto previo a la carga del comprobante');
                }
                if (! Schema::hasColumn('supplier_invoice_items', 'new_selling_price')) {
                    $table->decimal('new_selling_price', 12, 2)
                        ->nullable()
                        ->after('unit_cost')
                        ->comment('Precio de venta fijado al ingresar el comprobante');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('supplier_invoice_items')) {
            Schema::table('supplier_invoice_items', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('supplier_invoice_items', 'previous_cost')) {
                    $cols[] = 'previous_cost';
                }
                if (Schema::hasColumn('supplier_invoice_items', 'new_selling_price')) {
                    $cols[] = 'new_selling_price';
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('supplier_invoices')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                if (Schema::hasIndex('supplier_invoices', 'idx_supp_inv_date')) {
                    $table->dropIndex('idx_supp_inv_date');
                }
                if (Schema::hasIndex('supplier_invoices', 'idx_supp_inv_num')) {
                    $table->dropIndex('idx_supp_inv_num');
                }
            });
        }

        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('sale_payments', 'transfer_account_alias')) {
                    $cols[] = 'transfer_account_alias';
                }
                if (Schema::hasColumn('sale_payments', 'transfer_sender_name')) {
                    $cols[] = 'transfer_sender_name';
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('sale_items', 'is_temporary')) {
                    $cols[] = 'is_temporary';
                }
                if (Schema::hasColumn('sale_items', 'custom_description')) {
                    $cols[] = 'custom_description';
                }
                if (Schema::hasColumn('sale_items', 'custom_details')) {
                    $cols[] = 'custom_details';
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $cols = [];
                foreach ([
                    'needs_catalog_review',
                    'expiration_date',
                    'expiry_alert_days',
                    'expiry_alert_snoozed_until',
                    'expiry_alert_last_stage',
                ] as $col) {
                    if (Schema::hasColumn('products', $col)) {
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
