<?php
$path = 'app/Http/Controllers/Api/PosController.php';
$content = file_get_contents($path);
$content = str_replace(
    'broadcast(new \App\Events\DashboardUpdated());',
    'try { broadcast(new \App\Events\DashboardUpdated()); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error("Broadcast failed: " . $e->getMessage()); }',
    $content
);
file_put_contents($path, $content);

$path2 = 'app/Http/Controllers/Api/SalesController.php';
$content2 = file_get_contents($path2);
$content2 = str_replace(
    'broadcast(new \App\Events\DashboardUpdated());',
    'try { broadcast(new \App\Events\DashboardUpdated()); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error("Broadcast failed: " . $e->getMessage()); }',
    $content2
);
file_put_contents($path2, $content2);
echo "Wrapped broadcasts";
