<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;

class InjectSalesSeeder extends Seeder
{
    public function run()
    {
        $startDate = Carbon::create(2026, 8, 1);
        $endDate = Carbon::create(2026, 8, 21);

        // Limpiar ventas fakes anteriores
        $fakeSales = Sale::whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])->get();
        foreach ($fakeSales as $sale) {
            SaleItem::where('sale_id', $sale->id)->delete();
            SalePayment::where('sale_id', $sale->id)->delete();
            $sale->delete();
        }

        $products = Product::inRandomOrder()->limit(10)->get();
        if ($products->isEmpty()) {
            echo "No hay productos para vender.\n";
            return;
        }

        $paymentMethods = PaymentMethod::all();
        if ($paymentMethods->isEmpty()) {
            echo "No hay metodos de pago.\n";
            return;
        }

        $user = User::first();
        if (!$user) {
            echo "No hay usuarios.\n";
            return;
        }

        // Obtener o crear una caja y un turno para que no falle la FK
        $shift = CashShift::firstOrCreate(
            ['status' => 'closed', 'user_id' => $user->id, 'cash_register_id' => 1],
            ['opened_at' => now()->subMonths(1), 'closed_at' => now()->subMonths(1), 'opening_balance' => 0]
        );

        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
            // Random number of sales per day: 3 to 15
            $salesCount = rand(3, 15);
            for ($i = 0; $i < $salesCount; $i++) {
                $saleDate = $date->copy()->addHours(rand(8, 20))->addMinutes(rand(0, 59));

                $sale = Sale::create([
                    'customer_id' => null,
                    'user_id' => $user->id,
                    'cash_shift_id' => $shift->id,
                    'total_surcharge' => 0,
                    'total' => 0,
                    'amount_due' => 0,
                    'payment_status' => 'paid',
                    'status' => 'completed',
                    'created_at' => $saleDate,
                    'updated_at' => $saleDate,
                ]);

                $subtotal = 0;
                $itemsCount = rand(1, 5);
                for ($j = 0; $j < $itemsCount; $j++) {
                    $product = $products->random();
                    $quantity = rand(1, 5);
                    $unitPrice = $product->selling_price ?? rand(500, 2000);
                    $unitCost = $product->cost_price ?? ($unitPrice * 0.6);
                    // Ensure price is always at least 15% higher than cost for randoms
                    if ($unitPrice <= $unitCost) {
                        $unitPrice = $unitCost * 1.3;
                    }
                    $itemSubtotal = $quantity * $unitPrice;

                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name ?? 'Producto',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'unit_cost_price' => $unitCost,
                        'subtotal' => $itemSubtotal,
                        'created_at' => $saleDate,
                        'updated_at' => $saleDate,
                    ]);

                    $subtotal += $itemSubtotal;
                }

                $sale->total = $subtotal;
                $sale->save();

                $pm = $paymentMethods->random();
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $pm->id,
                    'base_amount' => $subtotal,
                    'surcharge_amount' => 0,
                    'total_amount' => $subtotal,
                ]);
            }
        }

        echo "Ventas antiguas borradas y nuevas inyectadas correctamente del 1 de Agosto al 21 de Agosto.\n";
    }
}
