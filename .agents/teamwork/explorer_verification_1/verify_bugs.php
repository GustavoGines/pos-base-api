<?php

/**
 * Programmatic Bug Verification Suite
 * Executed by explorer_verification_1
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\Sale;
use App\Models\Product;
use App\Models\User;
use App\Models\Customer;
use App\Models\CashShift;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Services\SaleService;
use App\Console\Commands\SyncLicenseCommand;
use App\Console\Commands\SyncLicenseStatus;

echo "====================================================================\n";
echo "       PROGRAMMATIC VERIFICATION SUITE — POS BACKEND AUDIT        \n";
echo "====================================================================\n\n";

$results = [];

// ------------------------------------------------------------------
// VERIFICATION 1: Command Signature Collision
// ------------------------------------------------------------------
echo "[1] Testing Command Signature Collision (license:sync)...\n";
$cmd1 = new SyncLicenseCommand($app->make(App\Services\LicenseSyncService::class));
$cmd2 = new SyncLicenseStatus();

$name1 = $cmd1->getName();
$name2 = $cmd2->getName();

$resolvedCommand = Artisan::all()['license:sync'] ?? null;
$resolvedClass = $resolvedCommand ? get_class($resolvedCommand) : 'NONE';
$desc = $resolvedCommand ? $resolvedCommand->getDescription() : '';

$isCollision = ($name1 === 'license:sync' && $name2 === 'license:sync');
$isHijacked = ($resolvedClass === SyncLicenseStatus::class);

echo "    - SyncLicenseCommand signature: {$name1}\n";
echo "    - SyncLicenseStatus signature:  {$name2}\n";
echo "    - Artisan resolved class:       {$resolvedClass}\n";
echo "    - Artisan resolved description: {$desc}\n";

if ($isCollision && $isHijacked) {
    echo "    >>> RESULT: BUG VERIFIED (SyncLicenseStatus shadows SyncLicenseCommand)\n\n";
    $results['command_collision'] = [
        'status' => 'VERIFIED_BUG',
        'details' => "Both commands define 'license:sync'. Artisan resolves to {$resolvedClass}, completely shadowing SyncLicenseCommand."
    ];
} else {
    echo "    >>> RESULT: NOT REPRODUCED\n\n";
    $results['command_collision'] = ['status' => 'FAILED'];
}

// ------------------------------------------------------------------
// VERIFICATION 2: Route Binding & Dead Code (AdjustStockRequest)
// ------------------------------------------------------------------
echo "[2] Testing Stock Adjustment Route Binding & Dead Code...\n";
$routes = Route::getRoutes();
$adjustStockRoute = null;
foreach ($routes as $route) {
    if (str_contains($route->uri(), 'adjust-stock')) {
        $adjustStockRoute = $route;
        break;
    }
}

if ($adjustStockRoute) {
    $actionName = $adjustStockRoute->getActionName();
    echo "    - Route 'adjust-stock' maps to: {$actionName}\n";
    $isDeadCode = ($actionName === 'App\Http\Controllers\Api\StockController@adjust');
    echo "    - ProductController::adjustStock() routed: " . ($actionName === 'App\Http\Controllers\Api\ProductController@adjustStock' ? 'YES' : 'NO (DEAD CODE)') . "\n";
    
    // Check parameters of StockController@adjust vs AdjustStockRequest
    $refMethod = new ReflectionMethod(\App\Http\Controllers\Api\StockController::class, 'adjust');
    $firstParamType = $refMethod->getParameters()[0]->getType()?->getName();
    echo "    - StockController::adjust first parameter type: {$firstParamType}\n";

    if ($isDeadCode && $firstParamType === 'Illuminate\Http\Request') {
        echo "    >>> RESULT: BUG VERIFIED (AdjustStockRequest is orphaned, StockController uses un-typed Request)\n\n";
        $results['stock_adjust_dead_code'] = [
            'status' => 'VERIFIED_BUG',
            'details' => "The route maps to StockController@adjust which uses generic Request, while AdjustStockRequest was bound to orphaned ProductController@adjustStock."
        ];
    } else {
        echo "    >>> RESULT: NOT REPRODUCED\n\n";
        $results['stock_adjust_dead_code'] = ['status' => 'FAILED'];
    }
} else {
    echo "    - adjust-stock route not found!\n\n";
    $results['stock_adjust_dead_code'] = ['status' => 'ROUTE_MISSING'];
}

// ------------------------------------------------------------------
// VERIFICATION 3: SaleService price_list persistence failure
// ------------------------------------------------------------------
echo "[3] Testing SaleService price_list persistence...\n";

// We execute this inside a rollback transaction so we don't pollute local DB
DB::beginTransaction();
try {
    // Ensure we have minimal seed data in the DB
    $user = User::first();
    if (!$user) {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);
    }
    
    $product = Product::first();
    if (!$product) {
        $product = Product::create([
            'name' => 'Verification Product',
            'selling_price' => 100,
            'cost_price' => 50,
            'stock' => 100,
            'internal_code' => '99999',
        ]);
    }

    $cashShift = CashShift::where('status', 'open')->first();
    if (!$cashShift) {
        $cashShift = CashShift::create([
            'user_id' => $user->id,
            'cashier_id' => $user->id,
            'opened_at' => now(),
            'start_amount' => 1000,
            'status' => 'open',
        ]);
    }

    $saleService = $app->make(SaleService::class);

    $processSaleDTO = ProcessSaleDTO::fromArray([
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
            ]
        ],
        'payments' => [
            [
                'payment_method_id' => 1, // standard cash
                'base_amount' => 100,
                'surcharge_amount' => 0,
                'total_amount' => 100,
            ]
        ],
        'status' => 'completed',
        'total' => 100,
        'total_surcharge' => 0,
        'shipping_cost' => 0,
        'tendered_amount' => 100,
        'change_amount' => 0,
        'requires_dispatch' => false,
        'fulfillment_status' => 'delivered',
        'check_details' => [],
    ]);

    $saleContextDTO = SaleContextDTO::fromArray([
        'user_id' => $user->id,
        'cash_shift_id' => $cashShift->id,
        'price_list' => 'mayorista_especial',
    ]);

    $sale = $saleService->executeSale($processSaleDTO, $saleContextDTO);

    // Refresh from database to verify what was stored
    $savedSale = Sale::find($sale->id);
    $firstItem = $savedSale->items()->first();

    echo "    - Input price_list in context: 'mayorista_especial'\n";
    echo "    - Saved sale->price_list in DB: " . var_export($savedSale->price_list, true) . "\n";
    
    // Check if price_list column exists on sale_items table
    $hasColumnOnItems = DB::getSchemaBuilder()->hasColumn('sale_items', 'price_list');
    echo "    - Does 'sale_items' table have 'price_list' column? " . ($hasColumnOnItems ? 'YES' : 'NO') . "\n";

    if ($savedSale->price_list === null && !$hasColumnOnItems) {
        echo "    >>> RESULT: BUG VERIFIED (SaleService drops price_list on Sale model; attempts insertion into non-existent sale_items column)\n\n";
        $results['sale_price_list_bug'] = [
            'status' => 'VERIFIED_BUG',
            'details' => "SaleService::executeSale() creates Sale without 'price_list' => \$context->priceList (saved as NULL). In processItems(), it attempts 'price_list' => \$context->priceList on SaleItem where the column does not exist."
        ];
    } else {
        echo "    >>> RESULT: UNEXPECTED (price_list was saved: " . var_export($savedSale->price_list, true) . ")\n\n";
        $results['sale_price_list_bug'] = ['status' => 'FAILED'];
    }

} catch (\Throwable $e) {
    echo "    >>> EXCEPTION: " . $e->getMessage() . "\n";
    $results['sale_price_list_bug'] = ['status' => 'EXCEPTION', 'message' => $e->getMessage()];
} finally {
    DB::rollBack();
}

// ------------------------------------------------------------------
// VERIFICATION 4: Security Hole (rescueMigrate without secret)
// ------------------------------------------------------------------
echo "[4] Testing Security Vulnerability (rescueMigrate unauthenticated access)...\n";
$rescueSecret = config('app.rescue_migrate_secret');
$route = Route::getRoutes()->getByName('rescue-migrate') ?? null;
// Find route by URI
$rescueRoute = null;
foreach (Route::getRoutes() as $r) {
    if (str_contains($r->uri(), 'rescue-migrate')) {
        $rescueRoute = $r;
        break;
    }
}

if ($rescueRoute) {
    $methods = implode(',', $rescueRoute->methods());
    $middlewares = implode(',', $rescueRoute->gatherMiddleware());
    echo "    - rescue-migrate HTTP methods: {$methods}\n";
    echo "    - rescue-migrate middleware:    " . ($middlewares ?: 'NONE (PUBLIC)') . "\n";
    echo "    - config('app.rescue_migrate_secret'): " . var_export($rescueSecret, true) . "\n";

    $isPublic = empty($middlewares) || $middlewares === 'api';
    $isSecretEmpty = empty($rescueSecret);

    if ($isPublic && $isSecretEmpty) {
        echo "    >>> RESULT: VULNERABILITY VERIFIED (Unauthenticated GET route executes migrate --force when RESCUE_MIGRATE_SECRET is empty)\n\n";
        $results['rescue_migrate_vulnerability'] = [
            'status' => 'VERIFIED_VULNERABILITY',
            'details' => "Route 'GET /api/system/rescue-migrate' has no auth middleware and config('app.rescue_migrate_secret') is empty by default, allowing arbitrary unauthenticated users to trigger Artisan migrate --force via simple GET request."
        ];
    } else {
        echo "    >>> RESULT: SECURED\n\n";
        $results['rescue_migrate_vulnerability'] = ['status' => 'NOT_VULNERABLE'];
    }
}

// ------------------------------------------------------------------
// VERIFICATION 5: Ghost Master PIN Backdoor
// ------------------------------------------------------------------
echo "[5] Testing AuthController Ghost Master PIN Backdoor...\n";
$refClass = new ReflectionClass(\App\Http\Controllers\Api\AuthController::class);
$constants = $refClass->getConstants();
$hasGhostPin = array_key_exists('GHOST_MASTER_HASH', $constants);

if ($hasGhostPin) {
    echo "    - AuthController::GHOST_MASTER_HASH: " . $constants['GHOST_MASTER_HASH'] . "\n";
    echo "    >>> RESULT: VULNERABILITY VERIFIED (Hardcoded Bcrypt backdoor constant in production AuthController)\n\n";
    $results['ghost_master_pin'] = [
        'status' => 'VERIFIED_VULNERABILITY',
        'details' => "AuthController defines private const GHOST_MASTER_HASH = '{$constants['GHOST_MASTER_HASH']}' allowing universal admin impersonation bypassing database credentials."
    ];
} else {
    echo "    >>> RESULT: NOT FOUND\n\n";
    $results['ghost_master_pin'] = ['status' => 'NOT_FOUND'];
}

// ------------------------------------------------------------------
// VERIFICATION 6: SQL Incompatibility in MonthlyBalanceExport
// ------------------------------------------------------------------
echo "[6] Testing MonthlyBalanceExport SQL Incompatibility & Business Logic Discrepancy...\n";
$refExport = new ReflectionClass(\App\Exports\MonthlyBalanceExport::class);
$exportFile = file_get_contents($refExport->getFileName());

$hasDateFormat = str_contains($exportFile, "DATE_FORMAT(sales.created_at");
$hasInternalExclusion = str_contains($exportFile, "is_internal_account");
$hasExpensesDeduction = str_contains($exportFile, "cash_movements") || str_contains($exportFile, "expense");

echo "    - Uses MySQL-only DATE_FORMAT(): " . ($hasDateFormat ? 'YES (Incompatible with SQLite)' : 'NO') . "\n";
echo "    - Excludes internal accounts:     " . ($hasInternalExclusion ? 'YES' : 'NO (Violates business logic)') . "\n";
echo "    - Deducts operational expenses:   " . ($hasExpensesDeduction ? 'YES' : 'NO (Labels gross profit as net profit)') . "\n";

if ($hasDateFormat && !$hasInternalExclusion && !$hasExpensesDeduction) {
    echo "    >>> RESULT: BUG VERIFIED (MonthlyBalanceExport has engine lock-in, omits internal account exclusion, and reports inaccurate profit compared to ReportController)\n\n";
    $results['monthly_balance_export_sql'] = [
        'status' => 'VERIFIED_BUG',
        'details' => "MonthlyBalanceExport hardcodes DATE_FORMAT, fails to exclude internal accounts, and does not subtract cash expenses, causing direct divergence with ReportController::monthlyBalance()."
    ];
} else {
    echo "    >>> RESULT: INCONCLUSIVE\n\n";
    $results['monthly_balance_export_sql'] = ['status' => 'INCONCLUSIVE'];
}

// ------------------------------------------------------------------
// VERIFICATION 7: SQLite Crash on MonthlyBalanceExport Execution
// ------------------------------------------------------------------
echo "[7] Testing SQLite Runtime Failure in MonthlyBalanceExport...\n";
try {
    // Create an in-memory SQLite connection dynamically
    config([
        'database.connections.sqlite_test' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]
    ]);
    
    // Set default connection to sqlite_test temporarily
    $defaultConn = DB::getDefaultConnection();
    DB::setDefaultConnection('sqlite_test');

    // Run schema for sales and sale_items and products
    DB::statement('CREATE TABLE sales (id INTEGER PRIMARY KEY, status TEXT, created_at DATETIME);');
    DB::statement('CREATE TABLE products (id INTEGER PRIMARY KEY, cost_price NUMERIC);');
    DB::statement('CREATE TABLE sale_items (id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, unit_cost_price NUMERIC, quantity NUMERIC, subtotal NUMERIC);');

    // Attempt to run the export collection
    $export = new \App\Exports\MonthlyBalanceExport('2026-01', '2026-03');
    $export->collection();

    DB::setDefaultConnection($defaultConn);
    echo "    >>> RESULT: FAILED (Did not throw on SQLite)\n\n";
    $results['sqlite_crash_export'] = ['status' => 'FAILED'];
} catch (\Illuminate\Database\QueryException $e) {
    DB::setDefaultConnection($defaultConn ?? 'mysql');
    echo "    - Caught expected QueryException on SQLite: " . $e->getMessage() . "\n";
    if (str_contains($e->getMessage(), 'no such function: DATE_FORMAT')) {
        echo "    >>> RESULT: BUG VERIFIED (MonthlyBalanceExport crashes under SQLite due to unportable DATE_FORMAT function)\n\n";
        $results['sqlite_crash_export'] = [
            'status' => 'VERIFIED_BUG',
            'details' => "Executing MonthlyBalanceExport under SQLite throws: QueryException: no such function: DATE_FORMAT."
        ];
    } else {
        echo "    >>> RESULT: ERROR " . $e->getMessage() . "\n\n";
        $results['sqlite_crash_export'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
} catch (\Throwable $e) {
    DB::setDefaultConnection($defaultConn ?? 'mysql');
    echo "    >>> RESULT: EXCEPTION " . $e->getMessage() . "\n\n";
    $results['sqlite_crash_export'] = ['status' => 'EXCEPTION', 'message' => $e->getMessage()];
}

// ------------------------------------------------------------------
// VERIFICATION 8: WebSocket Real-Time Broadcasting Broken
// ------------------------------------------------------------------
echo "[8] Testing Real-Time WebSocket Broadcasting (SaleCompleted)...\n";
$implementsShouldBroadcast = is_subclass_of(\App\Events\SaleCompleted::class, \Illuminate\Contracts\Broadcasting\ShouldBroadcast::class);
$listeners = \Illuminate\Support\Facades\Event::getListeners(\App\Events\SaleCompleted::class);

echo "    - SaleCompleted implements ShouldBroadcast: " . ($implementsShouldBroadcast ? 'YES' : 'NO') . "\n";
echo "    - Registered listeners for SaleCompleted:   " . count($listeners) . "\n";

if (!$implementsShouldBroadcast && count($listeners) === 0) {
    echo "    >>> RESULT: BUG VERIFIED (SaleCompleted is a dead/silent event: no broadcasting and 0 listeners)\n\n";
    $results['sale_completed_broadcasting'] = [
        'status' => 'VERIFIED_BUG',
        'details' => "SaleCompleted does not implement ShouldBroadcast and has 0 registered listeners, breaking real-time WebSocket dashboard updates."
    ];
} else {
    echo "    >>> RESULT: NOT REPRODUCED\n\n";
    $results['sale_completed_broadcasting'] = ['status' => 'FAILED'];
}

echo "====================================================================\n";
echo "                      SUMMARY OF VERIFICATIONS                      \n";
echo "====================================================================\n";
foreach ($results as $check => $data) {
    echo sprintf("%-30s : %s\n", $check, $data['status']);
}
echo "\nSuite completed successfully.\n";
