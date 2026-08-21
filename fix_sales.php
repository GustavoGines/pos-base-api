<?php
$path = 'app/Http/Controllers/Api/SalesController.php';
$content = file_get_contents($path);
$content = str_replace(
    '        return response()->json([' . "\n" . '            \'message\' => "Venta #{$completedSale->id} cobrada correctamente.",',
    '        broadcast(new \App\Events\DashboardUpdated());' . "\n\n" . '        return response()->json([' . "\n" . '            \'message\' => "Venta #{$completedSale->id} cobrada correctamente.",',
    $content
);

$content = str_replace(
    '        return response()->json([' . "\n" . '            \'message\' => "Venta #{$voidedSale->id} anulada correctamente. El stock fue restaurado.",',
    '        broadcast(new \App\Events\DashboardUpdated());' . "\n\n" . '        return response()->json([' . "\n" . '            \'message\' => "Venta #{$voidedSale->id} anulada correctamente. El stock fue restaurado.",',
    $content
);
file_put_contents($path, $content);
echo "Done";
