<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductPriceTier;

echo "=== Testing Proposed Reconciliation Logic ===" . PHP_EOL;

function reconcileItem(array $itemData, Product $product): array
{
    $quantity = isset($itemData['quantity']) && is_numeric($itemData['quantity']) 
        ? (float) $itemData['quantity'] 
        : 1.0;

    // 1. Reconcile Unit Price: Explicit agreed price prevails if provided
    $rawPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;
    if ($rawPrice !== null && is_numeric($rawPrice)) {
        $unitPrice = (float) $rawPrice;
    } else {
        $unitPrice = (float) $product->getPriceForQuantity($quantity);
    }
    $unitPrice = max(0.0, round($unitPrice, 2));

    // 2. Reconcile Subtotal: Atomically calculated to enforce unit_price * quantity == subtotal
    $subtotal = round($unitPrice * $quantity, 2);

    return [
        'unit_price' => $unitPrice,
        'quantity'   => $quantity,
        'subtotal'   => $subtotal,
    ];
}

$product = Product::create([
    'name' => 'Prod Tier Test',
    'internal_code' => 'TIER_TEST',
    'selling_price' => 200.00,
    'cost_price' => 100.00,
    'stock' => 200,
    'active' => true,
]);

$product->priceTiers()->createMany([
    ['min_quantity' => 10, 'unit_price' => 180.00],
    ['min_quantity' => 50, 'unit_price' => 150.00],
]);

// Case 1: Agreed price sent (e.g. 130.00 special discount for 60 units)
$res1 = reconcileItem(['quantity' => 60, 'unit_price' => 130.00, 'subtotal' => 7800.00], $product);
echo "Case 1 (Agreed 130 for 60 units): unit_price={$res1['unit_price']}, subtotal={$res1['subtotal']}" . PHP_EOL;
assert($res1['unit_price'] == 130.00);
assert($res1['subtotal'] == 7800.00);
assert(round($res1['unit_price'] * $res1['quantity'], 2) == $res1['subtotal']);

// Case 2: No unit_price sent for 60 units -> Should hit volume tier ($150)
$res2 = reconcileItem(['quantity' => 60], $product);
echo "Case 2 (No unit_price, 60 units): unit_price={$res2['unit_price']}, subtotal={$res2['subtotal']}" . PHP_EOL;
assert($res2['unit_price'] == 150.00);
assert($res2['subtotal'] == 9000.00);
assert(round($res2['unit_price'] * $res2['quantity'], 2) == $res2['subtotal']);

// Case 3: No unit_price sent for 5 units -> Should hit base selling_price ($200)
$res3 = reconcileItem(['quantity' => 5], $product);
echo "Case 3 (No unit_price, 5 units): unit_price={$res3['unit_price']}, subtotal={$res3['subtotal']}" . PHP_EOL;
assert($res3['unit_price'] == 200.00);
assert($res3['subtotal'] == 1000.00);
assert(round($res3['unit_price'] * $res3['quantity'], 2) == $res3['subtotal']);

// Case 4: Client sent tampered subtotal ($99999.00) with agreed price $180 for 10 units
$res4 = reconcileItem(['quantity' => 10, 'unit_price' => 180.00, 'subtotal' => 99999.00], $product);
echo "Case 4 (Tampered subtotal 99999): unit_price={$res4['unit_price']}, subtotal={$res4['subtotal']}" . PHP_EOL;
assert($res4['unit_price'] == 180.00);
assert($res4['subtotal'] == 1800.00); // Sanitized!
assert(round($res4['unit_price'] * $res4['quantity'], 2) == $res4['subtotal']);

// Case 5: Weighed product with decimal quantity (1.333 kg @ $120.00 = 159.96)
$res5 = reconcileItem(['quantity' => 1.333, 'unit_price' => 120.00], $product);
echo "Case 5 (Weighed 1.333 kg @ 120): unit_price={$res5['unit_price']}, subtotal={$res5['subtotal']}" . PHP_EOL;
assert($res5['unit_price'] == 120.00);
assert($res5['subtotal'] == 159.96);
assert(round($res5['unit_price'] * $res5['quantity'], 2) == $res5['subtotal']);

// Clean up
$product->priceTiers()->delete();
$product->delete();

echo PHP_EOL . "ALL 5 RECONCILIATION TEST CASES PASSED ATOMICALLY!" . PHP_EOL;
