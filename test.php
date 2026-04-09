<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $q = App\Models\Quote::create([
        'quote_number' => 'PRES-1234',
        'status' => 'pending',
        'subtotal' => 10,
        'total' => 10,
        'customer_name' => 'A',
        'customer_phone' => '1',
        'notes' => 'T',
        'valid_until' => '2026-04-10',
        'user_id' => 1,
    ]);
    echo "Success Quote\n";
    
    $i = App\Models\QuoteItem::create([
        'quote_id' => $q->id,
        'product_id' => null,
        'product_name' => 'Faplac 18mm',
        'unit_price' => 10,
        'quantity' => 1,
        'subtotal' => 10,
    ]);
    echo "Success QuoteItem\n";
} catch(Exception $e) {
    echo $e->getMessage() . "\n" . $e->getTraceAsString();
}
