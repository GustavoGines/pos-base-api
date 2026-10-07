<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\BusinessSetting;

class MpSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mp:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea la Sucursal y Caja por defecto en Mercado Pago para que funcione el QR presencial';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = BusinessSetting::getSecret('mp_access_token');
        if (empty($token)) {
            $this->error('No hay un Access Token de Mercado Pago configurado. Configurálo desde el panel web primero.');
            return;
        }

        $this->info('Consultando usuario de Mercado Pago...');
        $userResp = Http::withToken($token)->get('https://api.mercadopago.com/users/me');
        if (!$userResp->successful()) {
            $this->error('El Access Token es inválido o no tiene permisos. (Status: ' . $userResp->status() . ')');
            return;
        }
        $userId = (string) $userResp->json('id');
        $this->info("Usuario de MP validado: {$userId}");

        BusinessSetting::updateOrCreate(['key' => 'mp_collector_id'], ['value' => $userId]);
        $this->info("ID de recolector guardado en la base de datos.");

        // 1. Crear o recuperar Sucursal (Store)
        $this->info('Verificando/Creando Sucursal (Store) en MP...');
        $storeId = null;
        
        // Obtener sucursales
        $storesResp = Http::withToken($token)->get("https://api.mercadopago.com/users/{$userId}/stores/search");
        if ($storesResp->successful() && !empty($storesResp->json('results'))) {
            foreach ($storesResp->json('results') as $store) {
                if ($store['external_id'] === 'SUCURSAL1') {
                    $storeId = $store['id'];
                    break;
                }
            }
            if (!$storeId) {
                 $storeId = $storesResp->json('results.0.id');
            }
        }

        if (!$storeId) {
            $createStore = Http::withToken($token)->post("https://api.mercadopago.com/users/{$userId}/stores", [
                'name' => 'Sucursal Principal',
                'location' => [
                    'street_number' => '123',
                    'street_name' => 'Calle Falsa',
                    'city_name' => 'La Plata',
                    'state_name' => 'Buenos Aires',
                    'latitude' => -34.603722,
                    'longitude' => -58.381592,
                    'reference' => 'Local'
                ],
                'external_id' => 'SUCURSAL1'
            ]);
            
            if ($createStore->successful()) {
                $storeId = $createStore->json('id');
                $this->info("Sucursal creada exitosamente. ID: {$storeId}");
            } else {
                $this->error('Error al crear sucursal: ' . $createStore->body());
                return;
            }
        } else {
            $this->info("Usando Sucursal existente. ID: {$storeId}");
        }

        // 2. Crear o recuperar Caja (POS)
        $this->info('Verificando/Creando Caja (POS) en MP...');
        $posId = null;

        $posSearch = Http::withToken($token)->get("https://api.mercadopago.com/pos?external_id=CAJA1");
        if ($posSearch->successful() && !empty($posSearch->json('results'))) {
            $posId = $posSearch->json('results.0.id');
        }

        if (!$posId) {
            $createPos = Http::withToken($token)->post('https://api.mercadopago.com/pos', [
                'name' => 'Caja Principal POS',
                'fixed_amount' => true,
                'store_id' => $storeId,
                'external_id' => 'CAJA1'
            ]);

            if ($createPos->successful()) {
                $posId = $createPos->json('id');
                $this->info("Caja (POS) creada exitosamente. ID: {$posId} (External ID: CAJA1)");
            } else {
                $this->error('Error al crear Caja (POS): ' . $createPos->body());
                return;
            }
        } else {
            $this->info("Caja (POS) ya existe. ID: {$posId} (External ID: CAJA1)");
        }

        $this->info("\n¡Todo listo! Tu cuenta de Mercado Pago ya tiene Sucursal y Caja ('CAJA1').");
        $this->info("Podés ir al frontend y cobrar con QR sin problema.");
    }
}
