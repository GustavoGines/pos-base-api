<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name'            => 'Efectivo',
                'code'            => 'cash',
                'surcharge_type'  => 'none',
                'surcharge_value' => 0,
                'is_cash'         => true,
                'is_active'       => true,
                'sort_order'      => 1,
            ],
            [
                'name'            => 'Tarjeta Débito',
                'code'            => 'card_debit',
                'surcharge_type'  => 'none',
                'surcharge_value' => 0,
                'is_cash'         => false,
                'is_active'       => true,
                'sort_order'      => 2,
            ],
            [
                'name'            => 'Tarjeta Crédito',
                'code'            => 'card_credit',
                'surcharge_type'  => 'percent',
                'surcharge_value' => 15,
                'is_cash'         => false,
                'is_active'       => true,
                'sort_order'      => 3,
            ],
            [
                'name'            => 'Transferencia',
                'code'            => 'transfer',
                'surcharge_type'  => 'none',
                'surcharge_value' => 0,
                'is_cash'         => false,
                'is_active'       => true,
                'sort_order'      => 4,
            ],
            [
                'name'            => 'Cuenta Corriente',
                'code'            => 'cuenta_corriente',
                'surcharge_type'  => 'none',
                'surcharge_value' => 0,
                'is_cash'         => false,
                'is_active'       => true,
                'sort_order'      => 5,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(['code' => $method['code']], $method);
        }
    }
}
