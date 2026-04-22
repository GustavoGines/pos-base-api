<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearStaticPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-static-prices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sanea la base de datos limpiando los precios estáticos (wholesale y card) para forzar el uso del motor global';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando purga de precios estáticos...');

        $updated = DB::table('products')->update([
            'price_wholesale' => null,
            'price_card' => null,
        ]);

        $this->info("¡Purga completada! Se limpiaron los overrides estáticos de {$updated} productos.");
        $this->info('El sistema ahora utilizará exclusivamente el motor matemático de factores globales.');
        
        return 0;
    }
}
