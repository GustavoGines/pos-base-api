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
        // 1. Insertar métodos de pago 'mercadopago_qr' y 'mercadopago_point'
        if (Schema::hasTable('payment_methods')) {
            $methods = [
                [
                    'name'            => 'Mercado Pago QR',
                    'code'            => 'mercadopago_qr',
                    'surcharge_type'  => 'none',
                    'surcharge_value' => 0.00,
                    'is_cash'         => false,
                    'is_active'       => true,
                    'sort_order'      => 6,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'name'            => 'Mercado Pago Point',
                    'code'            => 'mercadopago_point',
                    'surcharge_type'  => 'none',
                    'surcharge_value' => 0.00,
                    'is_cash'         => false,
                    'is_active'       => true,
                    'sort_order'      => 7,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ];

            foreach ($methods as $method) {
                DB::table('payment_methods')->updateOrInsert(
                    ['code' => $method['code']],
                    $method
                );
            }
        }

        // 2. Extender sale_payments con referencias externas de pasarela
        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('sale_payments', 'mp_payment_id')) {
                    $table->string('mp_payment_id', 50)->nullable()->after('total_amount')->index();
                }
                if (! Schema::hasColumn('sale_payments', 'mp_order_id')) {
                    $table->string('mp_order_id', 50)->nullable()->after('mp_payment_id');
                }
                if (! Schema::hasColumn('sale_payments', 'reference_id')) {
                    $table->string('reference_id', 100)->nullable()->after('mp_order_id')->index();
                }
            });
        }

        // 3. Extender cash_shifts para arqueo de ventas de Mercado Pago
        if (Schema::hasTable('cash_shifts')) {
            Schema::table('cash_shifts', function (Blueprint $table) {
                if (! Schema::hasColumn('cash_shifts', 'mp_sales')) {
                    $table->decimal('mp_sales', 12, 2)->default(0.00)->after('transfer_sales');
                }
                if (! Schema::hasColumn('cash_shifts', 'mp_sales_count')) {
                    $table->unsignedInteger('mp_sales_count')->default(0)->after('mp_sales');
                }
            });
        }

        // 4. Crear tabla transaccional de órdenes e intenciones QR/Point
        if (! Schema::hasTable('mp_transactions')) {
            Schema::create('mp_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('external_reference', 100)->unique();
                $table->string('collector_id', 50)->nullable();
                $table->string('pos_id', 50)->nullable();
                $table->decimal('amount', 12, 2);
                $table->enum('status', ['pending', 'opened', 'approved', 'rejected', 'cancelled', 'expired'])
                      ->default('pending')
                      ->index();

                $table->string('mp_payment_id', 50)->nullable()->index();
                $table->string('mp_order_id', 50)->nullable();
                $table->text('qr_data')->nullable();
                $table->string('payer_email', 150)->nullable();
                $table->string('payment_method_type', 50)->nullable();

                $table->json('payload_received')->nullable();
                $table->timestamps();
            });
        }

        // 5. Crear bitácora y auditoría de Webhooks entrantes de Mercado Pago
        if (! Schema::hasTable('mp_webhooks')) {
            Schema::create('mp_webhooks', function (Blueprint $table) {
                $table->id();
                $table->string('action', 50)->nullable();
                $table->string('topic', 50)->index();
                $table->string('resource_id', 100)->index();
                $table->json('payload');
                $table->boolean('is_processed')->default(false)->index();
                $table->timestamp('processed_at')->nullable();
                $table->text('processing_error')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mp_webhooks');
        Schema::dropIfExists('mp_transactions');

        if (Schema::hasTable('cash_shifts')) {
            Schema::table('cash_shifts', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('cash_shifts', 'mp_sales_count')) {
                    $columnsToDrop[] = 'mp_sales_count';
                }
                if (Schema::hasColumn('cash_shifts', 'mp_sales')) {
                    $columnsToDrop[] = 'mp_sales';
                }
                if (! empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }

        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('sale_payments', 'reference_id')) {
                    $columnsToDrop[] = 'reference_id';
                }
                if (Schema::hasColumn('sale_payments', 'mp_order_id')) {
                    $columnsToDrop[] = 'mp_order_id';
                }
                if (Schema::hasColumn('sale_payments', 'mp_payment_id')) {
                    $columnsToDrop[] = 'mp_payment_id';
                }
                if (! empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }

        if (Schema::hasTable('payment_methods')) {
            DB::table('payment_methods')->whereIn('code', ['mercadopago_qr', 'mercadopago_point'])->delete();
        }
    }
};
