<?php
// actualizar_env.php
// Este script inyecta las variables de la 1.8.4 en el .env del cliente sin romper su APP_KEY

$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    die("No se encontro el archivo .env");
}

$envContent = file_get_contents($envPath);

// 1. Reemplazos (Buscar y Reemplazar)
$envContent = preg_replace('/^QUEUE_CONNECTION=.*/m', 'QUEUE_CONNECTION=database', $envContent);
$envContent = preg_replace('/^BROADCAST_CONNECTION=.*/m', 'BROADCAST_CONNECTION=reverb', $envContent);

// 2. Bloque de inyección
$nuevasVariables = [
    'LICENSE_SERVER_URL' => 'https://pos-license-server-2jma.onrender.com',
    'REVERB_APP_ID' => '110434',
    'REVERB_APP_KEY' => 'kz786cdfeldnzispymxq',
    'REVERB_APP_SECRET' => 'eizqkp4itcvqit97npqn',
    'REVERB_HOST' => '127.0.0.1',
    'REVERB_PORT' => '8080',
    'REVERB_SCHEME' => 'http',
    'VITE_REVERB_APP_KEY' => '"${REVERB_APP_KEY}"',
    'VITE_REVERB_HOST' => '"${REVERB_HOST}"',
    'VITE_REVERB_PORT' => '"${REVERB_PORT}"',
    'VITE_REVERB_SCHEME' => '"${REVERB_SCHEME}"',
    'RESCUE_MIGRATE_SECRET' => 'pos-rescue-2026-GGLabs'
];

$appendString = "\n\n# --- Variables inyectadas por Actualizacion 1.8.4 ---\n";
$needsAppend = false;

foreach ($nuevasVariables as $key => $value) {
    // Si la variable no existe en el archivo, la preparamos para agregarla
    if (strpos($envContent, $key . '=') === false) {
        $appendString .= "{$key}={$value}\n";
        $needsAppend = true;
    }
}

// Guardar cambios
if ($needsAppend) {
    file_put_contents($envPath, $envContent . $appendString);
} else {
    file_put_contents($envPath, $envContent);
}

echo "El archivo .env ha sido actualizado con exito.\n";
