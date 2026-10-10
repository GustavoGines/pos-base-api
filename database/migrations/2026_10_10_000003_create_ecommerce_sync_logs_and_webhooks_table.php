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
        // Registro de Webhooks Entrantes para Idempotencia, Anti-Replay y Procesamiento Desacoplado
        if (! Schema::hasTable('ecommerce_webhooks')) {
            Schema::create('ecommerce_webhooks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->nullable()->constrained('ecommerce_integrations')->nullOnDelete();
                $table->string('platform', 50)->index();
                $table->string('event_type', 100)->index()->comment('orders_v2, order/created, order.created, etc.');
                $table->string('external_event_id', 150)->unique()->comment('Identificador único provisto por la plataforma');
                $table->timestamp('event_timestamp')->nullable()->comment('Marca temporal provista por el emisor para ventana anti-replay');
                $table->json('payload')->comment('Cuerpo JSON crudo de la notificación');
                $table->json('headers')->nullable()->comment('Encabezados HTTP de la petición');
                $table->enum('status', ['received', 'processing', 'processed', 'failed', 'ignored'])->default('received')->index();
                $table->timestamp('processed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        // Registro Histórico de Auditoría y Trazabilidad de Sincronización
        if (! Schema::hasTable('ecommerce_sync_logs')) {
            Schema::create('ecommerce_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->constrained('ecommerce_integrations')->cascadeOnDelete();
                $table->foreignId('mapping_id')->nullable()->constrained('ecommerce_product_mappings')->nullOnDelete();
                $table->string('external_order_id', 100)->nullable()->index()->comment('ID de orden externa para idempotencia transaccional y reconciliación');
                $table->enum('direction', ['outbound', 'inbound'])->index();
                $table->string('action', 50)->index()->comment('update_stock, order_deduct, order_refund_restock, poll_reconcile, pause_listing');
                $table->decimal('stock_before', 12, 3)->nullable();
                $table->decimal('stock_after', 12, 3)->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->enum('status', ['success', 'failed', 'stock_conflict_review'])->index();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        // Extensión del enum de Kardex en stock_movements para trazabilidad omnicanal
        if (Schema::hasTable('stock_movements')) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement(
                    "ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale', 'purchase', 'sale_ecommerce', 'restock_ecommerce') NOT NULL"
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
                    "ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'sale', 'purchase') NOT NULL"
                );
            }
        }

        Schema::dropIfExists('ecommerce_sync_logs');
        Schema::dropIfExists('ecommerce_webhooks');
    }
};
