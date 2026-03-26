<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class SetLicenseKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:set {api_key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configura la llave de licencia del cliente y fuerza una sincronización.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = $this->argument('api_key');

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_api_key'],
            ['value' => $apiKey]
        );

        $this->info("✅ Llave de licencia guardada exitosamente.");
        $this->comment("Forzando sincronización con el servidor...");

        // Llamar al comando de sync programáticamente
        Artisan::call('license:sync');
        
        // Imprimir el output del comando llamado
        $this->line(Artisan::output());

        return self::SUCCESS;
    }
}
