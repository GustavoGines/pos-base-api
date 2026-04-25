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
        Schema::create('bulk_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('percentage', 8, 2);
            $table->string('rounding_rule');
            $table->string('target_field')->default('selling_price');
            $table->json('filters')->nullable();
            $table->integer('affected_count');
            $table->boolean('reverted')->default(false);
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_price_history_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_price_history_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->decimal('old_cost_price', 12, 2)->nullable();
            $table->decimal('new_cost_price', 12, 2)->nullable();
            $table->decimal('old_selling_price', 12, 2)->nullable();
            $table->decimal('new_selling_price', 12, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_price_history_items');
        Schema::dropIfExists('bulk_price_histories');
    }
};
