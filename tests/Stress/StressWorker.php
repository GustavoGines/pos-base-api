<?php

use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\DeliveryNoteController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Challenger 3 Stress Worker Process
 * Executes API operations against MySQL 8.4 InnoDB under high concurrency.
 */

require_once __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Ensure stress test database is used
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'sistema_pos_stress_test',
]);
DB::purge('mysql');
DB::reconnect('mysql');
DB::setDefaultConnection('mysql');

$action = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? 0);
$syncTimestamp = (float) ($argv[3] ?? 0);

// Barrier synchronization: wait until syncTimestamp
if ($syncTimestamp > 0) {
    while (microtime(true) < $syncTimestamp) {
        usleep(100);
    }
}

$startTime = microtime(true);
$result = [
    'worker_id' => $workerId,
    'action' => $action,
    'start_at' => $startTime,
];

try {
    $user = User::firstOrCreate(
        ['id' => 1],
        [
            'name' => 'Admin Stress',
            'username' => 'admin_stress',
            'email' => 'admin@stress.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'is_active' => true,
        ]
    );

    switch ($action) {
        case 'quote_store':
            $controller = app(QuoteController::class);
            $request = Request::create('/api/quotes', 'POST', [
                'customer_name' => "Cliente C3-W{$workerId}",
                'items' => [
                    ['product_name' => "Item W{$workerId}", 'unit_price' => 15.50, 'quantity' => 2],
                ],
            ]);
            $request->setUserResolver(fn () => $user);

            $response = $controller->store($request);
            $result['status'] = $response->getStatusCode();
            $result['data'] = $response->getData(true);
            break;

        case 'delivery_update':
            $dnId = (int) ($argv[4] ?? 1);
            $itemId = (int) ($argv[5] ?? 1);
            $deliverQty = (float) ($argv[6] ?? 5);

            $controller = app(DeliveryNoteController::class);
            $request = Request::create("/api/delivery-notes/{$dnId}/deliver", 'PUT', [
                'items' => [
                    ['id' => $itemId, 'delivered_now' => $deliverQty],
                ],
            ]);
            $request->setUserResolver(fn () => $user);

            $response = $controller->updateDelivery($request, $dnId);
            $result['status'] = $response->getStatusCode();
            $result['data'] = $response->getData(true);
            break;

        default:
            throw new InvalidArgumentException("Unknown worker action: {$action}");
    }

    $result['success'] = true;
} catch (Throwable $e) {
    $result['success'] = false;
    $result['error_class'] = get_class($e);
    $result['error_message'] = $e->getMessage();
    $result['error_code'] = $e->getCode();
}

$result['end_at'] = microtime(true);
$result['duration_ms'] = round(($result['end_at'] - $startTime) * 1000, 2);

echo 'WORKER_RESULT:'.json_encode($result)."\n";
