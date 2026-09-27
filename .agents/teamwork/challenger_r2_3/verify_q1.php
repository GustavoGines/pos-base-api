<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\User;

echo "=== QUESTION 1 VERIFICATION: ENUM TRUNCATION ON REFUND ===\n";

$driver = DB::connection()->getDriverName();
echo "Database driver: $driver\n";

$columns = DB::select("SHOW COLUMNS FROM customer_transactions WHERE Field = 'type'");
if (!empty($columns)) {
    echo "Column 'type' in customer_transactions: " . $columns[0]->Type . "\n";
} else {
    echo "Column 'type' not found in customer_transactions!\n";
}

// Test inserting 'refund'
DB::beginTransaction();
try {
    $user = User::first() ?? User::create(['name' => 'Tester', 'email' => 't@t.com', 'password' => 'secret', 'role' => 'admin']);
    $customer = Customer::first() ?? Customer::create(['name' => 'Test Customer', 'email' => 'c@c.com', 'balance' => 0]);
    
    echo "Attempting to insert CustomerTransaction with type='refund'...\n";
    CustomerTransaction::create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => 'refund',
        'amount' => 50.00,
        'balance_after' => 50.00,
        'description' => 'Test refund',
    ]);
    echo "SUCCESS (No error thrown)\n";
} catch (\Illuminate\Database\QueryException $e) {
    echo "CAUGHT EXPECTED QueryException:\n";
    echo "SQLSTATE: " . $e->getCode() . "\n";
    echo "Message: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    echo "CAUGHT EXCEPTION: " . get_class($e) . " - " . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}
