<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Quote;
use App\Models\Product;
use App\Models\DeliveryNote;
use App\DTOs\SaleContextDTO;
use App\DTOs\ProcessSaleDTO;

class SaleService
{
    public function __construct(
        protected StockService $stockService,
        protected PaymentService $paymentService
    ) {}

    public function executeSale(ProcessSaleDTO $dto, SaleContextDTO $context): Sale
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($dto, $context) {
            $isPendingSale = $dto->status === 'pending';
            
            $ccPaymentTotal = 0;
            $isCuentaCorriente = false;

            if (!$isPendingSale && !empty($dto->payments)) {
                $ccPaymentTotal = collect($dto->payments)->filter(function ($p) {
                    $method = \App\Models\PaymentMethod::find($p['payment_method_id']);
                    return $method && $method->code === 'cuenta_corriente';
                })->sum('total_amount');

                $isCuentaCorriente = $ccPaymentTotal > 0;
            }

            $total = $dto->total;
            $totalSurcharge = $dto->totalSurcharge;
            
            // Machine state: payment_status and amount_due
            $paymentStatus = $isPendingSale ? 'pending' : 
                ($isCuentaCorriente ? ($ccPaymentTotal >= ($total + $totalSurcharge - 0.1) ? 'pending' : 'partial') : 'paid');
            $amountDue = $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total : 0);

            // 1. Create Sale
            $sale = Sale::create([
                'total'                  => $total,
                'total_surcharge'        => $totalSurcharge,
                'shipping_cost'          => $dto->shippingCost,
                'payment_status'         => $paymentStatus,
                'amount_due'             => $amountDue,
                'tendered_amount'        => $dto->tenderedAmount,
                'change_amount'          => $dto->changeAmount,
                'user_id'                => $context->userId,
                'customer_id'            => $context->customerId,
                'cashier_id'             => $context->userId,
                'cash_shift_id'          => $context->cashShiftId,
                'delivery_address'       => $context->deliveryAddress,
                'status'                 => $dto->status,
            ]);

            // Resolve Quote
            if ($context->quoteId) {
                $quote = Quote::find($context->quoteId);
                if ($quote) {
                    $quote->update(['status' => 'approved']);
                }
            }

            // 2. Process Payments
            if (!$isPendingSale && !empty($dto->payments)) {
                $this->paymentService->validatePaymentsTotal($dto->payments, $total + $totalSurcharge + $dto->shippingCost);
                $this->paymentService->registerPayments($sale, $dto->payments, $dto->checkDetails, $context);
                
                if ($isCuentaCorriente) {
                    $this->paymentService->registerCustomerCharge($sale, $ccPaymentTotal, $context);
                }
            }

            // 3. Lock products to prevent Dirty Reads, then process
            $productIds = array_column($dto->items, 'product_id');
            $lockedProducts = $this->stockService->lockProducts($productIds);

            // 4. Process Items and Stock
            $this->processItems($sale, $dto->items, $lockedProducts, $context);

            $this->stockService->processCartStock($dto->items, $lockedProducts, $sale, $context, $dto->requiresDispatch, $dto->fulfillmentStatus);

            // 5. Delivery Notes
            if ($dto->requiresDispatch) {
                $this->createDeliveryNote($sale, $dto->items, $dto->fulfillmentStatus);
            }

            // Domain Event instead of websocket broadcast
            event(new \App\Events\SaleCompleted($sale));

            return $sale;
        });
    }

    protected function processItems(Sale $sale, array $items, \Illuminate\Database\Eloquent\Collection $products, SaleContextDTO $context): void
    {
        foreach ($items as $itemData) {
            $product = $products[$itemData['product_id']] ?? null;
            if (!$product) continue;
            
            // Invoke Volume Pricing and Historical Cost on locked models (no dirty reads, no N+1)
            $unitPrice = $product->getPriceForQuantity($itemData['quantity']) ?? $itemData['unit_price'];
            $costPrice = $this->stockService->calculateCostPrice($product);
            
            $sale->items()->create([
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'quantity'        => $itemData['quantity'],
                'unit_cost_price' => $costPrice,
                'unit_price'      => $unitPrice,
                'subtotal'        => $itemData['subtotal'],
                'price_list'      => $context->priceList,
            ]);
        }
    }

    protected function createDeliveryNote(Sale $sale, array $items, string $fulfillmentStatus): void
    {
        $deliveryNote = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status'  => $fulfillmentStatus,
        ]);

        foreach ($items as $itemData) {
            $qty = (float) $itemData['quantity'];
            $deliveryNote->items()->create([
                'product_id'         => $itemData['product_id'],
                'quantity_purchased' => $qty,
                'quantity_delivered' => $fulfillmentStatus === 'delivered' ? $qty : 0,
            ]);
        }
    }
}
