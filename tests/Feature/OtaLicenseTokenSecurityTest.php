<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\LicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\FakesSignedLicenseServer;
use Tests\TestCase;

class OtaLicenseTokenSecurityTest extends TestCase
{
    use FakesSignedLicenseServer;
    use RefreshDatabase;

    public function test_public_settings_masks_license_key_and_exposes_irreversible_ota_token(): void
    {
        $rawKey = 'pos_1234567890abcdef1234567890abcdef';
        BusinessSetting::updateOrCreate(['key' => 'license_key'], ['value' => $rawKey]);
        BusinessSetting::updateOrCreate(['key' => 'license_api_key'], ['value' => $rawKey]);

        $response = $this->getJson('/api/settings');

        $response->assertOk()
            ->assertJsonPath('license_key', 'pos_****cdef')
            ->assertJsonPath('license_ota_token', hash('sha256', 'caja4u-ota-v2:'.$rawKey))
            ->assertJsonMissingPath('license_api_key');

        $this->assertStringNotContainsString($rawKey, $response->getContent());
    }

    public function test_public_settings_without_license_returns_null_key_and_null_ota_token(): void
    {
        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $this->assertNull($response->json('license_key'));
        $this->assertNull($response->json('license_ota_token'));
    }

    public function test_license_sync_persists_ota_fields_and_restore_backup_preserves_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(LicenseSyncService::class);

        $unlockedUntil = now()->addHours(48)->toIso8601String();
        $this->fakeSignedLicenseServer([
            'status'               => 'active',
            'plan'                 => 'basic',
            'plan_type'            => 'lifetime',
            'business_type'        => 'retail',
            'server_time'          => now()->toIso8601String(),
            'expires_at'           => null,
            'allow_ota_updates'    => false,
            'ota_unlocked_until'   => $unlockedUntil,
            'max_unlocked_version' => '2.2.0',
            'features'             => ['fast_pos' => true],
        ]);

        $service->activateManual('pos_lifetime_key_1234567890abcdef');

        $this->assertSame('0', BusinessSetting::where('key', 'license_allow_ota_updates')->value('value'));
        $this->assertSame($unlockedUntil, BusinessSetting::where('key', 'license_ota_unlocked_until')->value('value'));
        $this->assertSame('2.2.0', BusinessSetting::where('key', 'license_max_unlocked_version')->value('value'));
        $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());

        // Generar backup con estado previo y restaurar para verificar preservación de campos OTA
        $backupResponse = $this->actingAsAdmin($admin)->get('/api/system/backup?include_db=1');
        $backupResponse->assertOk();
        $zipPath = $backupResponse->baseResponse->getFile()->getPathname();
        $copiedZip = storage_path('app/private/backups/test_ota_preserve.zip');
        copy($zipPath, $copiedZip);

        try {
            BusinessSetting::updateOrCreate(['key' => 'license_max_unlocked_version'], ['value' => '2.5.0']);
            BusinessSetting::updateOrCreate(['key' => 'license_allow_ota_updates'], ['value' => '1']);

            $uploadedZip = new UploadedFile($copiedZip, 'backup_ota.zip', 'application/zip', null, true);
            $this->actingAsAdmin($admin)->post('/api/system/restore', [
                'backup_file' => $uploadedZip,
            ])->assertOk();

            $this->assertSame('2.5.0', BusinessSetting::where('key', 'license_max_unlocked_version')->value('value'));
            $this->assertSame('1', BusinessSetting::where('key', 'license_allow_ota_updates')->value('value'));
            $this->assertTrue(LicenseSyncService::verifyLocalIntegrity());
        } finally {
            @unlink($copiedZip);
        }
    }
}
