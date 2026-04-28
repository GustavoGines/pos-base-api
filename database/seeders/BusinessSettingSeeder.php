<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use Illuminate\Database\Seeder;

class BusinessSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ⚠️  IMPORTANTE: NO agregar claves de licencia aquí.
        // installation_id, license_key, app_plan, last_license_check, etc.
        // son generadas/activadas automáticamente por el sistema al primer uso.
        $settings = [
            ['key' => 'company_name',           'value' => 'Mi Negocio'],
            ['key' => 'currency_symbol',         'value' => '$'],
            ['key' => 'timezone',                'value' => 'America/Argentina/Buenos_Aires'],
            ['key' => 'receipt_footer_message',  'value' => '¡Gracias por su compra!'],
            ['key' => 'tax_id',                  'value' => null],
            ['key' => 'logo_path',               'value' => null],
            ['key' => 'address',                 'value' => null],
            ['key' => 'phone',                   'value' => null],
            ['key' => 'printer_type',            'value' => 'usb'],
            ['key' => 'printer_paper_width',     'value' => '80mm'],
            ['key' => 'printer_com_port',        'value' => 'COM1'],
            ['key' => 'printer_ip_address',      'value' => null],
            ['key' => 'printer_ip_port',         'value' => null],
            ['key' => 'com_port_scale',          'value' => null],
        ];

        foreach ($settings as $setting) {
            BusinessSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
