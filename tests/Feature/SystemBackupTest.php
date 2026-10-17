<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Extrae el path del .zip temporal generado por el BinaryFileResponse.
     * En tests el archivo no se borra porque send() no se ejecuta realmente.
     */
    private function extractZipPath($response): string
    {
        $file = $response->baseResponse->getFile();
        $this->assertFileExists($file->getPathname());

        return $file->getPathname();
    }

    /**
     * Abre el .zip y devuelve el listado de entradas contenidas.
     *
     * @return string[]
     */
    private function listZipEntries(string $zipPath): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true, 'El archivo de respaldo no es un .zip válido');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $entries;
    }

    private function readZipEntry(string $zipPath, string $entryName): ?string
    {
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $content = $zip->getFromName($entryName);
        $zip->close();

        return $content === false ? null : $content;
    }

    protected function tearDown(): void
    {
        // Limpieza de .zip y .sql temporales generados durante los tests
        $backupDir = storage_path('app/private/backups');
        if (is_dir($backupDir)) {
            foreach (glob($backupDir.DIRECTORY_SEPARATOR.'*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($backupDir);
        }

        parent::tearDown();
    }

    public function test_backup_requires_authentication(): void
    {
        $this->getJson('/api/system/backup')->assertUnauthorized();
    }

    public function test_backup_forbidden_for_cashier_without_permission(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'pin' => Hash::make('1234'),
            'permissions' => [],
        ]);
        $token = 'cashier-session-'.uniqid();
        DB::table('users')->where('id', $cashier->id)->update(['session_token' => $token]);

        $this->withHeader('X-Session-Token', $token)
            ->getJson('/api/system/backup')
            ->assertForbidden();
    }

    public function test_backup_zip_contains_sql_dump_and_manifest(): void
    {
        Product::create([
            'name' => 'Producto Backup Test',
            'internal_code' => 'BKP-001',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'active' => true,
        ]);

        $response = $this->actingAsAdmin()->get('/api/system/backup');

        $response->assertOk();
        $this->assertStringContainsString(
            'Backup_POS_',
            (string) $response->headers->get('content-disposition')
        );

        $zipPath = $this->extractZipPath($response);
        $entries = $this->listZipEntries($zipPath);

        $this->assertContains('database_backup.sql', $entries);
        $this->assertContains('backup_info.json', $entries);

        $sql = $this->readZipEntry($zipPath, 'database_backup.sql');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('Producto Backup Test', $sql);

        $manifest = json_decode((string) $this->readZipEntry($zipPath, 'backup_info.json'), true);
        $this->assertSame('sqlite', $manifest['db_driver']);
        $this->assertContains('database', $manifest['modules']);
    }

    public function test_backup_without_db_excludes_sql_dump(): void
    {
        $response = $this->actingAsAdmin()->get('/api/system/backup?include_db=0');
        $response->assertOk();

        $entries = $this->listZipEntries($this->extractZipPath($response));
        $this->assertNotContains('database_backup.sql', $entries);
        $this->assertContains('backup_info.json', $entries);
    }

    public function test_backup_include_images_adds_public_storage_files(): void
    {
        $publicDir = storage_path('app/public/products');
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        file_put_contents($publicDir.'/test_image.txt', 'fake-image-bytes');

        try {
            $response = $this->actingAsAdmin()->get('/api/system/backup?include_images=1');
            $response->assertOk();

            $entries = $this->listZipEntries($this->extractZipPath($response));
            $this->assertContains('storage/public/products/test_image.txt', $entries);
        } finally {
            @unlink($publicDir.'/test_image.txt');
            @rmdir($publicDir);
        }
    }

    public function test_backup_include_afip_adds_certificate_files(): void
    {
        $afipDir = storage_path('app/private/afip/20123456789');
        if (! is_dir($afipDir)) {
            mkdir($afipDir, 0755, true);
        }
        file_put_contents($afipDir.'/cert.crt', 'fake-cert');

        try {
            $response = $this->actingAsAdmin()->get('/api/system/backup?include_afip=1');
            $response->assertOk();

            $entries = $this->listZipEntries($this->extractZipPath($response));
            $this->assertContains('afip/20123456789/cert.crt', $entries);
        } finally {
            @unlink($afipDir.'/cert.crt');
            @rmdir($afipDir);
            $base = storage_path('app/private/afip');
            if (is_dir($base) && count(scandir($base)) === 2) {
                @rmdir($base);
            }
        }
    }
}
