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
        if (! Schema::hasTable('ecommerce_integrations')) {
            Schema::create('ecommerce_integrations', function (Blueprint $table) {
                $table->id();
                $table->enum('platform', ['mercadolibre', 'tiendanube', 'woocommerce', 'ecommerce_platform'])
                      ->index()
                      ->comment('Identificador del canal integrado');
                $table->string('name', 100)->comment('Nombre descriptivo, ej: MercadoLibre Oficial');

                // Parámetros de direccionamiento
                $table->string('store_id', 100)->nullable()->comment('Store ID de TiendaNube o Tenant ID de ecommerce-platform');
                $table->string('base_url', 255)->nullable()->comment('URL base para WooCommerce o subdominio de tenant');

                // Credenciales y Seguridad (Cifrado en reposo con Crypt::encryptString)
                $table->string('client_id', 150)->nullable()->comment('App ID o Consumer Key');
                $table->text('client_secret')->nullable()->comment('Client Secret o Consumer Secret cifrado');
                $table->text('access_token')->nullable()->comment('OAuth2 Access Token o API Token cifrado');
                $table->text('refresh_token')->nullable()->comment('OAuth2 Refresh Token cifrado');
                $table->timestamp('token_expires_at')->nullable()->comment('Expiración del access token (6h en MELI)');
                $table->text('webhook_secret')->nullable()->comment('Secreto criptográfico para firma de Webhooks');

                // Configuración operativa, control de tráfico y resiliencia de canal
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('sync_stock_enabled')->default(true);
                $table->boolean('sync_price_enabled')->default(false);
                $table->unsignedSmallInteger('rate_limit_per_minute')->default(60)->comment('Cuota de peticiones por minuto');
                $table->unsignedSmallInteger('batch_size')->default(50)->comment('Tamaño de lote para sincronizaciones masivas (10-100)');
                $table->unsignedSmallInteger('timeout_seconds')->default(30)->comment('Timeout HTTP por petición (evita errores 504/524 en hosting compartido)');
                $table->boolean('auto_pause_on_zero_stock')->default(true)->comment('Pausar o despublicar remotamente al agotar stock');
                $table->json('settings')->nullable()->comment('Opciones específicas (bodega, estados de orden admitidos, webhook tolerance)');

                // Salud de la conexión y Circuit Breaker
                $table->timestamp('last_health_check_at')->nullable();
                $table->string('last_health_status', 50)->default('unknown');
                $table->unsignedSmallInteger('consecutive_failures')->default(0)->comment('Contador consecutivo de fallos');
                $table->timestamp('circuit_breaker_tripped_at')->nullable()->comment('Marca temporal de apertura del Circuit Breaker');
                $table->timestamps();

                $table->unique(['platform', 'store_id'], 'uk_platform_store');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_integrations');
    }
};
