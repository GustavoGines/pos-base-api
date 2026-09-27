<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;

echo "=== QUESTION 2 VERIFICATION: CASHIER PIN BYPASS IN AuthController ===\n";

DB::beginTransaction();
try {
    // Create or find a cashier user with PIN
    $cashier = User::where('role', 'cashier')->whereNotNull('pin')->first();
    if (!$cashier) {
        $cashier = User::create([
            'name' => 'Cashier Test',
            'email' => 'cashier_test_' . time() . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'pin' => Hash::make('4321'),
        ]);
        $testedPin = '4321';
    } else {
        $cashier->pin = Hash::make('4321');
        $cashier->save();
        $testedPin = '4321';
    }

    echo "Created/updated cashier user: ID {$cashier->id}, Role: {$cashier->role}, Name: {$cashier->name}\n";

    $controller = new AuthController();
    $request = Request::create('/api/auth/authorize-pin', 'POST', ['pin' => $testedPin]);

    $response = $controller->authorizePin($request);
    $data = json_decode($response->getContent(), true);

    echo "Status code: " . $response->getStatusCode() . "\n";
    echo "Response JSON: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";

    if ($response->getStatusCode() === 200 && ($data['authorized'] ?? false) === true && ($data['user']['role'] ?? '') === 'cashier') {
        echo ">>> RESULT: VULNERABILITY EMPIRICALLY CONFIRMED! A cashier's PIN produces 'authorized: true'.\n";
    } else {
        echo ">>> RESULT: NOT REPRODUCED.\n";
    }
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}
