<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// ── BLINDAJE DE ENTORNO EN SERVIDORES COMPARTIDOS (Laragon / Apache mod_php) ──
// Evita que otras aplicaciones web (ej. ecommerce-platform) que ejecutan putenv()
// en el mismo proceso de Apache contaminen las variables de entorno de este backend.
if (file_exists(__DIR__.'/../.env')) {
    foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

// ── GESTOR DE CACHÉ OTA (SIN COLISIONES EN WINDOWS) ──────────────────
// 1. config.php y routes*.php nunca se autogeneran en peticiones HTTP (solo
//    los crea `artisan optimize` en el updater). Se eliminan si existen para
//    respetar siempre el .env en tiempo real y las rutas nuevas tras un OTA.
foreach ([
    __DIR__.'/../bootstrap/cache/config.php',
    __DIR__.'/../bootstrap/cache/routes.php',
    __DIR__.'/../bootstrap/cache/routes-v7.php',
] as $staticCacheFile) {
    if (is_file($staticCacheFile)) {
        @unlink($staticCacheFile);
    }
}

// 2. packages.php y services.php SÍ son regenerados por Laravel en cada request
//    si no existen (escribiendo archivos .tmp y renombrándolos). En Windows, si
//    dos peticiones simultáneas los borran o regeneran al mismo tiempo, chocan
//    con "Acceso denegado (code: 5)". Por eso solo se invalidan cuando cambia
//    la versión o vendor/, serializando su regeneración con flock(LOCK_EX).
$otaMarkerFile = __DIR__.'/../storage/framework/ota_cache_stamp.txt';
$packagesCache = __DIR__.'/../bootstrap/cache/packages.php';
$servicesCache = __DIR__.'/../bootstrap/cache/services.php';
$currentCodeStamp = implode('|', [
    (string) (@filemtime(__DIR__.'/version.txt') ?: '0'),
    (string) (@filemtime(__DIR__.'/../composer.lock') ?: '0'),
    (string) (@filemtime(__DIR__.'/../vendor/composer/installed.json') ?: '0'),
]);

$otaBootLockFp = null;
if (@file_get_contents($otaMarkerFile) !== $currentCodeStamp || ! is_file($packagesCache) || ! is_file($servicesCache)) {
    $otaBootLockFp = @fopen(__DIR__.'/../storage/framework/ota_cache_boot.lock', 'c+');
    if ($otaBootLockFp !== false) {
        @flock($otaBootLockFp, LOCK_EX);
    }

    clearstatcache(true, $otaMarkerFile);
    clearstatcache(true, $packagesCache);
    clearstatcache(true, $servicesCache);

    if (@file_get_contents($otaMarkerFile) !== $currentCodeStamp) {
        foreach ([$packagesCache, $servicesCache] as $manifestFile) {
            if (is_file($manifestFile)) {
                @unlink($manifestFile);
            }
        }
        foreach (glob(__DIR__.'/../bootstrap/cache/*.tmp') ?: [] as $tmpFile) {
            @unlink($tmpFile);
        }
        @file_put_contents($otaMarkerFile, $currentCodeStamp);
    } elseif ($otaBootLockFp !== false && is_file($packagesCache) && is_file($servicesCache)) {
        // Otra petición concurrente ya completó la regeneración mientras esperábamos el lock
        @flock($otaBootLockFp, LOCK_UN);
        @fclose($otaBootLockFp);
        $otaBootLockFp = null;
    }
}
// ─────────────────────────────────────────────────────────────────────

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

if (isset($otaBootLockFp) && is_resource($otaBootLockFp)) {
    @flock($otaBootLockFp, LOCK_UN);
    @fclose($otaBootLockFp);
}
