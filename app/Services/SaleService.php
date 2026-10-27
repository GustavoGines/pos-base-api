<?php

namespace App\Services;

use App\DTOs\PaySaleDTO;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Events\SaleCompleted;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\DeliveryNote;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use App\Services\Afip\AfipHelper;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        protected StockService $stockService,
        protected PaymentService $paymentService,
        protected AfipWsfeService $afipWsfeService
    ) {}

    protected function hasClientTransactionColumn(): bool
    {
        try {
            return Schema::hasColumn('sales', 'client_transaction_id');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function findIdempotentSale(?string $clientTransactionId): ?Sale
    {
        if (empty($clientTransactionId)) {
            return null;
        }

        if ($this->hasClientTransactionColumn()) {
            $existing = Sale::where('client_transaction_id', $clientTransactionId)
                ->where('status', '!=', 'voided')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $cachedId = Cache::get("pos_sale_idemp:{$clientTransactionId}");
        if ($cachedId) {
            $existing = Sale::find($cachedId);
            if ($existing && $existing->status !== 'voided') {
                return $existing;
            }
        }

        return null;
    }

    public function executeSale(ProcessSaleDTO $dto, SaleContextDTO $context): Sale
    {
        // F3-RESIL-01 / FN-F3-04: Idempotencia rápida previa a bloqueo de turno
        $existingSale = $this->findIdempotentSale($dto->clientTransactionId);
        if ($existingSale) {
            return $existingSale;
        }

        return DB::transaction(function () use ($dto, $context) {
            // F3-CAJA-03 / FN-F3-03: Bloqueo pesimista de CashShift como PRIMER lock de la transacción
            // para evitar carreras con closeShift y prevenir deadlocks AB-BA con productos.
            if ($context->cashShiftId) {
                $lockedShift = CashShift::where('id', $context->cashShiftId)->lockForUpdate()->first();
                if (! $lockedShift || $lockedShift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'cash_shift_id' => ['El turno de caja ya fue cerrado. Por favor, recargue la aplicación.'],
                    ]);
                }
            }

            // Re-verificar idempotencia dentro de la sección crítica serializada por el turno
            $existingInsideLock = $this->findIdempotentSale($dto->clientTransactionId);
            if ($existingInsideLock) {
                return $existingInsideLock;
            }

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

                if ($isCuentaCorriente) {
                    $featuresJson = DB::table('business_settings')->where('key', 'license_features_dict')->value('value');
                    $features = ! empty($featuresJson) ? json_decode($featuresJson, true) : [];
                    if (! empty($features) && (! isset($features['current_accounts']) || $features['current_accounts'] !== true)) {
                        throw ValidationException::withMessages([
                            'payment_methods' => 'El cobro con Cuenta Corriente (Fiado) requiere el módulo de Cuentas Corrientes habilitado en su licencia.',
                        ]);
                    }

                    // FINDING-CC-01: Bloquear Customer en orden canónico (1° CashShift -> 2° Customer -> 3° Sale -> 4° Product)
                    // y verificar credit_limit antes de crear la venta o descontar stock.
                    if (! $context->customerId) {
                        throw new \InvalidArgumentException('Debe asociar un cliente a la venta para pagar con Cuenta Corriente.');
                    }

                    $lockedCustomer = \App\Models\Customer::where('id', $context->customerId)->lockForUpdate()->first();
                    if (! $lockedCustomer) {
                        throw new \InvalidArgumentException('El cliente seleccionado no existe.');
                    }

                    if (! $lockedCustomer->is_internal_account && (float) $lockedCustomer->credit_limit > 0) {
                        $projectedBalance = round((float) $lockedCustomer->balance + (float) $ccPaymentTotal, 2);
                        if ($projectedBalance > (round((float) $lockedCustomer->credit_limit, 2) + 0.01) && empty($context->authorizedByAdminId)) {
                            $available = max(0.0, round((float) $lockedCustomer->credit_limit - (float) $lockedCustomer->balance, 2));
                            throw new \InvalidArgumentException(
                                "El monto en Cuenta Corriente (\${$ccPaymentTotal}) supera el límite de crédito disponible del cliente (\${$available}). Se requiere autorización de supervisor."
                            );
                        }
                    }
                }
            }

            $productIds = array_column($dto->items, 'product_id');
            $lockedProducts = $this->stockService->lockProducts($productIds);

            $total = $dto->total;
            $totalSurcharge = $dto->totalSurcharge;

            $paymentStatus = $isPendingSale ? 'pending' :
                ($isCuentaCorriente ? ($ccPaymentTotal >= ($total + $totalSurcharge + $dto->shippingCost - 0.1) ? 'pending' : 'partial') : 'paid');
            $amountDue = $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total + $totalSurcharge + $dto->shippingCost : 0);

            $saleAttributes = [
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
            ];

            if (! empty($dto->clientTransactionId) && $this->hasClientTransactionColumn()) {
                $saleAttributes['client_transaction_id'] = $dto->clientTransactionId;
            }

            $sale = Sale::create($saleAttributes);

            if (! empty($dto->clientTransactionId)) {
                Cache::put("pos_sale_idemp:{$dto->clientTransactionId}", $sale->id, now()->addHours(24));
            }

            if ($context->quoteId) {
                $quote = Quote::find($context->quoteId);
                if ($quote) {
                    $quote->update(['status' => 'approved']);
                }
            }

            // Fix F1-BAJA-10 + FN-4: Procesar ítems y reconciliar total autoritativo ANTES de validar y registrar pagos
            $this->processItems($sale, $dto->items, $lockedProducts, $context);

            $itemsSubtotal = round((float) $sale->items()->sum('subtotal'), 2);
            $reconciledTotal = round($itemsSubtotal + (float) $dto->iibbPerceptionAmount, 2);
            if (abs($reconciledTotal - (float) $sale->total) > 0.001) {
                $total = $reconciledTotal;
                $paymentStatus = $isPendingSale ? 'pending' :
                    ($isCuentaCorriente ? ($ccPaymentTotal >= ($total + $totalSurcharge + $dto->shippingCost - 0.1) ? 'pending' : 'partial') : 'paid');
                $amountDue = $isCuentaCorriente ? $ccPaymentTotal : ($isPendingSale ? $total + $totalSurcharge + $dto->shippingCost : 0);

                $sale->update([
                    'total' => $total,
                    'payment_status' => $paymentStatus,
                    'amount_due' => $amountDue,
                ]);
            }

            if (! $isPendingSale && ! empty($dto->payments)) {
                $this->paymentService->validatePaymentsTotal($dto->payments, $total + $totalSurcharge + $dto->shippingCost);
                $this->paymentService->registerPayments($sale, $dto->payments, $dto->checkDetails, $context);

                if ($isCuentaCorriente) {
                    $this->paymentService->registerCustomerCharge($sale, $ccPaymentTotal, $context);
                }
            }

            $this->stockService->processCartStock($dto->items, $lockedProducts, $sale, $context, $dto->requiresDispatch, $dto->fulfillmentStatus);

            if ($dto->requiresDispatch) {
                $this->createDeliveryNote($sale, $dto->items, $dto->fulfillmentStatus);
            }

            // Fix F1-ALTA-03 & FN-2: Enqueue asynchronous stock synchronization (including parent combos)
            SyncProductStockToPlatformsJob::dispatchForProducts($lockedProducts->keys());

            $this->broadcastSaleCompletedSafely($sale);

            ReportCacheService::flush();

            return $sale;
        });
    }

    public function payPendingSale(Sale $sale, PaySaleDTO $dto, SaleContextDTO $context): Sale
    {
        return DB::transaction(function () use ($sale, $dto, $context) {
            // F3-CAJA-03 / FN-F3-03 / CC-04: Bloqueo pesimista del turno de caja SIEMPRE en primer término
            // (incluso si el cliente v1.8.5 omitió cash_shift_id y se usa el turno original de la venta)
            // para respetar el orden canónico 1° CashShift -> 2° Sale -> 3° Product -> 4° Customer.
            $shiftIdToLock = $context->cashShiftId ?? $sale->cash_shift_id;
            if ($shiftIdToLock) {
                $lockedShift = CashShift::where('id', $shiftIdToLock)->lockForUpdate()->first();
                if (! $lockedShift || $lockedShift->status !== 'open') {
                    $msg = $context->cashShiftId
                        ? 'El turno de caja ya fue cerrado. Por favor, recargue la aplicación.'
                        : 'El turno de caja de esta venta pendiente ya fue cerrado. Debe enviar un cash_shift_id activo.';
                    throw ValidationException::withMessages([
                        'cash_shift_id' => [$msg],
                    ]);
                }
            }

            $lockedSale = Sale::with('items')->lockForUpdate()->find($sale->id);
            if (! $lockedSale) {
                \Log::error('Sale not found! id='.$sale->id.', count='.Sale::count().', without lock='.(Sale::find($sale->id) ? 'yes' : 'no'));
            }

            if (! $shiftIdToLock && $lockedSale?->cash_shift_id) {
                $lockedShift = CashShift::where('id', $lockedSale->cash_shift_id)->lockForUpdate()->first();
                if (! $lockedShift || $lockedShift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'cash_shift_id' => ['El turno de caja de esta venta pendiente ya fue cerrado. Debe enviar un cash_shift_id activo.'],
                    ]);
                }
            }

            if ($lockedSale->status !== 'pending') {
                // COL-07: Retorno idempotente cuando un reintento de red vuelve a enviar el cobro
                // de una orden en espera que acaba de completarse en este mismo turno.
                $targetShiftId = $context->cashShiftId ?? $lockedSale->cash_shift_id;
                if (
                    $lockedSale->status === 'completed'
                    && (int) $lockedSale->cash_shift_id === (int) $targetShiftId
                    && abs((float) $lockedSale->total_surcharge - (float) $dto->totalSurcharge) <= 0.05
                    && abs((float) $lockedSale->shipping_cost - (float) $dto->shippingCost) <= 0.05
                    && $lockedSale->payments()->exists()
                ) {
                    $lockedSale->load(['items.product', 'user', 'cashier', 'customer', 'payments.paymentMethod']);

                    return $lockedSale;
                }

                throw new \InvalidArgumentException('Esta venta ya no está en estado pendiente.');
            }

            // Order Recall Items Delta
            if (! empty($dto->items)) {
                $lockedProducts = $this->stockService->reconcileStockDiff($dto->items, $lockedSale, $context);

                $lockedSale->items()->delete();

                $this->processItems($lockedSale, $dto->items, $lockedProducts, $context);

                // Recalculate Totals based on new items
                $newTotal = round((float) $lockedSale->items()->sum('subtotal'), 2);
                $lockedSale->total = round($newTotal + (float) $dto->iibbPerceptionAmount, 2);
            }

            $totalToValidate = round((float) $lockedSale->total + (float) $dto->totalSurcharge + (float) $dto->shippingCost, 2);
            $this->paymentService->validatePaymentsTotal($dto->payments, $totalToValidate);

            $effectiveCustomerId = $context->customerId ?? $lockedSale->customer_id;
            $lockedSale->customer_id = $effectiveCustomerId;

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
                $featuresJson = DB::table('business_settings')->where('key', 'license_features_dict')->value('value');
                $features = ! empty($featuresJson) ? json_decode($featuresJson, true) : [];
                if (! empty($features) && (! isset($features['current_accounts']) || $features['current_accounts'] !== true)) {
                    throw ValidationException::withMessages([
                        'payment_methods' => 'El cobro con Cuenta Corriente (Fiado) requiere el módulo de Cuentas Corrientes habilitado en su licencia.',
                    ]);
                }

                $effectiveCustomerId = $context->customerId ?? $lockedSale->customer_id;
                if (! $effectiveCustomerId) {
                    throw new \InvalidArgumentException('Debe asociar un cliente a la venta para pagar con Cuenta Corriente.');
                }
                $this->paymentService->registerCustomerCharge($lockedSale, $ccPaymentTotal, $context);
            } else {
                $effectiveCustomerId = $context->customerId ?? $lockedSale->customer_id;
            }

            $lockedSale->update([
                'status' => 'completed',
                'payment_status' => $isCuentaCorriente ? ($ccPaymentTotal >= ($totalToValidate - 0.1) ? 'pending' : 'partial') : 'paid',
                'total' => $lockedSale->total,
                'total_surcharge' => $dto->totalSurcharge,
                'shipping_cost' => $dto->shippingCost,
                'iibb_perception_amount' => $dto->iibbPerceptionAmount,
                'iibb_perception_rate' => $dto->iibbPerceptionRate,
                'amount_due' => $isCuentaCorriente ? $ccPaymentTotal : 0,
                'tendered_amount' => $dto->tenderedAmount,
                'change_amount' => $dto->changeAmount,
                'cash_shift_id' => $context->cashShiftId ?? $lockedSale->cash_shift_id,
                'cashier_id' => $context->userId ?? $lockedSale->cashier_id,
                'customer_id' => $effectiveCustomerId,
            ]);

            // Enqueue asynchronous stock synchronization to online platforms (including parent combos)
            if (isset($lockedProducts) && $lockedProducts instanceof \Illuminate\Support\Collection) {
                SyncProductStockToPlatformsJob::dispatchForProducts($lockedProducts->keys());
            } else {
                $lockedSale->loadMissing('items');
                SyncProductStockToPlatformsJob::dispatchForProducts($lockedSale->items->pluck('product_id'));
            }

            $this->broadcastSaleCompletedSafely($lockedSale);

            ReportCacheService::flush();

            $lockedSale->load(['items.product', 'user', 'cashier', 'customer', 'payments.paymentMethod']);

            return $lockedSale;
        });
    }

    public function voidSale(Sale $sale, SaleContextDTO $context): Sale
    {
        // ── FASE A: Pre-validación de invariantes antes de comunicar a AFIP ──
        DB::transaction(function () use ($sale) {
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status === 'voided') {
                throw new \InvalidArgumentException('Esta venta ya está anulada.');
            }
            if ($lockedSale->status !== 'pending' && $lockedSale->status !== 'completed') {
                throw new \InvalidArgumentException('No se puede anular una venta en este estado.');
            }

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
        });

        // ── FASE B: Emisión de Nota de Crédito AFIP fuera de la transacción de reversión local ──
        // Si AFIP autoriza el CAE, se persiste inmediatamente en electronic_invoices para garantizar
        // idempotencia fiscal y evitar duplicar Notas de Crédito si fallara una operación local posterior.
        $saleForAfip = Sale::with('electronicInvoice')->find($sale->id);
        $electronicInvoice = $saleForAfip?->electronicInvoice;
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
                ],
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
                $ncData = $this->afipWsfeService->authorizeInvoice($saleForAfip, $options);
                $electronicInvoice->update([
                    'credit_note_cae' => $ncData['cae'],
                    'credit_note_expiration' => $ncData['cae_expiration'],
                    'credit_note_number' => $ncData['voucher_number'],
                    'credit_note_issued_at' => now(),
                    'credit_note_voucher_type' => $ncVoucherType,
                    'credit_note_qr_data' => $ncData['qr_data'] ?? null,
                ]);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('No se pudo emitir la Nota de Crédito en AFIP. Motivo: '.$e->getMessage());
            }
        }

        // ── FASE C: Restitución transaccional local (Stock, Remito, Cheques, Cta Cte, Caja) ──
        return DB::transaction(function () use ($sale, $context) {
            $lockedSale = Sale::lockForUpdate()->find($sale->id);

            if ($lockedSale->status === 'voided') {
                throw new \InvalidArgumentException('Esta venta ya está anulada.');
            }
            if ($lockedSale->status !== 'pending' && $lockedSale->status !== 'completed') {
                throw new \InvalidArgumentException('No se puede anular una venta en este estado.');
            }

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

            $deliveryNote = DeliveryNote::with('items')->where('sale_id', $lockedSale->id)->first();

            $this->stockService->restoreStockForVoid($lockedSale, $context, $deliveryNote);

            if ($deliveryNote) {
                $deliveryNote->update(['status' => 'cancelled']);
            }

            // Si están 'in_wallet', procedemos a anularlos
            foreach ($checks as $check) {
                if ($check->status === 'in_wallet') {
                    $check->update(['status' => 'voided']);
                }
            }

            // Revertir Cuenta Corriente
            $this->paymentService->revertCustomerTransactionsForVoid($lockedSale, $context);

            if ($lockedSale->cash_shift_id !== $context->cashShiftId) {
                $originShift = $lockedSale->cash_shift_id
                    ? CashShift::where('id', $lockedSale->cash_shift_id)->lockForUpdate()->first()
                    : null;

                // MC-05: Solo generar egreso compensatorio ("Devolución Turno anterior") cuando el turno original
                // de la venta YA ESTÁ CERRADO (su balance quedó congelado). Si el turno original sigue ABIERTO
                // (ej. anulación desde Caja 1 de una venta del turno actual abierto de Caja 2), el cambio de
                // status a 'voided' YA descuenta automáticamente el efectivo de calculateLiveTotals($originShift),
                // por lo que crear además un CashMovement en Caja 1 duplicaría el descuento de efectivo.
                if (! $originShift || $originShift->status !== 'open') {
                    $cashRefundAmount = $lockedSale->payments()
                        ->whereHas('paymentMethod', fn ($q) => $q->where('is_cash', true))
                        ->sum('total_amount');

                    if ($cashRefundAmount > 0 && $context->cashShiftId) {
                        CashMovement::create([
                            'cash_shift_id' => $context->cashShiftId,
                            'user_id' => $context->userId,
                            'amount' => $cashRefundAmount,
                            'payment_method' => 'cash',
                            'type' => 'expense',
                            'category' => 'refund',
                            'description' => "Devolución en efectivo por anulación de Venta #{$lockedSale->id} (Turno anterior)",
                        ]);
                    }
                }
            }

            $voidPayload = [
                'status' => 'voided',
                'voided_by_user_id' => $context->userId,
                'void_authorized_by_admin_id' => $context->authorizedByAdminId,
                'voided_at' => now(),
                'void_reason' => $context->voidReason,
            ];

            if ($this->hasClientTransactionColumn() && ! empty($lockedSale->client_transaction_id)) {
                Cache::forget("pos_sale_idemp:{$lockedSale->client_transaction_id}");
                $voidPayload['client_transaction_id'] = null;
            }

            $lockedSale->update($voidPayload);

            // Fix F1-ALTA-04: Sincronizar stock restituido hacia plataformas e-commerce post-commit
            $lockedSale->loadMissing('items');
            SyncProductStockToPlatformsJob::dispatchForProducts($lockedSale->items->pluck('product_id'));

            $this->broadcastSaleCompletedSafely($lockedSale); // Opcional, podría ser SaleVoided

            ReportCacheService::flush();

            return $lockedSale;
        });
    }

    protected function processItems(Sale $sale, array $items, Collection $products, SaleContextDTO $context): void
    {
        foreach ($items as $itemData) {
            $product = $products[$itemData['product_id']] ?? null;
            if (! $product) {
                // Fix F1-ALTA-02: No omitir silenciosamente productos dados de baja (SoftDeletes)
                throw new \InvalidArgumentException("El producto con ID {$itemData['product_id']} ya no está disponible o fue dado de baja.");
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

    /**
     * Dispara el evento de WebSocket hacia Reverb sin abortar la transacción de venta
     * si el servicio local en el puerto 8080 se encuentra temporalmente inactivo.
     */
    protected function broadcastSaleCompletedSafely(Sale $sale): void
    {
        try {
            event(new SaleCompleted($sale));
        } catch (\Throwable $e) {
            \Log::warning('No se pudo emitir evento WebSocket SaleCompleted (Reverb 8080 inactivo): ' . $e->getMessage(), [
                'sale_id' => $sale->id,
            ]);
        }
    }
}
