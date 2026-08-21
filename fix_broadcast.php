<?php
// Fix PosController
$path = 'app/Http/Controllers/Api/PosController.php';
$content = file_get_contents($path);
$content = preg_replace("/return response\(\)->json\(\[\s*'message' => 'Sale processed successfully'/m", "broadcast(new \App\Events\DashboardUpdated());\n\n            return response()->json([\n                'message' => 'Sale processed successfully'", $content);
file_put_contents($path, $content);

// Fix SalesController
$path2 = 'app/Http/Controllers/Api/SalesController.php';
$content2 = file_get_contents($path2);
$content2 = preg_replace("/return response\(\)->json\(\[\s*'message' => \"Venta #\{\\$completedSale->id\} cobrada correctamente\.\"/m", "broadcast(new \App\Events\DashboardUpdated());\n\n        return response()->json([\n            'message' => \"Venta #{\$completedSale->id} cobrada correctamente.\"", $content2);
$content2 = preg_replace("/return response\(\)->json\(\[\s*'message' => \"Venta #\{\\$voidedSale->id\} anulada correctamente\. El stock fue restaurado\.\"/m", "broadcast(new \App\Events\DashboardUpdated());\n\n        return response()->json([\n            'message' => \"Venta #{\$voidedSale->id} anulada correctamente. El stock fue restaurado.\"", $content2);
file_put_contents($path2, $content2);
echo "Done";
