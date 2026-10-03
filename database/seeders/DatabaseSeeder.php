<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            BusinessSettingSeeder::class,
            PaymentMethodSeeder::class,
            BigCatalogSeeder::class,    // Solo para demos — NO correr en producción
            // HardwareStoreSeeder::class, // Solo para demo de Ferretería
        ]);
    }
}
