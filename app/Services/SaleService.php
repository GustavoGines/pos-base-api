<?php

namespace App\Services;

use App\DTOs\PaySaleDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Events\SaleCompleted;
use App\Models\DeliveryNote;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use App\Services\Afip\AfipHelper;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        protected StockService $stockService,
        protected PaymentService $paymentService,
        protected AfipWsfeService $afipWsfeService
    ) {}

    public function executeSale(ProcessSaleDTO $dto, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($dto, $context) {
            $isPendingSale = $dto->status === 'pending';

            $ccPaymentTotal = 0;
            $isCuentaCorriente = false;

            if (! $isPendingSale && ! empty($dto->payments)) {
                $paymentMethodIds = array_column($dto->payments, 'payment_method_id');
                $paymentMethods = PaymentMethod::whereIn('id', $paymentMethodIds)->get()->keyBy('id');

                $ccPaymentTotal = collect($dto->payments)->filter(function ($p) use ($paymentMethods) {
                    $method = $paymentMethods->get($p['payment_method_id']);

                    return $method && $method->code === 'cuenta_corriente';
                })->sum('total_amount');

                $isCuentaCorriente = $ccPaymentTotal > 0;
            }

            $total = $dto->total;
            $totalSurcharge = $dto->totalSurcharge;

            $paymentStatus = $isPendingSale ? 'pending' :
                ($isCuentaCorriente ? ($ccPaymentTotal >= ($total + $totalSurcharge + $dto->shippingCost - 0.1) ? 'pending' : 'partial') : 'paid');
            $amountDue = $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total + $dto->shippingCost : 0);

            $sale = Sale::create([
                'total' => $total,
                'total_surcharge' => $totalSurcharge,
                'shipping_cost' => $dto->shippingCost,
                'payment_status' => $paymentStatus,
                'amount_due' => $amountDue,
                'tendered_amount' => $dto->tenderedAmount,
                'change_amount' => $dto->changeAmount,
                'user_id' => $context->userId,
                'customer_id' => $context->customerId,
                'cashier_id' => $context->userId,
                'cash_shift_id' => $context->cashShiftId,
                'delivery_address' => $context->deliveryAddress,
                'price_list' => $context->priceList,
                'iibb_perception_amount' => $dto->iibbPerceptionAmount,
                'iibb_perception_rate' => $dto->iibbPerceptionRate,
                'status' => $dto->status,
            ]);

            if ($context->quoteId) {
                $quote = Quote::find($context->quoteId);
                if ($quote) {
                    $quote->update(['status' => 'approved']);
                }
            }

            if (! $isPendingSale && ! empty($dto->payments)) {
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

            event(new SaleCompleted($sale));

            ReportCacheService::flush();

            return $sale;
        });
    }

    public function payPendingSale(Sale $sale, PaySaleDTO $dto, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($sale, $dto, $context) {
            $lockedSale = Sale::with('items')->lockForUpdate()->find($sale->id);
            if (! $lockedSale) {
                \Log::error('Sale not found! id='.$sale->id.', count='.Sale::count().', without lock='.(Sale::find($sale->id) ? 'yes' : 'no'));
            }

            if ($lockedSale->status !== 'pending') {
                throw new \InvalidArgumentException('Esta venta ya no está en estado pendiente.');
            }

            // Order Recall Items Delta
            if (! empty($dto->items)) {
                $lockedProducts = $this->stockService->reconcileStockDiff($dto->items, $lockedSale, $context);

                $lockedSale->items()->delete();

                $this->processItems($lockedSale, $dto->items, $lockedProducts, $context);

                // Recalculate Totals based on new items
                $newTotal = (float) $lockedSale->items()->sum('subtotal');
                $lockedSale->total = $newTotal;
            }

            $totalToValidate = $lockedSale->total + $dto->totalSurcharge + $dto->shippingCost;
            $this->paymentService->validatePaymentsTotal($dto->payments, $totalToValidate);
            $this->paymentService->registerPayments($lockedSale, $dto->payments, $dto->checkDetails, $context);

            $ccPaymentTotal = 0;
            $isCuentaCorriente = false;
            if (! empty($dto->payments)) {
                $paymentMethodIds = array_column($dto->payments, 'payment_method_id');
                $paymentMethods = PaymentMethod::whereIn('id', $paymentMethodIds)->get()->keyBy('id');

                $ccPaymentTotal = collect($dto->payments)->filter(function ($p) use ($paymentMethods) {
                    $method = $paymentMethods->get($p['payment_method_id']);

                    return $method && $method->code === 'cuenta_corriente';
                })->sum('total_amount');

                $isCuentaCorriente = $ccPaymentTotal > 0;
            }

            if ($isCuentaCorriente) {
                $context->customerId = $context->customerId ?? $lockedSale->customer_id;
                if (! $context->customerId) {
                    throw new \InvalidArgumentException('Debe asociar un cliente a la venta para pagar con Cuenta Corriente.');
                }
                $this->paymentService->registerCustomerCharge($lockedSale, $ccPaymentTotal, $context);
            }

            $lockedSale->update([
                'status' => 'completed',
                'payment_status' => $isCuentaCorriente ? ($ccPaymentTotal >= ($totalToValidate - 0.1) ? 'pending' : 'partial') : 'paid',
                'total' => $lockedSale->total,
                'total_surcharge' => $dto->totalSurcharge,
                'shipping_cost' => $dto->shippingCost,
                'amount_due' => $isCuentaCorriente ? $ccPaymentTotal : 0,
                'tendered_amount' => $dto->tenderedAmount,
                'change_amount' => $dto->changeAmount,
                'cash_shift_id' => $context->cashShiftId ?? $lockedSale->cash_shift_id,
                'cashier_id' => $context->userId ?? $lockedSale->cashier_id,
            ]);

            event(new SaleCompleted($lockedSale));

            ReportCacheService::flush();

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

            // ── PASO 1: Validar estado de Cheques antes de AFIP (bloqueo pesimista) ──
            $checks = ThirdPartyCheck::with('supplier')->where('sale_id', $lockedSale->id)->lockForUpdate()->get();
            foreach ($checks as $check) {
                if ($check->status === 'endorsed') {
                    $supplierName = $check->supplier?->name;
                    $msg = $supplierName
                        ? "No se puede anular la venta porque el cheque #{$check->check_number} fue endosado al proveedor '{$supplierName}'. Debe anular primero el pago al proveedor en Movimientos de Caja para reincorporarlo a cartera."
                        : "No se puede anular la venta porque el cheque #{$check->check_number} figura como endosado. Debe reincorporarlo a cartera primero.";
                    throw new \InvalidArgumentException($msg);
                }
                if ($check->status === 'deposited') {
                    throw new \InvalidArgumentException(
                        "No se puede anular la venta porque el cheque #{$check->check_number} ya fue depositado en el banco."
                    );
                }
                if ($check->status === 'rejected') {
                    throw new \InvalidArgumentException(
                        "No se puede anular la venta porque el cheque #{$check->check_number} figura como rechazado."
                    );
                }
                if ($check->status !== 'in_wallet' && $check->status !== 'voided') {
                    throw new \InvalidArgumentException(
                        "No se puede anular la venta porque el cheque #{$check->check_number} tiene un estado incompatible ({$check->status})."
                    );
                }
            }

            // ── PASO 2: Emisión de Nota de Crédito AFIP si corresponde ──
            $electronicInvoice = $lockedSale->electronicInvoice;
            if ($electronicInvoice && $electronicInvoice->cae && empty($electronicInvoice->credit_note_cae)) {
                $ncVoucherType = match ($electronicInvoice->voucher_type) {
                    AfipHelper::VOUCHER_FACTURA_A => AfipHelper::VOUCHER_NOTA_CREDITO_A,
                    AfipHelper::VOUCHER_FACTURA_C => AfipHelper::VOUCHER_NOTA_CREDITO_C,
                    default => AfipHelper::VOUCHER_NOTA_CREDITO_B,
                };

                $cbtesAsoc = [
                    [
                        'Tipo' => $electronicInvoice->voucher_type,
                        'PtoVta' => $electronicInvoice->point_of_sale,
                        'Nro' => $electronicInvoice->voucher_number,
                        // 'Cuit' lo completa AfipWsfeService con el CUIT emisor (comprobante propio)
                        'CbteFch' => $electronicInvoice->issued_at ? $electronicInvoice->issued_at->format('Ymd') : date('Ymd'),
                    ]
                ];

                $options = [
                    'voucher_type' => $ncVoucherType,
                    'point_of_sale' => $electronicInvoice->point_of_sale,
                    'doc_type' => $electronicInvoice->doc_type,
                    'doc_number' => $electronicInvoice->doc_number,
                    'receiver_name' => $electronicInvoice->receiver_name,
                    'receiver_address' => $electronicInvoice->receiver_address,
                    'receiver_tax_condition' => $electronicInvoice->receiver_tax_condition,
                    'cbtes_asoc' => $cbtesAsoc,
                ];

                try {
                    $ncData = $this->afipWsfeService->authorizeInvoice($lockedSale, $options);
                    $electronicInvoice->update([
                        'credit_note_cae' => $ncData['cae'],
                        'credit_note_expiration' => $ncData['cae_expiration'],
                        'credit_note_number' => $ncData['voucher_number'],
                        'credit_note_issued_at' => now(),
                        'credit_note_voucher_type' => $ncVoucherType,
                        // H-09: Save QR Data (TODO in next step if required)
                    ]);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException("No se pudo emitir la Nota de Crédito en AFIP. Motivo: " . $e->getMessage());
                }
            }

            // ── PASO 3: Restitución local (Stock, Remito) ──
            $deliveryNote = DeliveryNote::with('items')->where('sale_id', $lockedSale->id)->first();

            $this->stockService->restoreStockForVoid($lockedSale, $context, $deliveryNote);

            if ($deliveryNote) {
                $deliveryNote->update(['status' => 'cancelled']);
            }

            // ── PASO 4: Si los cheques están 'in_wallet', procedemos a anularlos ──

            // Si están 'in_wallet', procedemos a anularlos
            foreach ($checks as $check) {
                if ($check->status === 'in_wallet') {
                    $check->update(['status' => 'voided']);
                }
            }

            // Revertir Cuenta Corriente
            $this->paymentService->revertCustomerTransactionsForVoid($lockedSale, $context);

            $lockedSale->update([
                'status' => 'voided',
                'voided_by_user_id' => $context->userId,
                'void_authorized_by_admin_id' => $context->authorizedByAdminId,
                'voided_at' => now(),
                'void_reason' => $context->voidReason,
            ]);

            event(new SaleCompleted($lockedSale)); // Opcional, podría ser SaleVoided

            ReportCacheService::flush();

            return $lockedSale;
        });
    }

    protected function processItems(Sale $sale, array $items, Collection $products, SaleContextDTO $context): void
    {
        foreach ($items as $itemData) {
            $product = $products[$itemData['product_id']] ?? null;
            if (! $product) {
                continue;
            }

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

            $ivaRate = (float) ($product->iva_rate ?? 21.00);
            $taxCalc = AfipHelper::calculateNetAndIva($subtotal, $ivaRate);

            $sale->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_cost_price' => $costPrice,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'iva_rate' => $ivaRate,
                'net_amount' => $taxCalc['net'],
                'iva_amount' => $taxCalc['iva'],
            ]);
        }
    }

    protected function createDeliveryNote(Sale $sale, array $items, string $fulfillmentStatus): void
    {
        $deliveryNote = DeliveryNote::create([
            'sale_id' => $sale->id,
            'status' => $fulfillmentStatus,
        ]);

        foreach ($items as $itemData) {
            $qty = (float) $itemData['quantity'];
            $deliveryNote->items()->create([
                'product_id' => $itemData['product_id'],
                'quantity_purchased' => $qty,
                'quantity_delivered' => $fulfillmentStatus === 'delivered' ? $qty : 0,
            ]);
        }
    }
}
