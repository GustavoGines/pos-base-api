<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Quote;
use App\Models\Product;
use App\Models\DeliveryNote;
use App\Models\CustomerTransaction;
use App\DTOs\SaleContextDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\PaySaleDTO;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        protected StockService $stockService,
        protected PaymentService $paymentService
    ) {}

    public function executeSale(ProcessSaleDTO $dto, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($dto, $context) {
            $isPendingSale = $dto->status === 'pending';
            
            $ccPaymentTotal = 0;
            $isCuentaCorriente = false;

            if (!$isPendingSale && !empty($dto->payments)) {
                $paymentMethodIds = array_column($dto->payments, 'payment_method_id');
                $paymentMethods = \App\Models\PaymentMethod::whereIn('id', $paymentMethodIds)->get()->keyBy('id');

                $ccPaymentTotal = collect($dto->payments)->filter(function ($p) use ($paymentMethods) {
                    $method = $paymentMethods->get($p['payment_method_id']);
                    return $method && $method->code === 'cuenta_corriente';
                })->sum('total_amount');

                $isCuentaCorriente = $ccPaymentTotal > 0;
            }

            $total = $dto->total;
            $totalSurcharge = $dto->totalSurcharge;
            
            $paymentStatus = $isPendingSale ? 'pending' : 
                ($isCuentaCorriente ? ($ccPaymentTotal >= ($total + $totalSurcharge - 0.1) ? 'pending' : 'partial') : 'paid');
            $amountDue = $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total : 0);

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

            if ($context->quoteId) {
                $quote = Quote::find($context->quoteId);
                if ($quote) {
                    $quote->update(['status' => 'approved']);
                }
            }

            if (!$isPendingSale && !empty($dto->payments)) {
                $this->paymentService->validatePaymentsTotal($dto->payments, $total + $totalSurcharge + $dto->shippingCost);
                $this->paymentService->registerPayments($sale, $dto->payments, $dto->checkDetails, $context);
                
                if ($isCuentaCorriente) {
                    $this->paymentService->registerCustomerCharge($sale, $ccPaymentTotal, $context);
                }
            }

            $productIds = array_column($dto->items, 'product_id');
            $lockedProducts = $this->stockService->lockProducts($productIds);

            $this->processItems($sale, $dto->items, $lockedProducts, $context);

            $this->stockService->processCartStock($dto->items, $lockedProducts, $sale, $context, $dto->requiresDispatch, $dto->fulfillmentStatus);

            if ($dto->requiresDispatch) {
                $this->createDeliveryNote($sale, $dto->items, $dto->fulfillmentStatus);
            }

            event(new \App\Events\SaleCompleted($sale));

            return $sale;
        });
    }

    public function payPendingSale(Sale $sale, PaySaleDTO $dto, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($sale, $dto, $context) {
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status !== 'pending') {
                throw new \InvalidArgumentException('Esta venta ya no está en estado pendiente.');
            }

            // Order Recall Items Delta
            if (!empty($dto->items)) {
                $this->stockService->reconcileStockDiff($dto->items, $lockedSale, $context);

                $lockedSale->items()->delete();
                
                $productIds = array_column($dto->items, 'product_id');
                $lockedProducts = $this->stockService->lockProducts($productIds);
                $this->processItems($lockedSale, $dto->items, $lockedProducts, $context);
                
                // Recalculate Totals based on new items
                $newTotal = collect($dto->items)->sum('subtotal');
                $lockedSale->total = $newTotal;
            }

            $totalToValidate = $lockedSale->total + $dto->totalSurcharge + $dto->shippingCost;
            $this->paymentService->validatePaymentsTotal($dto->payments, $totalToValidate);
            $this->paymentService->registerPayments($lockedSale, $dto->payments, $dto->checkDetails, $context);

            $ccPaymentTotal = 0;
            $isCuentaCorriente = false;
            if (!empty($dto->payments)) {
                $paymentMethodIds = array_column($dto->payments, 'payment_method_id');
                $paymentMethods = \App\Models\PaymentMethod::whereIn('id', $paymentMethodIds)->get()->keyBy('id');

                $ccPaymentTotal = collect($dto->payments)->filter(function ($p) use ($paymentMethods) {
                    $method = $paymentMethods->get($p['payment_method_id']);
                    return $method && $method->code === 'cuenta_corriente';
                })->sum('total_amount');

                $isCuentaCorriente = $ccPaymentTotal > 0;
            }

            if ($isCuentaCorriente) {
                $this->paymentService->registerCustomerCharge($lockedSale, $ccPaymentTotal, $context);
            }

            $lockedSale->update([
                'status' => 'completed',
                'payment_status' => $isCuentaCorriente ? ($ccPaymentTotal >= ($totalToValidate - 0.1) ? 'pending' : 'partial') : 'paid',
                'total_surcharge' => $dto->totalSurcharge,
                'shipping_cost' => $dto->shippingCost,
                'amount_due' => $isCuentaCorriente ? $ccPaymentTotal : 0,
                'tendered_amount' => $dto->tenderedAmount,
                'change_amount' => $dto->changeAmount,
            ]);

            event(new \App\Events\SaleCompleted($lockedSale));

            return $lockedSale;
        });
    }

    public function voidSale(Sale $sale, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($sale, $context) {
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status === 'voided') {
                throw new \InvalidArgumentException('Esta venta ya está anulada.');
            }
            if ($lockedSale->status !== 'pending' && $lockedSale->status !== 'completed') {
                throw new \InvalidArgumentException('No se puede anular una venta en este estado.');
            }

            $deliveryNote = DeliveryNote::with('items')->where('sale_id', $lockedSale->id)->first();

            $this->stockService->restoreStockForVoid($lockedSale, $context, $deliveryNote);

            if ($deliveryNote) {
                $deliveryNote->update(['status' => 'cancelled']);
            }

            // Anular Cheques
            \App\Models\ThirdPartyCheck::where('sale_id', $lockedSale->id)->update(['status' => 'voided']);

            // Revertir Cuenta Corriente
            $this->paymentService->revertCustomerTransactionsForVoid($lockedSale, $context);

            $lockedSale->update(['status' => 'voided']);

            event(new \App\Events\SaleCompleted($lockedSale)); // Opcional, podría ser SaleVoided

            return $lockedSale;
        });
    }

    protected function processItems(Sale $sale, array $items, \Illuminate\Database\Eloquent\Collection $products, SaleContextDTO $context): void
    {
        foreach ($items as $itemData) {
            $product = $products[$itemData['product_id']] ?? null;
            if (!$product) continue;
            
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
