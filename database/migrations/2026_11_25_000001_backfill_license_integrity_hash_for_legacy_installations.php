<?php

use App\Services\LicenseSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill idempotente de `license_integrity_hash` para instalaciones legítimas heredadas de v1.8.5
     * (especialmente licencias Pago Único / Vitalicia `lifetime` que operan offline y no poseían sello HMAC),
     * y limpieza de la clave obsoleta `license_plan`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('business_settings')) {
            return;
        }

        // Eliminar cualquier clave obsoleta `license_plan` que pudiera desincronizarse frente a `app_plan`
        DB::table('business_settings')->where('key', 'license_plan')->delete();

        $settings = DB::table('business_settings')
            ->whereIn('key', [
                'license_key',
                'license_api_key',
                'license_integrity_hash',
                'app_plan',
                'license_plan_mode',
                'license_is_lifetime',
                'license_features_dict',
            ])
            ->pluck('value', 'key');

        $licenseKey = trim((string) ($settings['license_key'] ?? $settings['license_api_key'] ?? ''));
        $storedSeal = trim((string) ($settings['license_integrity_hash'] ?? ''));
        $plan = trim((string) ($settings['app_plan'] ?? 'basic'));
        if ($plan === '') {
            $plan = 'basic';
        }

        if ($licenseKey === '' || strtolower($plan) === 'blocked' || $storedSeal !== '') {
            return;
        }

        $planMode = trim((string) ($settings['license_plan_mode'] ?? 'saas'));
        if ($planMode === '') {
            $planMode = 'saas';
        }

        $isLifetime = trim((string) ($settings['license_is_lifetime'] ?? ($planMode === 'lifetime' ? '1' : '0')));
        if ($isLifetime === '') {
            $isLifetime = strtolower($planMode) === 'lifetime' ? '1' : '0';
        }

        $featuresJson = (string) ($settings['license_features_dict'] ?? '{}');
        if (trim($featuresJson) === '') {
            $featuresJson = '{}';
        }

        $seal = LicenseSyncService::computeLocalIntegritySeal(
            $plan,
            $planMode,
            $isLifetime,
            $featuresJson
        );

        $now = now();
        $exists = DB::table('business_settings')->where('key', 'license_integrity_hash')->exists();
        if ($exists) {
            DB::table('business_settings')
                ->where('key', 'license_integrity_hash')
                ->update([
                    'value' => $seal,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('business_settings')->insert([
                'key' => 'license_integrity_hash',
                'value' => $seal,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // No se borra license_integrity_hash en rollback para no invalidar licencias activas.
    }
};
