<?php

/**
 * Concurrency Orchestrator & Stress Testing Suite
 *
 * Runs multi-process empirical challenges against MySQL 8.4 InnoDB.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'sistema_pos_stress_test',
]);
\Illuminate\Support\Facades\DB::purge('mysql');
\Illuminate\Support\Facades\DB::reconnect('mysql');
\Illuminate\Support\Facades\DB::setDefaultConnection('mysql');

use Illuminate\Support\Facades\DB;
use App\Models\Quote;
use App\Models\Product;
use App\Models\Sale;
use App\Models\DeliveryNote;
use App\Models\StockMovement;
use App\Models\CashShift;
use App\Models\CashRegister;
use App\Models\PaymentMethod;

echo "====================================================================\n";
echo "   EMPIRICAL CONCURRENCY STRESS TEST SUITE (MySQL 8.4 InnoDB)       \n";
echo "Database: " . DB::connection()->getDatabaseName() . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Ensure baseline records exist in stress DB
\App\Models\User::firstOrCreate(
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
\App\Models\CashRegister::firstOrCreate(
    ['id' => 1],
    ['name' => 'Caja Stress']
);
\App\Models\PaymentMethod::firstOrCreate(
    ['id' => 1],
    [
        'name' => 'Efectivo',
        'code' => 'cash',
        'type' => 'cash',
        'active' => true,
    ]
);
\App\Models\BusinessSetting::firstOrCreate(
    ['key' => 'license_features_dict'],
    ['value' => json_encode(['quotes' => true])]
);

function runConcurrentWorkers(string $testCase, int $numWorkers, array $extraArgs = []): array {
    $syncTime = microtime(true) + 0.6; // barrier sync 600ms in future
    $processes = [];
    $pipes = [];

    $phpBinary = PHP_BINARY;
    $workerScript = __DIR__ . '/concurrency_worker.php';

    for ($i = 1; $i <= $numWorkers; $i++) {
        $cmd = array_merge([
            $phpBinary,
            $workerScript,
            $testCase,
            (string) $i,
            sprintf('%.4f', $syncTime),
        ], array_map('strval', $extraArgs));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes[$i], __DIR__);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to launch worker {$i}");
        }
        $processes[$i] = $proc;
    }

    $results = [];
    foreach ($processes as $i => $proc) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][0]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        $exitCode = proc_close($proc);

        $parsed = null;
        if (preg_match('/WORKER_RESULT:(.+)$/m', $stdout, $matches)) {
            $parsed = json_decode($matches[1], true);
        }

        $results[$i] = [
            'exit_code' => $exitCode,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'parsed'    => $parsed,
        ];
    }

    return $results;
}

$summary = [];

// =============================================================================
// TEST 1: Quote Cold Start Concurrency (Empty table, 5 concurrent requests)
// =============================================================================
echo "--- TEST 1: Quote Cold Start Concurrency (5 concurrent workers on empty table) ---\n";
DB::table('quote_items')->delete();
DB::table('quotes')->delete();

$test1Workers = 5;
$res1 = runConcurrentWorkers('quote_store', $test1Workers);

$t1Statuses = [];
$t1Numbers = [];
$t1Errors = [];

foreach ($res1 as $i => $r) {
    if ($r['parsed'] && isset($r['parsed']['status'])) {
        $t1Statuses[] = $r['parsed']['status'];
        if ($r['parsed']['status'] === 201) {
            $t1Numbers[] = $r['parsed']['data']['quote_number'] ?? 'UNKNOWN';
        } else {
            $t1Errors[] = "Worker {$i} HTTP {$r['parsed']['status']}: " . json_encode($r['parsed']['data']);
        }
    } else {
        $t1Errors[] = "Worker {$i} crashed or no JSON: " . $r['stderr'];
    }
}

$dbQuotes1 = Quote::orderBy('id', 'asc')->pluck('quote_number')->toArray();
$uniqueDbQuotes1 = array_unique($dbQuotes1);

echo "Workers Launched: {$test1Workers}\n";
echo "HTTP Statuses: " . implode(', ', $t1Statuses) . "\n";
echo "Returned Numbers: " . implode(', ', $t1Numbers) . "\n";
echo "DB Persisted Count: " . count($dbQuotes1) . " (Unique: " . count($uniqueDbQuotes1) . ")\n";
echo "DB Numbers: " . implode(', ', $dbQuotes1) . "\n";

$t1Pass = count($dbQuotes1) === $test1Workers 
    && count($uniqueDbQuotes1) === $test1Workers 
    && empty($t1Errors)
    && $dbQuotes1 === ['PRES-0001', 'PRES-0002', 'PRES-0003', 'PRES-0004', 'PRES-0005'];

echo "Result: " . ($t1Pass ? "PASS [PERFECT SEQUENCING & ZERO COLLISIONS]" : "FAIL") . "\n";
if (!empty($t1Errors)) {
    echo "Errors encountered:\n" . implode("\n", $t1Errors) . "\n";
}
echo "\n";
$summary['TEST_1_QUOTE_COLD_START'] = [
    'pass' => $t1Pass,
    'expected' => ['PRES-0001', 'PRES-0002', 'PRES-0003', 'PRES-0004', 'PRES-0005'],
    'actual' => $dbQuotes1,
    'errors' => $t1Errors,
];

// =============================================================================
// TEST 2: Quote Number Overflow Concurrency (PRES-9999 boundary, 5 concurrent workers)
// =============================================================================
echo "--- TEST 2: Quote Overflow Concurrency (PRES-9999 + 5 concurrent workers) ---\n";
DB::table('quote_items')->delete();
DB::table('quotes')->delete();

Quote::create([
    'quote_number' => 'PRES-9999',
    'status'       => 'pending',
    'subtotal'     => 10,
    'total'        => 10,
    'user_id'      => 1,
]);

$test2Workers = 5;
$res2 = runConcurrentWorkers('quote_store', $test2Workers);

$t2Numbers = [];
$t2Errors = [];
foreach ($res2 as $i => $r) {
    if ($r['parsed'] && isset($r['parsed']['status']) && $r['parsed']['status'] === 201) {
        $t2Numbers[] = $r['parsed']['data']['quote_number'] ?? 'UNKNOWN';
    } else {
        $t2Errors[] = "Worker {$i} failed: " . json_encode($r['parsed'] ?? $r['stderr']);
    }
}

$dbQuotes2 = Quote::where('quote_number', '!=', 'PRES-9999')->orderBy('id', 'asc')->pluck('quote_number')->toArray();
$expected2 = ['PRES-10000', 'PRES-10001', 'PRES-10002', 'PRES-10003', 'PRES-10004'];

echo "DB Numbers Created: " . implode(', ', $dbQuotes2) . "\n";
$t2Pass = $dbQuotes2 === $expected2 && empty($t2Errors);
echo "Result: " . ($t2Pass ? "PASS [SEAMLESS OVERFLOW BEYOND 9999]" : "FAIL") . "\n";
if (!empty($t2Errors)) {
    echo "Errors encountered:\n" . implode("\n", $t2Errors) . "\n";
}
echo "\n";
$summary['TEST_2_QUOTE_OVERFLOW'] = [
    'pass' => $t2Pass,
    'expected' => $expected2,
    'actual' => $dbQuotes2,
    'errors' => $t2Errors,
];

// =============================================================================
// TEST 3: Delivery Note Concurrency & Double Deduction Shield (Counter Sale)
// =============================================================================
echo "--- TEST 3: Delivery Note Concurrency - Counter Sale (5 concurrent updates) ---\n";

// Setup product and counter sale
$prod3 = Product::create([
    'name'          => 'Stress Prod Counter',
    'internal_code' => 'SPC-' . uniqid(),
    'cost_price'    => 10,
    'selling_price' => 20,
    'stock'         => 100,
    'active'        => 1,
]);

$user = \App\Models\User::first();
$shift = CashShift::firstOrCreate(
    ['status' => 'open'],
    [
        'cash_register_id' => 1,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'opened_at' => now(),
    ]
);

$sale3 = Sale::create([
    'total'           => 100,
    'total_surcharge' => 0,
    'payment_status'  => 'paid',
    'amount_due'      => 0,
    'status'          => 'completed',
    'cash_shift_id'   => $shift->id,
    'user_id'         => $user->id,
]);

$sale3->items()->create([
    'product_id'   => $prod3->id,
    'product_name' => $prod3->name,
    'quantity'     => 5,
    'unit_price'   => 20,
    'subtotal'     => 100,
]);

// Decrement stock as counter sale does at checkout: 100 -> 95
$prod3->stock -= 5;
$prod3->save();

StockMovement::create([
    'product_id'    => $prod3->id,
    'user_id'       => $user->id,
    'cash_shift_id' => $shift->id,
    'sale_id'       => $sale3->id,
    'type'          => 'sale',
    'quantity'      => -5,
    'notes'         => "Venta Ticket #{$sale3->id}",
]);

$dn3 = DeliveryNote::create([
    'sale_id' => $sale3->id,
    'status'  => 'pending',
    'notes'   => 'Remito Mostrador',
]);
$dnItem3 = $dn3->items()->create([
    'product_id'         => $prod3->id,
    'quantity_purchased' => 5,
    'quantity_delivered' => 0,
]);

echo "Initial Product Stock after checkout: " . $prod3->fresh()->stock . " (expected 95)\n";
echo "Sale hasDeductedStock(): " . ($sale3->hasDeductedStock() ? 'TRUE' : 'FALSE') . "\n";

// Run 5 concurrent delivery updates
$res3 = runConcurrentWorkers('delivery_update', 5, [$dn3->id, $dnItem3->id, 5]);

$stock3After = (float) $prod3->fresh()->stock;
$movements3 = StockMovement::where('product_id', $prod3->id)->count();
$dnItem3After = (float) $dnItem3->fresh()->quantity_delivered;

echo "Product Stock after 5 concurrent delivery updates: {$stock3After}\n";
echo "Stock movements logged: {$movements3} (expected exactly 1)\n";
echo "Quantity delivered in DeliveryNoteItem: {$dnItem3After} (expected 5)\n";

$t3Pass = ($stock3After === 95.0) && ($movements3 === 1) && ($dnItem3After === 5.0);
echo "Result: " . ($t3Pass ? "PASS [ABSOLUTELY NO DOUBLE DEDUCTION UNDER CONCURRENCY]" : "FAIL") . "\n\n";
$summary['TEST_3_DELIVERY_COUNTER_CONCURRENCY'] = [
    'pass' => $t3Pass,
    'final_stock' => $stock3After,
    'movements_count' => $movements3,
    'quantity_delivered' => $dnItem3After,
];

// =============================================================================
// TEST 4: Delivery Note Concurrency - Deferred Sale (Bounded deduction)
// =============================================================================
echo "--- TEST 4: Delivery Note Concurrency - Deferred Sale (5 concurrent workers delivering 5 units each of 10) ---\n";

$prod4 = Product::create([
    'name'          => 'Stress Prod Deferred',
    'internal_code' => 'SPD-' . uniqid(),
    'cost_price'    => 10,
    'selling_price' => 20,
    'stock'         => 100, // untouched at checkout
    'active'        => 1,
]);

$sale4 = Sale::create([
    'total'           => 200,
    'total_surcharge' => 0,
    'payment_status'  => 'paid',
    'amount_due'      => 0,
    'status'          => 'completed',
    'cash_shift_id'   => $shift->id,
    'user_id'         => $user->id,
]);
$sale4->items()->create([
    'product_id'   => $prod4->id,
    'product_name' => $prod4->name,
    'quantity'     => 10,
    'unit_price'   => 20,
    'subtotal'     => 200,
]);

$dn4 = DeliveryNote::create([
    'sale_id' => $sale4->id,
    'status'  => 'pending',
    'notes'   => 'Remito diferido despacho',
]);
$dnItem4 = $dn4->items()->create([
    'product_id'         => $prod4->id,
    'quantity_purchased' => 10,
    'quantity_delivered' => 0,
]);

// 5 concurrent workers all trying to deliver 5 units
// Max possible deduction should be 10 (the purchased amount).
// Stock must go from 100 to 90. It must NEVER go below 90 (e.g. 75).
$res4 = runConcurrentWorkers('delivery_update', 5, [$dn4->id, $dnItem4->id, 5]);

$stock4After = (float) $prod4->fresh()->stock;
$delivered4After = (float) $dnItem4->fresh()->quantity_delivered;
$movements4 = StockMovement::where('product_id', $prod4->id)->get();
$totalDeducted = $movements4->sum('quantity');

echo "Initial Stock: 100 | Purchased: 10\n";
echo "Final Product Stock: {$stock4After} (expected 90.0)\n";
echo "Quantity Delivered recorded: {$delivered4After} (expected 10.0)\n";
echo "Total net stock deducted: {$totalDeducted} (expected -10.0)\n";

$t4Pass = ($stock4After === 90.0) && ($delivered4After === 10.0) && ($totalDeducted == -10.0);
echo "Result: " . ($t4Pass ? "PASS [CONCURRENT DELIVERY CLEANLY BOUNDED TO PURCHASED QTY]" : "FAIL") . "\n\n";
$summary['TEST_4_DELIVERY_DEFERRED_BOUNDS'] = [
    'pass' => $t4Pass,
    'final_stock' => $stock4After,
    'delivered' => $delivered4After,
    'total_deducted' => $totalDeducted,
];

// =============================================================================
// TEST 5: Combo Lock Order & Anti-Deadlock Stress
// =============================================================================
echo "--- TEST 5: Combo Lock Order & Anti-Deadlock (Concurrent locking of overlapping combos) ---\n";

$childA = Product::create(['name' => 'Child A', 'internal_code' => 'CA-' . uniqid(), 'cost_price' => 5, 'selling_price' => 10, 'stock' => 100]);
$childB = Product::create(['name' => 'Child B', 'internal_code' => 'CB-' . uniqid(), 'cost_price' => 5, 'selling_price' => 10, 'stock' => 100]);

$combo1 = Product::create(['name' => 'Combo 1', 'internal_code' => 'C1-' . uniqid(), 'cost_price' => 0, 'selling_price' => 20, 'stock' => 0, 'is_combo' => true]);
$combo2 = Product::create(['name' => 'Combo 2', 'internal_code' => 'C2-' . uniqid(), 'cost_price' => 0, 'selling_price' => 20, 'stock' => 0, 'is_combo' => true]);

// Combo 1 references A then B
DB::table('product_combos')->insert([
    ['parent_product_id' => $combo1->id, 'child_product_id' => $childA->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['parent_product_id' => $combo1->id, 'child_product_id' => $childB->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
]);

// Combo 2 references B then A (inverted relationship)
DB::table('product_combos')->insert([
    ['parent_product_id' => $combo2->id, 'child_product_id' => $childB->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['parent_product_id' => $combo2->id, 'child_product_id' => $childA->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
]);

// Launch 4 workers: 2 locking Combo 1, 2 locking Combo 2
$res5 = runConcurrentWorkers('combo_lock_transaction', 4, [$combo1->id]);

$t5Deadlocks = 0;
$t5Successes = 0;
foreach ($res5 as $r) {
    if ($r['parsed'] && ($r['parsed']['status'] ?? 0) === 200) {
        $t5Successes++;
    } else {
        if (str_contains(json_encode($r), 'Deadlock') || str_contains(json_encode($r), '1213')) {
            $t5Deadlocks++;
        }
    }
}

echo "Combo Transactions Executed: 4 | Successes: {$t5Successes} | Deadlocks: {$t5Deadlocks}\n";
$t5Pass = ($t5Successes === 4) && ($t5Deadlocks === 0);
echo "Result: " . ($t5Pass ? "PASS [ZERO DEADLOCKS IN INNODB]" : "FAIL") . "\n\n";
$summary['TEST_5_COMBO_DEADLOCK'] = [
    'pass' => $t5Pass,
    'successes' => $t5Successes,
    'deadlocks' => $t5Deadlocks,
];

// =============================================================================
// TEST 6: Sale Void Stock Restoration Exactness
// =============================================================================
echo "--- TEST 6: Sale Void Stock Restoration Exactness ---\n";
$saleService = app(\App\Services\SaleService::class);

// Scenario A: Counter sale void
$prod6A = Product::create(['name' => 'Prod Void A', 'internal_code' => 'PVA-' . uniqid(), 'cost_price' => 10, 'selling_price' => 20, 'stock' => 50]);
$sale6A = Sale::create(['total' => 100, 'total_surcharge' => 0, 'status' => 'completed', 'cash_shift_id' => $shift->id, 'user_id' => $user->id]);
$sale6A->items()->create(['product_id' => $prod6A->id, 'product_name' => $prod6A->name, 'quantity' => 5, 'unit_price' => 20, 'subtotal' => 100]);
$prod6A->stock -= 5;
$prod6A->save();
StockMovement::create(['product_id' => $prod6A->id, 'user_id' => $user->id, 'cash_shift_id' => $shift->id, 'sale_id' => $sale6A->id, 'type' => 'sale', 'quantity' => -5, 'notes' => "Ticket #{$sale6A->id}"]);

$dn6A = DeliveryNote::create(['sale_id' => $sale6A->id, 'status' => 'pending']);
$dn6A->items()->create(['product_id' => $prod6A->id, 'quantity_purchased' => 5, 'quantity_delivered' => 2]); // only 2 delivered on delivery note

$context = \App\DTOs\SaleContextDTO::fromArray([
    'user_id'       => $user->id,
    'cash_shift_id' => $shift->id,
]);
$saleService->voidSale($sale6A, $context);

$stock6AFinal = (float) $prod6A->fresh()->stock;
echo "Counter Sale Void Final Stock: {$stock6AFinal} (expected 50.0 - full 5 units restored)\n";
$t6APass = ($stock6AFinal === 50.0);

// Scenario B: Deferred sale void (only delivered units restored)
$prod6B = Product::create(['name' => 'Prod Void B', 'internal_code' => 'PVB-' . uniqid(), 'cost_price' => 10, 'selling_price' => 20, 'stock' => 50]);
$sale6B = Sale::create(['total' => 100, 'total_surcharge' => 0, 'status' => 'completed', 'cash_shift_id' => $shift->id, 'user_id' => $user->id]);
$sale6B->items()->create(['product_id' => $prod6B->id, 'product_name' => $prod6B->name, 'quantity' => 5, 'unit_price' => 20, 'subtotal' => 100]);
// Stock untouched at checkout: stays 50

$dn6B = DeliveryNote::create(['sale_id' => $sale6B->id, 'status' => 'partial']);
$dn6B->items()->create(['product_id' => $prod6B->id, 'quantity_purchased' => 5, 'quantity_delivered' => 2]);
$prod6B->stock -= 2; // delivered 2 units
$prod6B->save();
// Stock is now 48

$saleService->voidSale($sale6B, $context);
$stock6BFinal = (float) $prod6B->fresh()->stock;
echo "Deferred Sale Void Final Stock: {$stock6BFinal} (expected 50.0 - exactly 2 units restored, NO phantom inventory)\n";
$t6BPass = ($stock6BFinal === 50.0);

$t6Pass = $t6APass && $t6BPass;
echo "Result: " . ($t6Pass ? "PASS [PERFECT INVENTORY RECONCILIATION & NO LEAKS]" : "FAIL") . "\n\n";
$summary['TEST_6_SALE_VOID'] = [
    'pass' => $t6Pass,
    'counter_void_stock' => $stock6AFinal,
    'deferred_void_stock' => $stock6BFinal,
];

// =============================================================================
// OVERALL SUMMARY
// =============================================================================
echo "====================================================================\n";
echo "   OVERALL EMPIRICAL VERDICT                                       \n";
echo "====================================================================\n";
$allPass = true;
foreach ($summary as $name => $s) {
    $status = $s['pass'] ? 'PASS' : 'FAIL';
    echo sprintf("%-35s: %s\n", $name, $status);
    if (!$s['pass']) $allPass = false;
}

echo "\nFINAL EMPIRICAL VERDICT: " . ($allPass ? "APPROVE" : "REQUEST_CHANGES") . "\n";
echo "====================================================================\n";

file_put_contents(__DIR__ . '/empirical_results.json', json_encode($summary, JSON_PRETTY_PRINT));
