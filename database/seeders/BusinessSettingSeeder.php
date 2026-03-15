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
        $settings = [
            ['key' => 'company_name', 'value' => 'Mi Negocio'],
            ['key' => 'currency_symbol', 'value' => '$'],
            ['key' => 'timezone', 'value' => 'America/Argentina/Buenos_Aires'],
            ['key' => 'receipt_footer_message', 'value' => '¡Gracias por su compra!'],
            ['key' => 'tax_id', 'value' => null],
            ['key' => 'logo_path', 'value' => null],
            ['key' => 'address', 'value' => null],
            ['key' => 'phone', 'value' => null],
        ];

        foreach ($settings as $setting) {
            BusinessSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
