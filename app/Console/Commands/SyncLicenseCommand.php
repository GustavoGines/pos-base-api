<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LicenseSyncService;

class SyncLicenseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza el estado de la licencia local con el servidor central.';

    /**
     * Execute the console command.
     */
    public function handle(LicenseSyncService $syncService)
    {
        $this->info('Iniciando sincronización de licencia...');
        
        try {
            $syncService->syncHeartbeat();
            $this->info('Sincronización de licencia completada.');
        } catch (\Exception $e) {
            $this->error('Ocurrió un error al sincronizar la licencia: ' . $e->getMessage());
        }
    }
}
