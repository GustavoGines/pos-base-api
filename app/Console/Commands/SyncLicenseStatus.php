<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncLicenseStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'license:sync';

    /**
     * The console command description.
     */
    protected $description = 'Sincroniza el estado de la licencia con el servidor remoto de licencias.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Leer la API Key desde la tabla de configuraciones
        $apiKey = DB::table('business_settings')->where('key', 'license_api_key')->value('value');

        if (empty($apiKey)) {
            // Sin API Key configurada — modo sin licencia, no hacer nada
            $this->info('Sin API Key configurada. Saltando sincronización.');
            return self::SUCCESS;
        }

        $serverUrl = rtrim(config('services.license_server.url', ''), '/');

        try {
            $response = Http::timeout(10)->post("{$serverUrl}/api/check-license", [
                'api_key' => $apiKey
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->updateSetting('license_status', $data['status'] ?? 'active');
                $this->updateSetting('license_plan_type', $data['plan_type'] ?? 'basic');
                $this->updateSetting('license_allowed_addons', json_encode($data['allowed_addons'] ?? []));
                $this->updateSetting('license_last_sync', now()->toISOString());
                $this->info("Licencia sincronizada: plan={$data['plan_type']}, status={$data['status']}");

            } elseif ($response->status() === 403) {
                // Licencia suspendida o expirada
                $data = $response->json();
                $this->updateSetting('license_status', $data['status'] ?? 'suspended');
                $this->updateSetting('license_plan_type', 'none');
                $this->updateSetting('license_allowed_addons', json_encode([]));
                $this->warn("Licencia suspendida/expirada: {$data['message']}");

            } else {
                // Otro error del servidor — no alterar el estado local
                $this->warn("Respuesta inesperada del servidor ({$response->status()}). Estado local sin modificar.");
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Sin internet — modo offline, el estado local se mantiene intacto
            $this->info('Sin conexión al servidor. El sistema continúa en modo offline.');

        } catch (\Exception $e) {
            $this->error("Error inesperado: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }

    /**
     * Inserta o actualiza un valor en la tabla settings.
     */
    private function updateSetting(string $key, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }
}
