<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

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
