<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\CashShift;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Services\SaleService;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use Illuminate\Support\Facades\DB;

echo "=== Simulating Worker Unit/Feature Test for P2.2 ===" . PHP_EOL;

// We simulate what the fixed SaleService::processItems() will produce:
class PatchedSaleService extends SaleService
{
    protected function processItems(Sale $sale, array $items, \Illuminate\Database\Eloquent\Collection $products, SaleContextDTO $context): void
    {
        foreach ($items as $itemData) {
            $product = $products[$itemData['product_id']] ?? null;
            if (!$product) continue;

            $quantity = isset($itemData['quantity']) && is_numeric($itemData['quantity'])
                ? (float) $itemData['quantity']
                : 1.0;

            $rawPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;
            if ($rawPrice !== null && is_numeric($rawPrice)) {
                $unitPrice = (float) $rawPrice;
            } else {
                $unitPrice = (float) $product->getPriceForQuantity($quantity);
            }
            $unitPrice = max(0.0, round($unitPrice, 2));

            $subtotal = round($unitPrice * $quantity, 2);
            $costPrice = $this->stockService->calculateCostPrice($product);

            $sale->items()->create([
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'quantity'        => $quantity,
                'unit_cost_price' => $costPrice,
                'unit_price'      => $unitPrice,
                'subtotal'        => $subtotal,
            ]);
        }
    }
}

DB::beginTransaction();
try {
    $user = User::first() ?? User::factory()->create(['role' => 'admin']);
    $register = CashRegister::firstOrCreate(['id' => 1], ['name' => 'Caja 1', 'is_active' => true]);
    $shift = CashShift::create([
        'cash_register_id' => $register->id,
        'user_id' => $user->id,
        'opened_at' => now(),
        'status' => 'open',
        'opening_balance' => 0,
    ]);
    $cash = PaymentMethod::firstOrCreate(['code' => 'efectivo'], ['name' => 'Efectivo', 'is_cash' => true]);

    $product = Product::create([
        'name' => 'Producto Descuento Especial',
        'internal_code' => 'P22_SIM',
        'selling_price' => 100.00,
        'cost_price' => 40.00,
        'stock' => 50,
        'active' => true,
    ]);

    // Agreed price: 75.00, Qty: 4, Total: 300.00
    // Malicious/tampered subtotal in payload: 999.00
    $dto = ProcessSaleDTO::fromArray([
        'total' => 300.00,
        'total_surcharge' => 0,
        'payments' => [[
            'payment_method_id' => $cash->id,
            'base_amount' => 300.00,
            'surcharge_amount' => 0,
            'total_amount' => 300.00,
        ]],
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 4,
            'unit_price' => 75.00,
            'subtotal' => 999.00, // Tampered!
        ]],
    ]);

    $context = new SaleContextDTO(
        userId: $user->id,
        cashShiftId: $shift->id,
        customerId: null,
        quoteId: null,
        priceList: 'descuento_especial',
        deliveryAddress: null,
        isInternalAccount: false
    );

    $patchedService = app(PatchedSaleService::class);
    $sale = $patchedService->executeSale($dto, $context);

    $item = SaleItem::where('sale_id', $sale->id)->first();

    echo "Saved item -> unit_price: {$item->unit_price}, quantity: {$item->quantity}, subtotal: {$item->subtotal}" . PHP_EOL;

    assert((float)$item->unit_price === 75.00, 'Agreed unit price 75.00 must prevail over catalog 100.00');
    assert((float)$item->subtotal === 300.00, 'Subtotal must be recalculated to 300.00 (not 999.00)');
    assert(round($item->unit_price * $item->quantity, 2) === (float)$item->subtotal, 'unit_price * quantity == subtotal must strictly hold');

    echo "SUCCESS: PatchedSaleService passed all assertions!" . PHP_EOL;
} finally {
    DB::rollBack();
}
