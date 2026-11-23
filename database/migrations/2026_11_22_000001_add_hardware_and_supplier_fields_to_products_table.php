<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'unit_type')) {
            $driver = DB::getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE products MODIFY COLUMN unit_type VARCHAR(20) NOT NULL DEFAULT 'un'");
            } else {
                Schema::table('products', function (Blueprint $table) {
                    $table->string('unit_type', 20)->default('un')->change();
                });
            }
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'supplier_sku')) {
                $table->string('supplier_sku', 64)->nullable()->after('internal_code');
            }
            if (! Schema::hasColumn('products', 'storage_location')) {
                $table->string('storage_location', 120)->nullable()->after('supplier_id');
            }
            if (! Schema::hasColumn('products', 'technical_spec')) {
                $table->string('technical_spec', 120)->nullable()->after('storage_location');
            }
            if (! Schema::hasColumn('products', 'purchase_package_name')) {
                $table->string('purchase_package_name', 40)->nullable()->after('unit_type');
            }
            if (! Schema::hasColumn('products', 'conversion_factor')) {
                $table->decimal('conversion_factor', 10, 3)->nullable()->after('purchase_package_name');
            }
        });

        if (Schema::hasColumn('products', 'supplier_sku') && ! Schema::hasIndex('products', 'products_supplier_sku_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('supplier_sku', 'products_supplier_sku_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasIndex('products', 'products_supplier_sku_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_supplier_sku_index');
            });
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['conversion_factor', 'purchase_package_name', 'technical_spec', 'storage_location', 'supplier_sku'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
