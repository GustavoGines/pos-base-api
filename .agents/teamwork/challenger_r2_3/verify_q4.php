<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

echo "=== QUESTION 4 VERIFICATION: UNAUTHENTICATED ROUTES IN routes/api.php ===\n";

$routesToTest = [
    'api/sales' => 'GET',
    'api/sales/pending' => 'GET',
    'api/customers' => 'GET',
    'api/customers/{customer}' => 'GET',
];

$allRoutes = Route::getRoutes();

foreach ($routesToTest as $uri => $method) {
    $matched = null;
    foreach ($allRoutes as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods())) {
            $matched = $route;
            break;
        }
    }

    if ($matched) {
        $middleware = $matched->gatherMiddleware();
        echo "Route [{$method} {$uri}]:\n";
        echo "  - Action: " . $matched->getActionName() . "\n";
        echo "  - Middlewares: " . implode(', ', $middleware) . "\n";
        $hasSessionValidate = in_array('session.validate', $middleware) || in_array('App\Http\Middleware\ValidateSessionToken', $middleware);
        echo "  - Protected by session.validate? " . ($hasSessionValidate ? 'YES' : 'NO (UNAUTHENTICATED/PUBLIC)') . "\n";
    } else {
        echo "Route [{$method} {$uri}] not found!\n";
    }
}

echo "\n--- Simulating HTTP request to unauthenticated GET /api/sales?period=all ---\n";
$requestSales = Request::create('/api/sales?period=all', 'GET');
// Ensure NO auth headers are sent
$responseSales = $app->handle($requestSales);
echo "Status code: " . $responseSales->getStatusCode() . "\n";
$salesData = json_decode($responseSales->getContent(), true);
echo "Sales count returned without auth: " . (is_array($salesData) ? count($salesData) : 'non-array') . "\n";

echo "\n--- Simulating HTTP request to unauthenticated GET /api/customers ---\n";
$requestCustomers = Request::create('/api/customers', 'GET');
$responseCustomers = $app->handle($requestCustomers);
echo "Status code: " . $responseCustomers->getStatusCode() . "\n";
$customersData = json_decode($responseCustomers->getContent(), true);
echo "Customers data structure: " . (isset($customersData['data']) ? 'Paginated collection with ' . count($customersData['data']) . ' customers' : 'Other') . "\n";

if ($responseSales->getStatusCode() === 200 && $responseCustomers->getStatusCode() === 200) {
    echo "\n>>> RESULT: VULNERABILITY CONFIRMED! Both /api/sales?period=all and /api/customers are publicly accessible without authentication.\n";
} else {
    echo "\n>>> RESULT: NOT PUBLIC.\n";
}
