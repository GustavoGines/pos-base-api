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
        if (! Schema::hasTable('ecommerce_product_mappings')) {
            Schema::create('ecommerce_product_mappings', function (Blueprint $table) {
                $table->id();

                // Claves Foráneas
                $table->foreignId('integration_id')
                      ->constrained('ecommerce_integrations')
                      ->cascadeOnDelete()
                      ->comment('Conexión externa correspondiente');
                $table->foreignId('product_id')
                      ->constrained('products')
                      ->cascadeOnDelete()
                      ->comment('Producto local del catálogo POS');

                // Canal desnormalizado para consultas directas sin JOIN
                $table->string('platform', 50)->index();

                // Identificadores Remotos en la Plataforma Externa
                $table->string('external_product_id', 100)
                      ->comment('ID remoto: MLA1234567890 (MELI), ID Numérico (TiendaNube/Woo/ecommerce-platform)');
                $table->string('external_variant_id', 100)->default('0')
                      ->comment('ID de variante remota ("0" si es producto simple sin variantes, garantizando unicidad estricta en MySQL)');
                $table->string('external_sku', 100)->nullable()->index()
                      ->comment('SKU remoto para reconciliación cruzada');
                $table->string('external_title', 255)->nullable()
                      ->comment('Título de la publicación remota');

                // Reglas de Inventario y Parámetros
                $table->boolean('sync_stock')->default(true)->index();
                $table->decimal('safety_stock_threshold', 8, 2)->default(0.00)
                      ->comment('Umbral de protección: si stock local <= threshold, se publica 0 en la web');
                $table->decimal('allocated_stock_percentage', 5, 2)->default(100.00)
                      ->comment('Porcentaje de stock asignado a este canal (0.00 a 100.00)');
                $table->boolean('is_paused')->default(false)->index()
                      ->comment('Pausa selectiva de publicación en este canal específico');

                // Trazabilidad de Estados
                $table->decimal('last_known_local_stock', 12, 3)->nullable();
                $table->decimal('last_known_external_stock', 12, 3)->nullable();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->enum('sync_status', ['synced', 'pending', 'error', 'disabled'])->default('synced')->index();
                $table->text('sync_error_message')->nullable();

                $table->timestamps();

                // Restricciones de Unicidad Compuestas
                $table->unique(
                    ['integration_id', 'external_product_id', 'external_variant_id'],
                    'uk_integration_external_item'
                );
                $table->unique(
                    ['product_id', 'integration_id', 'external_variant_id'],
                    'uk_product_integration_variant'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_product_mappings');
    }
};
