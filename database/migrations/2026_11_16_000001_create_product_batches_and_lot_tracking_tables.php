<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'track_batches')) {
                    $table->boolean('track_batches')
                        ->default(false)
                        ->after('vencimiento_dias')
                        ->comment('Activa control de stock y vencimientos por lotes (FEFO)');
                }
                if (! Schema::hasColumn('products', 'lot_deduction_policy')) {
                    $table->string('lot_deduction_policy', 16)
                        ->default('fefo')
                        ->after('track_batches')
                        ->comment('Politica de descuento de lotes: fefo o fifo');
                }
                if (! Schema::hasColumn('products', 'allow_sell_expired')) {
                    $table->boolean('allow_sell_expired')
                        ->default(false)
                        ->after('lot_deduction_policy')
                        ->comment('Permite vender sin PIN de supervisor aunque los lotes disponibles esten vencidos');
                }
            });
        }

        if (! Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->string('batch_code', 64);
                $table->string('source_type', 32)->default('purchase');
                $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->unsignedBigInteger('production_order_id')->nullable()->index();
                $table->date('manufactured_at')->nullable();
                $table->date('expiration_date')->nullable();
                $table->decimal('initial_quantity', 12, 3);
                $table->decimal('remaining_quantity', 12, 3);
                $table->decimal('reserved_quantity', 12, 3)->default(0);
                $table->decimal('unit_cost', 12, 2)->nullable();
                $table->string('status', 24)->default('active');
                $table->timestamp('alert_snoozed_until')->nullable();
                $table->smallInteger('alert_last_stage')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['product_id', 'status', 'expiration_date', 'id'], 'idx_batches_fefo_lookup');
                $table->index(['supplier_invoice_id', 'status'], 'idx_batches_invoice_status');
            });
        }

        if (! Schema::hasTable('sale_item_batch_allocations')) {
            Schema::create('sale_item_batch_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
                $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('batch_id')->constrained('product_batches')->restrictOnDelete();
                $table->decimal('quantity', 12, 3);
                $table->decimal('restored_quantity', 12, 3)->default(0);
                $table->decimal('unit_cost', 12, 2)->nullable();
                $table->timestamps();

                $table->index(['sale_id', 'product_id', 'id'], 'idx_alloc_sale_product');
                $table->index(['batch_id', 'sale_id'], 'idx_alloc_batch_sale');
            });
        }

        if (Schema::hasTable('supplier_invoice_items')) {
            Schema::table('supplier_invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('supplier_invoice_items', 'batch_id')) {
                    $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('product_batches')->nullOnDelete();
                }
                if (! Schema::hasColumn('supplier_invoice_items', 'batch_code')) {
                    $table->string('batch_code', 64)->nullable()->after('batch_id');
                }
                if (! Schema::hasColumn('supplier_invoice_items', 'expiration_date')) {
                    $table->date('expiration_date')->nullable()->after('batch_code');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'batch_id')) {
                    $table->foreignId('batch_id')->nullable()->after('supplier_invoice_id')->constrained('product_batches')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_movements', 'unit_cost')) {
                    $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
                }
            });

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement(
                    "ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale', 'purchase', 'sale_ecommerce', 'restock_ecommerce', 'return', 'waste', 'production_in', 'production_out') NOT NULL"
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_movements')) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement(
                    "ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale', 'purchase', 'sale_ecommerce', 'restock_ecommerce') NOT NULL"
                );
            }

            Schema::table('stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('stock_movements', 'batch_id')) {
                    $table->dropForeign(['batch_id']);
                    $table->dropColumn('batch_id');
                }
                if (Schema::hasColumn('stock_movements', 'unit_cost')) {
                    $table->dropColumn('unit_cost');
                }
            });
        }

        if (Schema::hasTable('supplier_invoice_items')) {
            Schema::table('supplier_invoice_items', function (Blueprint $table) {
                if (Schema::hasColumn('supplier_invoice_items', 'batch_id')) {
                    $table->dropForeign(['batch_id']);
                    $table->dropColumn('batch_id');
                }
                if (Schema::hasColumn('supplier_invoice_items', 'batch_code')) {
                    $table->dropColumn('batch_code');
                }
                if (Schema::hasColumn('supplier_invoice_items', 'expiration_date')) {
                    $table->dropColumn('expiration_date');
                }
            });
        }

        Schema::dropIfExists('sale_item_batch_allocations');
        Schema::dropIfExists('product_batches');

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $cols = array_values(array_filter([
                    Schema::hasColumn('products', 'track_batches') ? 'track_batches' : null,
                    Schema::hasColumn('products', 'lot_deduction_policy') ? 'lot_deduction_policy' : null,
                    Schema::hasColumn('products', 'allow_sell_expired') ? 'allow_sell_expired' : null,
                ]));
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
