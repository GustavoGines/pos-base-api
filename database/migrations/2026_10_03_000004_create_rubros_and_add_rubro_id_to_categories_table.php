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
        // 1. Crear tabla rubros
        Schema::create('rubros', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // 2. Determinar rubro principal basado en license_business_type de business_settings
        $businessType = 'retail';
        if (Schema::hasTable('business_settings')) {
            $type = DB::table('business_settings')
                ->where('key', 'license_business_type')
                ->value('value');
            if (! empty($type)) {
                $businessType = $type;
            }
        }

        $mapping = [
            'hardware_store' => 'Ferretería',
            'retail'         => 'Comercio General',
            'kiosko'         => 'Kiosco',
            'kiosk'          => 'Kiosco',
            'grocery'        => 'Almacén',
            'supermarket'    => 'Supermercado',
            'clothing'       => 'Indumentaria',
            'pharmacy'       => 'Farmacia',
            'restaurant'     => 'Gastronomía',
        ];

        $rubroName = $mapping[$businessType] ?? (ucfirst(str_replace('_', ' ', $businessType)) ?: 'Comercio General');

        $now = now();
        $defaultRubroId = DB::table('rubros')->insertGetId([
            'name'       => $rubroName,
            'is_system'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Añadir rubro_id a categories con backfill inmediato
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('rubro_id')
                ->nullable()
                ->after('id')
                ->constrained('rubros')
                ->cascadeOnDelete();
        });

        DB::table('categories')->whereNull('rubro_id')->update([
            'rubro_id' => $defaultRubroId,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['rubro_id']);
            $table->dropColumn('rubro_id');
        });

        Schema::dropIfExists('rubros');
    }
};
