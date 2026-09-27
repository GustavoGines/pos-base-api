<?php

/**
 * Challenger 3 Adversarial Concurrency Burst Analyzer
 *
 * Systematically tests varying concurrency levels
 * to evaluate the headroom and failure threshold of the 5-attempt retry with jitter.
 */

if (!isset($app)) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $app = require_once __DIR__ . '/../../bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'sistema_pos_stress_test',
]);
\Illuminate\Support\Facades\DB::purge('mysql');
\Illuminate\Support\Facades\DB::reconnect('mysql');
\Illuminate\Support\Facades\DB::setDefaultConnection('mysql');

use Illuminate\Support\Facades\DB;
use App\Models\Quote;

function runBurst(int $concurrency): array {
    // Reset table cleanly
    DB::table('quote_items')->delete();
    DB::table('quotes')->delete();

    $syncTime = microtime(true) + 0.6;
    $phpBinary = PHP_BINARY;
    $workerScript = __DIR__ . '/StressWorker.php';

    $procs = [];
    $pipes = [];

    for ($i = 1; $i <= $concurrency; $i++) {
        $cmd = [
            $phpBinary,
            $workerScript,
            'quote_store',
            (string) $i,
            sprintf('%.4f', $syncTime),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $procs[$i] = proc_open($cmd, $descriptors, $pipes[$i], __DIR__);
    }

    $results = [];
    foreach ($procs as $i => $proc) {
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
            'parsed'    => $parsed,
            'stderr'    => $stderr,
        ];
    }

    $successCount = 0;
    $failCount = 0;
    $deadlockCount = 0;
    $statuses = [];

    foreach ($results as $i => $r) {
        $status = $r['parsed']['status'] ?? 0;
        $statuses[] = $status;
        if ($status === 201) {
            $successCount++;
        } else {
            $failCount++;
            $msg = json_encode($r['parsed'] ?? $r['stderr']);
            if (str_contains($msg, 'Deadlock') || str_contains($msg, '40001') || str_contains($msg, '1213')) {
                $deadlockCount++;
            }
        }
    }

    $dbQuotes = Quote::orderBy('id', 'asc')->pluck('quote_number')->toArray();
    $uniqueCount = count(array_unique($dbQuotes));

    return [
        'concurrency'      => $concurrency,
        'successes'        => $successCount,
        'failures'         => $failCount,
        'deadlocks_leaked' => $deadlockCount,
        'statuses'         => $statuses,
        'db_count'         => count($dbQuotes),
        'unique_count'     => $uniqueCount,
        'db_numbers'       => $dbQuotes,
    ];
}

$cliLevel = isset($argv[1]) ? (int) $argv[1] : null;
$cliReps = isset($argv[2]) ? (int) $argv[2] : 1;

if ($cliLevel) {
    echo "Running target concurrency level {$cliLevel} for {$cliReps} repetitions...\n";
    for ($rep = 1; $rep <= $cliReps; $rep++) {
        $res = runBurst($cliLevel);
        echo sprintf(
            "Rep %d/%d: %2d/%2d succeeded | Leaked deadlocks: %d | DB unique: %d\n",
            $rep,
            $cliReps,
            $res['successes'],
            $cliLevel,
            $res['deadlocks_leaked'],
            $res['unique_count']
        );
    }
    exit(0);
}

$levels = [5, 8, 10, 12, 15];
$analysis = [];

foreach ($levels as $level) {
    echo "Testing concurrency level: {$level} simultaneous workers...\n";
    $res = runBurst($level);
    $statusText = ($res['successes'] === $level && $res['unique_count'] === $level) ? 'PASS' : 'FAIL';
    echo "  Successes: {$res['successes']}/{$level} | DB Count: {$res['db_count']} (Unique: {$res['unique_count']}) | Leaked 500 Deadlocks: {$res['deadlocks_leaked']} -> {$statusText}\n\n";
    $analysis[$level] = $res;
}

echo "====================================================================\n";
echo "   CONCURRENCY STRESS MATRIX SUMMARY                               \n";
echo "====================================================================\n";
foreach ($analysis as $lvl => $r) {
    echo sprintf(
        "Concurrency %2d: %2d/%2d succeeded (%3.0f%%) | Leaked Deadlocks: %d | Status: %s\n",
        $lvl,
        $r['successes'],
        $lvl,
        ($r['successes'] / $lvl) * 100,
        $r['deadlocks_leaked'],
        ($r['successes'] === $lvl) ? 'ROBUST' : 'COLLISION_OVERFLOW'
    );
}
echo "====================================================================\n";
