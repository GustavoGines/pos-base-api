<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\User;
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

    public function installPath(Request $request)
    {
        $isLoopback = in_array($request->ip(), ['127.0.0.1', '::1', 'localhost'], true);

        if (! $isLoopback) {
            $token = $request->header('X-Session-Token') ?? $request->bearerToken();
            $hasValidSession = $request->user() !== null
                || ($request->attributes->get('authenticated_user') !== null)
                || (is_string($token) && trim($token) !== '' && User::withoutGlobalScope('visible')->where('session_token', trim($token))->exists());

            if (! $hasValidSession) {
                return response()->json([
                    'message' => 'No autorizado para consultar rutas del servidor desde una IP remota sin sesión activa.',
                ], 403);
            }
        }

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
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

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
        // Solo tablas físicas: SHOW TABLES también devuelve vistas (VIEW),
        // que no se pueden exportar con SHOW CREATE TABLE + INSERT INTO.
        $tables = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
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
        $stmt->closeCursor();

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
        if ($isMysql) {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
            if ($stmt === false) {
                return;
            }

            try {
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
            } finally {
                $stmt->closeCursor();
            }
        } finally {
            if ($isMysql) {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
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

    /**
     * Restaura una copia de seguridad desde un archivo .zip subido.
     *
     * El archivo debe contener:
     *  - database_backup.sql (dump SQL completo)
     *  - backup_info.json (metadatos del backup)
     *  - storage/public/ (imágenes de productos y logo, opcional)
     *  - afip/ o storage/afip/ (certificados fiscales ARCA/AFIP, opcional)
     *
     * Protecciones:
     *  - Validación de estructura del ZIP
     *  - Zip-Slip protection (rutas absolutas y ..)
     *  - Snapshot previo automático
     *  - Preservación de licencia local y sesión activa
     */
    public function restoreBackup(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $request->validate([
            'backup_file' => 'required|file|mimes:zip|max:512000', // 512 MB max
        ]);

        if (! class_exists(\ZipArchive::class)) {
            return response()->json([
                'message' => 'La extensión PHP ZipArchive no está disponible en el servidor.',
                'error_code' => 'ZIP_EXTENSION_MISSING',
            ], 500);
        }

        $file = $request->file('backup_file');
        $tmpDir = storage_path('app/private/restore_temp');
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0755, true)) {
            return response()->json(['message' => 'No se pudo crear el directorio temporal de restauración.'], 500);
        }

        $zipPath = $tmpDir.'/upload_'.Str::random(16).'.zip';
        $file->move($tmpDir, basename($zipPath));

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);

            return response()->json(['message' => 'No se pudo abrir el archivo .zip.'], 400);
        }

        // Validar estructura del ZIP y protección Zip-Slip
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_contains($entryName, '../') || str_starts_with($entryName, '/') || str_contains($entryName, ':')) {
                $zip->close();
                @unlink($zipPath);

                return response()->json(['message' => 'El archivo .zip contiene rutas no permitidas.'], 400);
            }
        }

        $hasBackupInfo = $zip->locateName('backup_info.json') !== false;
        $hasDbDump = $zip->locateName('database_backup.sql') !== false;
        $hasModuleFiles = $hasDbDump;

        if (! $hasModuleFiles) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (! str_ends_with($entryName, '/') && (
                    str_starts_with($entryName, 'storage/public/') ||
                    str_starts_with($entryName, 'afip/') ||
                    str_starts_with($entryName, 'storage/afip/')
                )) {
                    $hasModuleFiles = true;
                    break;
                }
            }
        }

        if (! $hasBackupInfo || ! $hasModuleFiles) {
            $zip->close();
            @unlink($zipPath);

            return response()->json(['message' => 'El archivo .zip no es un backup válido (falta database_backup.sql o backup_info.json).'], 400);
        }

        $extractDir = null;

        try {
            $infoContent = $zip->getFromName('backup_info.json');
            $backupInfo = $infoContent !== false ? (json_decode($infoContent, true) ?? []) : [];

            $snapshotPath = null;
            $tablesRestored = 0;

            if ($hasDbDump) {
                // Generar snapshot previo de seguridad antes de tocar la base de datos
                $snapshotPath = $tmpDir.'/pre_restore_safety_'.now()->format('Ymd_His').'.sql';
                $this->writeSqlDump($snapshotPath);

                $sqlContent = $zip->getFromName('database_backup.sql');
                if ($sqlContent === false || trim($sqlContent) === '') {
                    throw new \RuntimeException('No se pudo leer database_backup.sql del ZIP.');
                }

                // Resguardar licencia local y sesión del usuario autenticado antes de restaurar
                $localLicense = [];
                $localLicenseFields = [
                    'license_key',
                    'license_status',
                    'installation_hash',
                    'installation_id',
                    'last_license_check',
                    'license_features_dict',
                    'license_plan_mode',
                    'app_plan',
                    'license_expires_at',
                    'license_server_time_offset',
                    'license_server_time',
                    'license_integrity_hash',
                    'license_is_lifetime',
                    'license_business_type',
                ];
                foreach ($localLicenseFields as $field) {
                    $localLicense[$field] = BusinessSetting::where('key', $field)->value('value');
                }

                $currentUser = $request->user();
                $currentUserId = $currentUser?->id;
                $currentSessionToken = $currentUser?->session_token ?? $request->header('X-Session-Token');

                // Ejecutar dump SQL
                $pdo = DB::connection()->getPdo();
                $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
                $isMysql = $driver === 'mysql';

                if ($isMysql) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                } else {
                    $pdo->exec('PRAGMA foreign_keys=OFF');
                }

                $statements = $this->parseSqlStatements($sqlContent);

                try {
                    foreach ($statements as $stmt) {
                        if (preg_match('/^\s*CREATE\s+TABLE/i', $stmt)) {
                            $tablesRestored++;
                        }
                        $pdo->exec($stmt);
                    }
                    if ($snapshotPath !== null && file_exists($snapshotPath)) {
                        @unlink($snapshotPath);
                    }
                } catch (\Throwable $sqlException) {
                    if ($snapshotPath !== null && file_exists($snapshotPath)) {
                        try {
                            $rollbackContent = file_get_contents($snapshotPath);
                            if (is_string($rollbackContent) && trim($rollbackContent) !== '') {
                                foreach ($this->parseSqlStatements($rollbackContent) as $rbStmt) {
                                    $pdo->exec($rbStmt);
                                }
                            }
                        } catch (\Throwable $rollbackException) {
                            report($rollbackException);
                        }
                    }

                    throw $sqlException;
                } finally {
                    if ($isMysql) {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    } else {
                        $pdo->exec('PRAGMA foreign_keys=ON');
                    }
                }

                // Restaurar licencia local si existía
                if (! empty(array_filter($localLicense, fn ($v) => $v !== null && $v !== ''))) {
                    foreach ($localLicense as $key => $value) {
                        if ($value !== null) {
                            BusinessSetting::updateOrCreate(['key' => $key], ['value' => $value]);
                        }
                    }
                }

                // Preservar el session_token del usuario actual para no desconectar su sesión activa
                if ($currentUserId !== null && ! empty($currentSessionToken)) {
                    DB::table('users')
                        ->where('id', $currentUserId)
                        ->update(['session_token' => $currentSessionToken]);
                }
            }

            // Restaurar imágenes y certificados AFIP si existen en el ZIP
            $imagesRestored = 0;
            $afipRestored = 0;
            $publicPrefix = 'storage/public/';
            $afipPrefix = 'afip/';
            $legacyAfipPrefix = 'storage/afip/';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (str_ends_with($filename, '/')) {
                    continue;
                }

                if (str_starts_with($filename, $publicPrefix)) {
                    $relPath = substr($filename, strlen($publicPrefix));
                    if ($relPath === '') {
                        continue;
                    }
                    $targetPath = storage_path('app/public/'.$relPath);
                    $targetDir = dirname($targetPath);
                    if (! is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        file_put_contents($targetPath, $content);
                        $imagesRestored++;
                    }
                } elseif (str_starts_with($filename, $afipPrefix) || str_starts_with($filename, $legacyAfipPrefix)) {
                    $relPath = str_starts_with($filename, $afipPrefix)
                        ? substr($filename, strlen($afipPrefix))
                        : substr($filename, strlen($legacyAfipPrefix));
                    if ($relPath === '') {
                        continue;
                    }
                    $targetPath = storage_path('app/private/afip/'.$relPath);
                    $targetDir = dirname($targetPath);
                    if (! is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        file_put_contents($targetPath, $content);
                        $afipRestored++;
                    }
                }
            }

            // Limpiar directorios temporales
            if ($extractDir !== null && is_dir($extractDir)) {
                $this->recursiveDelete($extractDir);
            }
            $zip->close();
            @unlink($zipPath);

            return response()->json([
                'message' => 'Copia de seguridad restaurada exitosamente.',
                'backup_info' => $backupInfo,
                'tables_restored' => $tablesRestored,
                'images_restored' => $imagesRestored,
                'afip_restored' => $afipRestored,
                'snapshot_path' => $snapshotPath,
            ]);
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            if ($extractDir !== null && is_dir($extractDir)) {
                $this->recursiveDelete($extractDir);
            }
            report($e);

            return response()->json([
                'message' => 'Error al restaurar la copia de seguridad: '.$e->getMessage(),
                'error_code' => 'RESTORE_FAILED',
            ], 500);
        }
    }

    /**
     * Parsea un archivo SQL en sentencias individuales, ignorando comentarios (-- y /* * /)
     * fuera de cadenas literales y respetando escapes (\' y '').
     *
     * @return string[]
     */
    private function parseSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === '\\' && $i + 1 < $len) {
                    $current .= $char.$sql[$i + 1];
                    $i++;

                    continue;
                }
                if ($char === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                    // Escape estándar SQL ('')
                    $current .= "''";
                    $i++;

                    continue;
                }
                if ($char === "'") {
                    $inString = false;
                }
                $current .= $char;

                continue;
            }

            // Comentario de línea (-- ...)
            if ($char === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $newlinePos = strpos($sql, "\n", $i + 2);
                if ($newlinePos === false) {
                    break;
                }
                $i = $newlinePos;

                continue;
            }

            // Comentario de bloque (/* ... */)
            if ($char === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $endCommentPos = strpos($sql, '*/', $i + 2);
                if ($endCommentPos === false) {
                    break;
                }
                $i = $endCommentPos + 1;

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;

                continue;
            }

            if ($char === ';') {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    private function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
