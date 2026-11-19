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
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'has_production_recipe')) {
                    $table->boolean('has_production_recipe')
                        ->default(false)
                        ->after('allow_sell_expired')
                        ->comment('Indica si posee receta de elaboracion propia (semi-elaborados o terminados)');
                }
                if (! Schema::hasColumn('products', 'recipe_yield_quantity')) {
                    $table->decimal('recipe_yield_quantity', 12, 3)
                        ->default(1.000)
                        ->after('has_production_recipe')
                        ->comment('Rendimiento base de la receta (ej. 1 torta o 10 kg)');
                }
            });
        }

        if (! Schema::hasTable('product_recipe_items')) {
            Schema::create('product_recipe_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
                $table->decimal('quantity', 12, 3);
                $table->decimal('waste_pct', 5, 2)->default(0);
                $table->timestamps();

                $table->unique(['parent_product_id', 'ingredient_product_id'], 'uniq_recipe_parent_ingredient');
            });
        }

        if (! Schema::hasTable('production_orders')) {
            Schema::create('production_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number', 32)->unique();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('produced_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->decimal('quantity_produced', 12, 3);
                $table->decimal('total_cost', 12, 2)->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->date('manufactured_at');
                $table->date('expiration_date')->nullable();
                $table->string('status', 24)->default('completed');
                $table->text('notes')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('authorized_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['product_id', 'status', 'manufactured_at'], 'idx_prod_orders_product_date');
            });
        }

        if (! Schema::hasTable('production_order_consumptions')) {
            Schema::create('production_order_consumptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
                $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->decimal('quantity_consumed', 12, 3);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('subtotal_cost', 12, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_consumptions');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('product_recipe_items');

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $cols = array_values(array_filter([
                    Schema::hasColumn('products', 'has_production_recipe') ? 'has_production_recipe' : null,
                    Schema::hasColumn('products', 'recipe_yield_quantity') ? 'recipe_yield_quantity' : null,
                ]));
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
