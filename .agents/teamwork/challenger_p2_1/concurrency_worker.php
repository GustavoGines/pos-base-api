<?php

/**
 * Concurrency Worker Process
 *
 * Usage: php concurrency_worker.php <test_case> <worker_id> <sync_timestamp>
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Ensure working on stress database
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'sistema_pos_stress_test',
]);
\Illuminate\Support\Facades\DB::purge('mysql');
\Illuminate\Support\Facades\DB::reconnect('mysql');
\Illuminate\Support\Facades\DB::setDefaultConnection('mysql');

$testCase = $argv[1] ?? null;
$workerId = $argv[2] ?? 0;
$syncTimestamp = (float) ($argv[3] ?? 0);

if (!$testCase) {
    echo json_encode(['error' => 'Missing test_case']);
    exit(1);
}

// Barrier synchronization: wait until syncTimestamp
if ($syncTimestamp > 0) {
    while (microtime(true) < $syncTimestamp) {
        usleep(100);
    }
}

$startTime = microtime(true);
$result = ['worker_id' => $workerId, 'start_time' => $startTime];

try {
    switch ($testCase) {
        case 'quote_store':
            $controller = app(\App\Http\Controllers\Api\QuoteController::class);
            $request = \Illuminate\Http\Request::create('/api/quotes', 'POST', [
                'customer_name' => "Cliente Worker {$workerId}",
                'items' => [
                    ['product_name' => "Item W{$workerId}", 'unit_price' => 10.0, 'quantity' => 1],
                ],
            ]);
            
            // Set fake session user for auth
            $user = \App\Models\User::first();
            $request->setUserResolver(fn () => $user);

            $response = $controller->store($request);
            $result['status'] = $response->getStatusCode();
            $result['data'] = $response->getData(true);
            break;

        case 'delivery_update':
            $dnId = (int) ($argv[4] ?? 1);
            $itemId = (int) ($argv[5] ?? 1);
            $deliverQty = (float) ($argv[6] ?? 10);

            $controller = app(\App\Http\Controllers\DeliveryNoteController::class);
            $request = \Illuminate\Http\Request::create("/api/delivery-notes/{$dnId}/deliver", 'PUT', [
                'items' => [
                    ['id' => $itemId, 'delivered_now' => $deliverQty],
                ],
            ]);
            $user = \App\Models\User::first();
            $request->setUserResolver(fn () => $user);

            $response = $controller->updateDelivery($request, $dnId);
            $result['status'] = $response->getStatusCode();
            $result['data'] = $response->getData(true);
            break;

        case 'combo_lock_transaction':
            $comboId = (int) ($argv[4] ?? 1);
            $stockService = app(\App\Services\StockService::class);
            
            \Illuminate\Support\Facades\DB::transaction(function () use ($stockService, $comboId, &$result) {
                $locked = $stockService->lockProducts([$comboId]);
                // Simulate small hold time inside transaction to challenge concurrent transactions
                usleep(20000); // 20ms
                $result['locked_ids'] = $locked->keys()->toArray();
            });
            $result['status'] = 200;
            break;

        default:
            throw new \InvalidArgumentException("Unknown test case {$testCase}");
    }

    $result['success'] = true;
} catch (\Throwable $e) {
    $result['success'] = false;
    $result['error_class'] = get_class($e);
    $result['error_message'] = $e->getMessage();
    $result['error_code'] = $e->getCode();
}

$result['end_time'] = microtime(true);
$result['duration_ms'] = round(($result['end_time'] - $startTime) * 1000, 2);

echo "WORKER_RESULT:" . json_encode($result) . "\n";
