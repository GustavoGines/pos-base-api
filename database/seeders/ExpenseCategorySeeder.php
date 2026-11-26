<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Categorías de gasto base para instalaciones nuevas y restauraciones post-demo.
     *
     * @var array<int, string>
     */
    public const DEFAULT_CATEGORIES = [
        'Mercadería e Insumos',
        'Servicios (Luz, Agua, Internet)',
        'Alquiler e Impuestos',
        'Limpieza y Mantenimiento',
        'Fletes y Cadetería',
        'Varios / Caja Chica',
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_CATEGORIES as $name) {
            ExpenseCategory::firstOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );
        }
    }
}
