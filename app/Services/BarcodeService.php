<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class BarcodeService
{
    /**
     * Genera un PLU numérico de 5 dígitos secuencial.
     */
    public function generateUniqueInternalCode(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // Fallback para SQLite en entorno de testing (evita REGEXP y CAST AS UNSIGNED)
            $lastCode = Product::withTrashed()
                ->get(['internal_code'])
                ->filter(fn ($p) => ctype_digit($p->internal_code))
                ->max(fn ($p) => (int) $p->internal_code);
            $nextNumber = $lastCode ? $lastCode + 1 : 1;
        } else {
            // Obtener el último código interno numérico (incluso si fue borrado)
            $lastCode = Product::withTrashed()
                ->whereRaw('internal_code REGEXP "^[0-9]+$"')
                ->orderByRaw('CAST(internal_code AS UNSIGNED) DESC')
                ->first();
            $nextNumber = $lastCode ? (int) $lastCode->internal_code + 1 : 1;
        }

        // Si por alguna razón el número ya existe, buscamos el siguiente disponible
        while (Product::withTrashed()->where('internal_code', str_pad($nextNumber, 5, '0', STR_PAD_LEFT))->exists()) {
            $nextNumber++;
        }

        return str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Genera un EAN-13 de uso interno estandarizado.
     * Formato: Prefijo (20) + Relleno (00000) + PLU (5 dígitos) + Checksum (1 dígito)
     */
    public function generateInternalEan13(string $plu): string
    {
        $base = '2000000'.str_pad($plu, 5, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            // Peso 1 para posiciones impares (idx par), Peso 3 para posiciones pares (idx impar)
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;

        return $base.$checksum;
    }
}
