<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemController extends Controller
{
    public function rescueMigrate(Request $request)
    {
        $secret = config('app.rescue_migrate_secret');

        // Fail-Secure: si no hay secreto configurado o el token no coincide exactamente, abortar 403
        if (empty($secret) || $request->header('X-Rescue-Token') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        Artisan::call('migrate', ['--force' => true]);

        return response()->json(['success' => true, 'output' => Artisan::output()]);
    }

    public function versionCheck()
    {
        $path = public_path('version.txt');
        clearstatcache(true, $path);
        $version = '0.0.0';
        if (file_exists($path)) {
            $version = trim(file_get_contents($path));
        }

        return response()->json(['version' => $version])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function installPath()
    {
        return response()->json([
            'install_path' => base_path(),
            'backend_path' => base_path(),
        ]);
    }

    /**
     * Genera una copia de seguridad en formato .zip y la transmite como descarga binaria.
     *
     * El volcado SQL se produce con PDO nativo (sin depender de mysqldump en el PATH),
     * por lo que funciona en cualquier PC Windows del cliente sin configuración extra.
     *
     * Query params:
     *  - include_db     (default true)  → dump SQL de todas las tablas (database_backup.sql)
     *  - include_images (default false) → fotos de productos y logo (storage/app/public)
     *  - include_afip   (default false) → certificados fiscales ARCA/AFIP
     */
    public function downloadBackup(Request $request)
    {
        $includeDb = $request->boolean('include_db', true);
        $includeImages = $request->boolean('include_images', false);
        $includeAfip = $request->boolean('include_afip', false);

        if (! class_exists(\ZipArchive::class)) {
            return response()->json([
                'message' => 'La extensión PHP ZipArchive no está disponible en el servidor.',
                'error_code' => 'ZIP_EXTENSION_MISSING',
            ], 500);
        }

        $tmpDir = storage_path('app/private/backups');
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0755, true)) {
            return response()->json(['message' => 'No se pudo crear el directorio temporal de respaldos.'], 500);
        }

        $zipPath = $tmpDir.'/pos_backup_'.now()->format('Ymd_His').'_'.Str::random(8).'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'No se pudo crear el archivo .zip de respaldo.'], 500);
        }

        $dumpTempPath = null;
        $modules = [];

        try {
            if ($includeDb) {
                $dumpTempPath = $tmpDir.'/database_backup_'.Str::random(8).'.sql';
                $this->writeSqlDump($dumpTempPath);
                $zip->addFile($dumpTempPath, 'database_backup.sql');
                $modules[] = 'database';
            }

            if ($includeImages) {
                $added = $this->addDirectoryToZip($zip, storage_path('app/public'), 'storage/public');
                if ($added > 0) {
                    $modules[] = 'images';
                }
            }

            if ($includeAfip) {
                $added = 0;
                foreach ([storage_path('app/private/afip'), storage_path('app/afip')] as $afipDir) {
                    $added += $this->addDirectoryToZip($zip, $afipDir, 'afip');
                }
                if ($added > 0) {
                    $modules[] = 'afip_certificates';
                }
            }

            $versionPath = public_path('version.txt');
            $manifest = [
                'created_at' => now()->toIso8601String(),
                'company_name' => (string) (BusinessSetting::where('key', 'company_name')->value('value') ?? ''),
                'app_version' => file_exists($versionPath) ? trim((string) file_get_contents($versionPath)) : '0.0.0',
                'db_driver' => DB::connection()->getDriverName(),
                'modules' => $modules,
            ];
            $zip->addFromString('backup_info.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
        } catch (\Throwable $e) {
            $zip->close();
            report($e);

            return response()->json([
                'message' => 'Error al generar la copia de seguridad: '.$e->getMessage(),
                'error_code' => 'BACKUP_FAILED',
            ], 500);
        } finally {
            if ($dumpTempPath !== null && file_exists($dumpTempPath)) {
                @unlink($dumpTempPath);
            }
        }

        $fileName = 'Backup_POS_'.now()->format('Y-m-d_Hi').'.zip';

        return response()->download($zipPath, $fileName, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Agrega recursivamente los archivos de un directorio al .zip. Devuelve la cantidad agregada.
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPrefix): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = $zipPrefix.'/'.substr($file->getPathname(), strlen($dir) + 1);
            $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relative));
            $count++;
        }

        return $count;
    }

    /**
     * Escribe un dump SQL completo (CREATE TABLE + INSERT por lotes) usando solo PDO.
     * Soporta MySQL y SQLite; escapa valores con PDO::quote para máxima portabilidad.
     */
    private function writeSqlDump(string $targetPath): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $isMysql = $driver === 'mysql';

        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('No se pudo escribir el volcado SQL temporal.');
        }

        try {
            fwrite($out, "-- POS System Backup\n");
            fwrite($out, '-- Generado: '.now()->toIso8601String()."\n");
            fwrite($out, "-- Driver: {$driver}\n\n");
            fwrite($out, $isMysql
                ? "SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n"
                : "PRAGMA foreign_keys=OFF;\n\n");

            $tables = $isMysql ? $this->listMysqlTables($pdo) : $this->listSqliteTables($pdo);

            // MySQL: desactivar buffering para streamear tablas grandes sin agotar memoria.
            if ($isMysql) {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }

            try {
                foreach ($tables as $table) {
                    $createSql = $isMysql
                        ? $this->mysqlCreateTableSql($pdo, $table)
                        : $this->sqliteCreateTableSql($pdo, $table);

                    fwrite($out, "-- ---------------------------------------------------------\n");
                    fwrite($out, "-- Tabla: {$table}\n");
                    fwrite($out, "-- ---------------------------------------------------------\n");
                    fwrite($out, $isMysql
                        ? "DROP TABLE IF EXISTS `{$table}`;\n"
                        : "DROP TABLE IF EXISTS \"{$table}\";\n");
                    if ($createSql !== null && $createSql !== '') {
                        fwrite($out, rtrim($createSql, ';').";\n\n");
                    }

                    $this->writeTableRows($pdo, $out, $table, $isMysql);
                    fwrite($out, "\n");
                }
            } finally {
                if ($isMysql) {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }

            fwrite($out, $isMysql
                ? "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n"
                : "PRAGMA foreign_keys=ON;\n");
        } finally {
            fclose($out);
        }
    }

    /** @return string[] */
    private function listMysqlTables(\PDO $pdo): array
    {
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }

        return $tables;
    }

    /** @return string[] */
    private function listSqliteTables(\PDO $pdo): array
    {
        $tables = [];
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }

        return $tables;
    }

    private function mysqlCreateTableSql(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? (string) ($row['Create Table'] ?? $row['Create View'] ?? '') : null;
    }

    private function sqliteCreateTableSql(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        $sql = $stmt->fetchColumn();

        return $sql === false ? null : (string) $sql;
    }

    private function writeTableRows(\PDO $pdo, $out, string $table, bool $isMysql): void
    {
        $quotedTable = $isMysql ? "`{$table}`" : "\"{$table}\"";
        $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
        if ($stmt === false) {
            return;
        }

        $columns = null;
        $batch = [];

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $batch[] = $row;
            if (count($batch) >= 200) {
                $this->flushInsertBatch($pdo, $out, $table, $columns, $batch, $isMysql);
                $batch = [];
            }
        }
        if ($columns !== null && ! empty($batch)) {
            $this->flushInsertBatch($pdo, $out, $table, $columns, $batch, $isMysql);
        }
        $stmt->closeCursor();
    }

    /**
     * @param  string[]  $columns
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function flushInsertBatch(\PDO $pdo, $out, string $table, array $columns, array $batch, bool $isMysql): void
    {
        $quotedCols = implode(', ', array_map(
            fn ($c) => $isMysql ? "`{$c}`" : "\"{$c}\"",
            $columns
        ));
        $quotedTable = $isMysql ? "`{$table}`" : "\"{$table}\"";

        $valuesSql = [];
        foreach ($batch as $row) {
            $values = [];
            foreach ($columns as $col) {
                $values[] = $this->quoteValue($pdo, $row[$col] ?? null);
            }
            $valuesSql[] = '('.implode(', ', $values).')';
        }

        fwrite($out, "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES\n");
        fwrite($out, implode(",\n", $valuesSql).";\n");
    }

    private function quoteValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }
}
