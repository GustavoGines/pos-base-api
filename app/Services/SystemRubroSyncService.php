<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Rubro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene el nombre del Rubro principal del sistema (rubros.is_system = true)
 * alineado con el tipo de negocio que define el servidor de licencias.
 *
 * Reglas:
 *  - Solo se renombra cuando el tipo de negocio CAMBIA respecto del último sincronizado
 *    (clave `rubro_synced_business_type`), de modo que un nombre editado a mano por un
 *    cliente Premium no se pisa en cada heartbeat.
 *  - En la primera ejecución (sin tipo previo registrado) solo se renombra si el nombre
 *    actual es uno de los nombres por defecto, es decir, si nadie lo editó.
 *  - Un tipo de negocio desconocido se ignora sin tocar nada.
 *  - Nunca toca categorías ni productos.
 */
class SystemRubroSyncService
{
    public const SYNCED_KEY = 'rubro_synced_business_type';

    /**
     * Tipos de negocio válidos del servidor de licencias => nombre del rubro principal.
     */
    public const BUSINESS_TYPE_NAMES = [
        'hardware_store' => 'Ferretería',
        'retail' => 'Comercio General',
    ];

    public function sync(?string $businessType): void
    {
        if ($businessType === null || ! isset(self::BUSINESS_TYPE_NAMES[$businessType])) {
            return;
        }

        $targetName = self::BUSINESS_TYPE_NAMES[$businessType];

        DB::transaction(function () use ($businessType, $targetName) {
            $lastSynced = BusinessSetting::where('key', self::SYNCED_KEY)->value('value');
            $rubro = Rubro::where('is_system', true)->lockForUpdate()->first();

            if (! $rubro) {
                $this->createSystemRubro($targetName);
            } elseif ($rubro->name !== $targetName && $this->shouldRename($rubro, $lastSynced, $businessType)) {
                $this->rename($rubro, $targetName);
            }

            if ($lastSynced !== $businessType) {
                BusinessSetting::updateOrCreate(
                    ['key' => self::SYNCED_KEY],
                    ['value' => $businessType]
                );
            }
        });
    }

    private function shouldRename(Rubro $rubro, ?string $lastSynced, string $businessType): bool
    {
        if ($lastSynced === $businessType) {
            // Mismo tipo de negocio que la última vez: respeta cualquier edición manual.
            return false;
        }

        if ($lastSynced === null) {
            // Primera sincronización: solo renombra si sigue con un nombre por defecto.
            return in_array($rubro->name, self::BUSINESS_TYPE_NAMES, true);
        }

        // El tipo de negocio cambió en el servidor de licencias.
        return true;
    }

    private function createSystemRubro(string $targetName): void
    {
        $existing = Rubro::where('name', $targetName)->first();

        if ($existing) {
            // El nombre es único: se promueve el rubro existente en lugar de duplicar.
            $existing->update(['is_system' => true]);

            return;
        }

        Rubro::create(['name' => $targetName, 'is_system' => true]);
    }

    private function rename(Rubro $rubro, string $targetName): void
    {
        $conflict = Rubro::where('name', $targetName)
            ->where('id', '!=', $rubro->id)
            ->exists();

        if ($conflict) {
            // `rubros.name` es único: no se fuerza el renombre para no violar la restricción.
            Log::warning('SystemRubroSync: no se renombró el rubro principal porque ya existe otro rubro con ese nombre.', [
                'rubro_id' => $rubro->id,
                'target_name' => $targetName,
            ]);

            return;
        }

        $rubro->update(['name' => $targetName]);
    }
}
