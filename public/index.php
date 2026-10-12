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

// ── DESTRUCTOR DE CACHÉ OTA ──────────────────────────────────────────
// Parche de arranque para limpiar la caché de Laravel si existen archivos
// en bootstrap/cache tras una actualización. Evita errores fantasma.
$cacheFiles = [
    __DIR__.'/../bootstrap/cache/config.php',
    __DIR__.'/../bootstrap/cache/routes.php',
    __DIR__.'/../bootstrap/cache/services.php',
    __DIR__.'/../bootstrap/cache/packages.php',
];
foreach ($cacheFiles as $file) {
    if (file_exists($file)) {
        @unlink($file);
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
