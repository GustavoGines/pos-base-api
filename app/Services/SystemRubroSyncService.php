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
        'butcher' => 'Carnicería y Frescos',
        'apparel' => 'Indumentaria y Calzado',
        'tech' => 'Tecnología y Repuestos',
        'general' => 'Bazar y Regalería',
    ];

    /**
     * @param  array<int, string>  $secondaryTypes
     */
    public function sync(?string $businessType, array $secondaryTypes = []): void
    {
        if ($businessType === null || ! isset(self::BUSINESS_TYPE_NAMES[$businessType])) {
            return;
        }

        $targetName = self::BUSINESS_TYPE_NAMES[$businessType];
        $validSecondaries = array_values(array_unique(array_filter(
            $secondaryTypes,
            fn ($t) => is_string($t) && isset(self::BUSINESS_TYPE_NAMES[$t]) && $t !== $businessType
        )));

        DB::transaction(function () use ($businessType, $targetName, $validSecondaries) {
            $lastSynced = BusinessSetting::where('key', self::SYNCED_KEY)->value('value');
            $rubro = Rubro::where('is_system', true)->lockForUpdate()->first();

            if (! $rubro) {
                $this->createSystemRubro($targetName, $businessType);
            } elseif ($rubro->name !== $targetName && $this->shouldRename($rubro, $lastSynced, $businessType)) {
                $this->rename($rubro, $targetName, $businessType);
            } elseif (Rubro::hasExtendedRubroColumns() && $rubro->vertical_type !== $businessType) {
                $rubro->update(['vertical_type' => $businessType]);
            }

            if ($lastSynced !== $businessType) {
                BusinessSetting::updateOrCreate(
                    ['key' => self::SYNCED_KEY],
                    ['value' => $businessType]
                );
            }

            foreach ($validSecondaries as $secType) {
                $this->ensureSecondaryRubro($secType);
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

    private function createSystemRubro(string $targetName, string $businessType): void
    {
        $existing = Rubro::where('name', $targetName)->first();

        if ($existing) {
            // El nombre es único: se promueve el rubro existente en lugar de duplicar.
            $existing->update([
                'is_system' => true,
                'vertical_type' => $businessType,
            ]);

            return;
        }

        Rubro::create([
            'name' => $targetName,
            'vertical_type' => $businessType,
            'is_system' => true,
        ]);
    }

    private function rename(Rubro $rubro, string $targetName, string $businessType): void
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

            if (Rubro::hasExtendedRubroColumns() && $rubro->vertical_type !== $businessType) {
                $rubro->update(['vertical_type' => $businessType]);
            }

            return;
        }

        $rubro->update([
            'name' => $targetName,
            'vertical_type' => $businessType,
        ]);
    }

    private function ensureSecondaryRubro(string $secondaryType): void
    {
        $targetName = self::BUSINESS_TYPE_NAMES[$secondaryType] ?? null;
        if ($targetName === null) {
            return;
        }

        if (Rubro::where('name', $targetName)->exists()) {
            return;
        }

        if (Rubro::hasExtendedRubroColumns() && Rubro::where('vertical_type', $secondaryType)->exists()) {
            return;
        }

        Rubro::create([
            'name' => $targetName,
            'vertical_type' => $secondaryType,
            'is_system' => false,
        ]);
    }
}
