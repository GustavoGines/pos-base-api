<?php

/**
 * Challenger 3 Empirical Stress Test Runner
 *
 * Runs multi-process empirical challenges against MySQL 8.4 InnoDB.
 */

require_once __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'sistema_pos_stress_test',
]);
DB::purge('mysql');
DB::reconnect('mysql');
DB::setDefaultConnection('mysql');

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\DeliveryNote;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "====================================================================\n";
echo "   CHALLENGER 3 EMPIRICAL CONCURRENCY STRESS HARNESS               \n";
echo 'Database: '.DB::connection()->getDatabaseName()."\n";
echo 'Engine: MySQL '.DB::select('SELECT VERSION() as v')[0]->v." InnoDB\n";
echo 'Start Time: '.date('Y-m-d H:i:s')."\n";
echo "====================================================================\n\n";

// Ensure prerequisite base records
User::firstOrCreate(
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
CashRegister::firstOrCreate(['id' => 1], ['name' => 'Caja Stress']);
PaymentMethod::firstOrCreate(
    ['id' => 1],
    ['name' => 'Efectivo', 'code' => 'cash', 'type' => 'cash', 'active' => true]
);
BusinessSetting::updateOrCreate(
    ['key' => 'license_features_dict'],
    ['value' => json_encode(['quotes' => true])]
);

function executeConcurrentProcesses(array $workerConfigs): array
{
    $syncTimestamp = microtime(true) + 0.8; // 800ms barrier sync
    $processes = [];
    $pipes = [];
    $phpBinary = PHP_BINARY;
    $workerScript = __DIR__.'/StressWorker.php';

    foreach ($workerConfigs as $id => $config) {
        $action = $config['action'];
        $extra = $config['extra'] ?? [];
        $cmd = array_merge([
            $phpBinary,
            $workerScript,
            $action,
            (string) $id,
            sprintf('%.4f', $syncTimestamp),
        ], array_map('strval', $extra));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes[$id], __DIR__);
        if (! is_resource($proc)) {
            throw new RuntimeException("Failed to spawn worker {$id}");
        }
        $processes[$id] = $proc;
    }

    $results = [];
    foreach ($processes as $id => $proc) {
        $stdout = stream_get_contents($pipes[$id][1]);
        $stderr = stream_get_contents($pipes[$id][2]);
        fclose($pipes[$id][0]);
        fclose($pipes[$id][1]);
        fclose($pipes[$id][2]);
        $exitCode = proc_close($proc);

        $parsed = null;
        if (preg_match('/WORKER_RESULT:(.+)$/m', $stdout, $matches)) {
            $parsed = json_decode($matches[1], true);
        }

        $results[$id] = [
            'worker_id' => $id,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'parsed' => $parsed,
        ];
    }

    return $results;
}

$summary = [];

// =============================================================================
// TEST C3-1: 10 CONCURRENT WORKERS COLD START (EMPTY TABLE)
// =============================================================================
echo "--- TEST C3-1: Cold Start Concurrency (10 workers simultaneous on empty quotes table) ---\n";
DB::table('quote_items')->delete();
DB::table('quotes')->delete();

$workers10 = [];
for ($i = 1; $i <= 10; $i++) {
    $workers10[$i] = ['action' => 'quote_store'];
}

$res1 = executeConcurrentProcesses($workers10);

$c1Statuses = [];
$c1Numbers = [];
$c1Errors = [];

foreach ($res1 as $i => $r) {
    $status = $r['parsed']['status'] ?? null;
    $c1Statuses[] = $status;
    if ($status === 201) {
        $c1Numbers[] = $r['parsed']['data']['quote_number'];
    } else {
        $c1Errors[] = "Worker {$i} failed: status={$status}, err=".json_encode($r['parsed'] ?? $r['stderr']);
    }
}

$dbQuotes1 = Quote::orderBy('id', 'asc')->pluck('quote_number')->toArray();
$expected1 = array_map(fn ($n) => sprintf('PRES-%04d', $n), range(1, 10));

echo "Workers Launched: 10\n";
echo 'HTTP Statuses: '.implode(', ', $c1Statuses)."\n";
echo 'Returned Numbers: '.implode(', ', $c1Numbers)."\n";
echo 'DB Persisted Count: '.count($dbQuotes1).' (Unique: '.count(array_unique($dbQuotes1)).")\n";
echo 'DB Numbers: '.implode(', ', $dbQuotes1)."\n";

$pass1 = (count($dbQuotes1) === 10)
    && (count(array_unique($dbQuotes1)) === 10)
    && ($dbQuotes1 === $expected1)
    && empty($c1Errors);

echo 'Result: '.($pass1 ? 'PASS [PERFECT 10-PROCESS COLD START SEQUENCE]' : 'FAIL')."\n";
if (! empty($c1Errors)) {
    echo "Errors:\n".implode("\n", $c1Errors)."\n";
}
echo "\n";
$summary['TEST_C3_1_COLD_START_10_WORKERS'] = [
    'pass' => $pass1,
    'total_workers' => 10,
    'http_201_count' => count(array_filter($c1Statuses, fn ($s) => $s === 201)),
    'expected_sequence' => $expected1,
    'actual_sequence' => $dbQuotes1,
    'errors' => $c1Errors,
];

// =============================================================================
// TEST C3-2: 15 CONCURRENT WORKERS CONTINUATION BURST
// =============================================================================
echo "--- TEST C3-2: Continuation Burst (15 workers simultaneous immediately following C3-1) ---\n";
// Keep existing 10 quotes in database (PRES-0001 to PRES-0010)
$workers15 = [];
for ($i = 11; $i <= 25; $i++) {
    $workers15[$i] = ['action' => 'quote_store'];
}

$res2 = executeConcurrentProcesses($workers15);

$c2Statuses = [];
$c2Numbers = [];
$c2Errors = [];

foreach ($res2 as $i => $r) {
    $status = $r['parsed']['status'] ?? null;
    $c2Statuses[] = $status;
    if ($status === 201) {
        $c2Numbers[] = $r['parsed']['data']['quote_number'];
    } else {
        $c2Errors[] = "Worker {$i} failed: status={$status}, err=".json_encode($r['parsed'] ?? $r['stderr']);
    }
}

$dbQuotes2 = Quote::where('id', '>', 10)->orderBy('id', 'asc')->pluck('quote_number')->toArray();
$expected2 = array_map(fn ($n) => sprintf('PRES-%04d', $n), range(11, 25));

echo "Workers Launched: 15\n";
echo 'HTTP Statuses: '.implode(', ', $c2Statuses)."\n";
echo 'DB Persisted Count: '.count($dbQuotes2).' (Unique: '.count(array_unique($dbQuotes2)).")\n";
echo 'DB Numbers Range: '.reset($dbQuotes2).' .. '.end($dbQuotes2)."\n";

$pass2 = (count($dbQuotes2) === 15)
    && (count(array_unique($dbQuotes2)) === 15)
    && ($dbQuotes2 === $expected2)
    && empty($c2Errors);

echo 'Result: '.($pass2 ? 'PASS [PERFECT 15-PROCESS CONTINUATION SEQUENCE]' : 'FAIL')."\n";
if (! empty($c2Errors)) {
    echo "Errors:\n".implode("\n", $c2Errors)."\n";
}
echo "\n";
$summary['TEST_C3_2_BURST_15_WORKERS'] = [
    'pass' => $pass2,
    'total_workers' => 15,
    'http_201_count' => count(array_filter($c2Statuses, fn ($s) => $s === 201)),
    'expected_sequence' => $expected2,
    'actual_sequence' => $dbQuotes2,
    'errors' => $c2Errors,
];

// =============================================================================
// TEST C3-3: 10 CONCURRENT WORKERS AT OVERFLOW BOUNDARY (PRES-9999)
// =============================================================================
echo "--- TEST C3-3: Boundary Overflow Concurrency (10 workers simultaneous from PRES-9999) ---\n";
DB::table('quote_items')->delete();
DB::table('quotes')->delete();

Quote::create([
    'quote_number' => 'PRES-9999',
    'status' => 'pending',
    'subtotal' => 10,
    'total' => 10,
    'user_id' => 1,
]);

$workersOverflow = [];
for ($i = 1; $i <= 10; $i++) {
    $workersOverflow[$i] = ['action' => 'quote_store'];
}

$res3 = executeConcurrentProcesses($workersOverflow);

$c3Statuses = [];
$c3Numbers = [];
$c3Errors = [];

foreach ($res3 as $i => $r) {
    $status = $r['parsed']['status'] ?? null;
    $c3Statuses[] = $status;
    if ($status === 201) {
        $c3Numbers[] = $r['parsed']['data']['quote_number'];
    } else {
        $c3Errors[] = "Worker {$i} failed: status={$status}, err=".json_encode($r['parsed'] ?? $r['stderr']);
    }
}

$dbQuotes3 = Quote::where('quote_number', '!=', 'PRES-9999')->orderBy('id', 'asc')->pluck('quote_number')->toArray();
$expected3 = array_map(fn ($n) => 'PRES-'.$n, range(10000, 10009));

echo 'DB Numbers Range: '.reset($dbQuotes3).' .. '.end($dbQuotes3)."\n";
$pass3 = (count($dbQuotes3) === 10)
    && (count(array_unique($dbQuotes3)) === 10)
    && ($dbQuotes3 === $expected3)
    && empty($c3Errors);

echo 'Result: '.($pass3 ? 'PASS [PERFECT OVERFLOW TO 5-DIGIT WITHOUT COLLISION]' : 'FAIL')."\n";
if (! empty($c3Errors)) {
    echo "Errors:\n".implode("\n", $c3Errors)."\n";
}
echo "\n";
$summary['TEST_C3_3_OVERFLOW_10_WORKERS'] = [
    'pass' => $pass3,
    'total_workers' => 10,
    'expected_sequence' => $expected3,
    'actual_sequence' => $dbQuotes3,
    'errors' => $c3Errors,
];

// =============================================================================
// TEST C3-4: MIXED HIGH-CONCURRENCY WORKLOAD (8 QUOTES + 8 DELIVERY NOTES)
// =============================================================================
echo "--- TEST C3-4: Mixed Workload (8 Quotes + 8 Delivery Note updates simultaneous) ---\n";
DB::table('quote_items')->delete();
DB::table('quotes')->delete();

// Setup product and delivery note
$mixedProd = Product::create([
    'name' => 'Mixed Workload Prod',
    'internal_code' => 'MWP-'.uniqid(),
    'cost_price' => 10,
    'selling_price' => 20,
    'stock' => 100,
    'active' => 1,
]);

$user = User::first();
$shift = CashShift::firstOrCreate(
    ['status' => 'open'],
    [
        'cash_register_id' => 1,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'opened_at' => now(),
    ]
);

$mixedSale = Sale::create([
    'total' => 160,
    'total_surcharge' => 0,
    'payment_status' => 'paid',
    'amount_due' => 0,
    'status' => 'completed',
    'cash_shift_id' => $shift->id,
    'user_id' => $user->id,
]);
$mixedSale->items()->create([
    'product_id' => $mixedProd->id,
    'product_name' => $mixedProd->name,
    'quantity' => 8,
    'unit_price' => 20,
    'subtotal' => 160,
]);
// Counter sale deduction
$mixedProd->stock -= 8; // 100 -> 92
$mixedProd->save();
StockMovement::create([
    'product_id' => $mixedProd->id,
    'user_id' => $user->id,
    'cash_shift_id' => $shift->id,
    'sale_id' => $mixedSale->id,
    'type' => 'sale',
    'quantity' => -8,
    'notes' => "Ticket #{$mixedSale->id}",
]);

$mixedDn = DeliveryNote::create([
    'sale_id' => $mixedSale->id,
    'status' => 'pending',
]);
$mixedDnItem = $mixedDn->items()->create([
    'product_id' => $mixedProd->id,
    'quantity_purchased' => 8,
    'quantity_delivered' => 0,
]);

$mixedConfigs = [];
// 8 quote workers
for ($i = 1; $i <= 8; $i++) {
    $mixedConfigs[$i] = ['action' => 'quote_store'];
}
// 8 delivery note workers
for ($i = 9; $i <= 16; $i++) {
    $mixedConfigs[$i] = [
        'action' => 'delivery_update',
        'extra' => [$mixedDn->id, $mixedDnItem->id, 8],
    ];
}

$res4 = executeConcurrentProcesses($mixedConfigs);

$c4Errors = [];
$quote201Count = 0;
$delivery200Count = 0;

foreach ($res4 as $i => $r) {
    $status = $r['parsed']['status'] ?? null;
    if ($r['parsed']['action'] === 'quote_store') {
        if ($status === 201) {
            $quote201Count++;
        } else {
            $c4Errors[] = "Quote Worker {$i} failed: status={$status}, err=".json_encode($r['parsed'] ?? $r['stderr']);
        }
    } else {
        if ($status === 200) {
            $delivery200Count++;
        } else {
            $c4Errors[] = "Delivery Worker {$i} failed: status={$status}, err=".json_encode($r['parsed'] ?? $r['stderr']);
        }
    }
}

$finalStock4 = (float) $mixedProd->fresh()->stock;
$finalDnItemQty = (float) $mixedDnItem->fresh()->quantity_delivered;
$movementsCount = StockMovement::where('product_id', $mixedProd->id)->count();

$pass4 = ($quote201Count === 8)
    && ($delivery200Count === 8)
    && ($finalStock4 === 92.0)
    && ($finalDnItemQty === 8.0)
    && ($movementsCount === 1)
    && empty($c4Errors);

echo "Quote Successes: {$quote201Count}/8 | Delivery Note Successes: {$delivery200Count}/8\n";
echo "Final Stock: {$finalStock4} (expected 92.0) | Stock Movements: {$movementsCount} (expected 1)\n";
echo "Quantity Delivered: {$finalDnItemQty} (expected 8.0)\n";
echo 'Result: '.($pass4 ? 'PASS [MIXED WORKLOAD EXECUTED WITH ZERO CONCURRENCY FAILURES]' : 'FAIL')."\n";
if (! empty($c4Errors)) {
    echo "Errors:\n".implode("\n", $c4Errors)."\n";
}
echo "\n";
$summary['TEST_C3_4_MIXED_WORKLOAD'] = [
    'pass' => $pass4,
    'quote_successes' => $quote201Count,
    'delivery_successes' => $delivery200Count,
    'final_stock' => $finalStock4,
    'movements_count' => $movementsCount,
    'errors' => $c4Errors,
];

// =============================================================================
// TEST C3-5: REPEAT COLD START STRESS (5 ITERATIONS OF 10 WORKERS = 50 QUOTES)
// Stress test for intermittent timing races or gap lock edge cases
// =============================================================================
echo "--- TEST C3-5: Repeat Cold Start Stability (3 cycles of 10 workers on wiped table) ---\n";
$cyclePasses = 0;
for ($cycle = 1; $cycle <= 3; $cycle++) {
    DB::table('quote_items')->delete();
    DB::table('quotes')->delete();

    $w = [];
    for ($i = 1; $i <= 10; $i++) {
        $w[$i] = ['action' => 'quote_store'];
    }

    $cRes = executeConcurrentProcesses($w);
    $cDb = Quote::orderBy('id', 'asc')->pluck('quote_number')->toArray();
    $exp = array_map(fn ($n) => sprintf('PRES-%04d', $n), range(1, 10));

    if (count($cDb) === 10 && $cDb === $exp) {
        $cyclePasses++;
        echo "Cycle {$cycle}/3: PASS (10/10 perfect)\n";
    } else {
        echo "Cycle {$cycle}/3: FAIL (Got: ".implode(', ', $cDb).")\n";
    }
}

$pass5 = ($cyclePasses === 3);
echo 'Result: '.($pass5 ? 'PASS [STABILITY REPRODUCED ACROSS ALL CYCLES]' : 'FAIL')."\n\n";
$summary['TEST_C3_5_REPEAT_COLD_START'] = [
    'pass' => $pass5,
    'passed_cycles' => $cyclePasses,
    'total_cycles' => 3,
];

// =============================================================================
// OVERALL SUMMARY & VERDICT
// =============================================================================
echo "====================================================================\n";
echo "   CHALLENGER 3 EMPIRICAL VERDICT                                  \n";
echo "====================================================================\n";
$allPass = true;
foreach ($summary as $name => $s) {
    $status = $s['pass'] ? 'PASS' : 'FAIL';
    echo sprintf("%-35s: %s\n", $name, $status);
    if (! $s['pass']) {
        $allPass = false;
    }
}

echo "\nFINAL EMPIRICAL VERDICT: ".($allPass ? 'APPROVE' : 'REQUEST_CHANGES')."\n";
echo "====================================================================\n";

file_put_contents(__DIR__.'/c3_empirical_results.json', json_encode($summary, JSON_PRETTY_PRINT));
