<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ThirdPartyCheck;
use App\Models\CustomerTransaction;
use App\DTOs\SaleContextDTO;

class PaymentService
{
    /**
     * Validate the sum of payments against the expected total.
     */
    public function validatePaymentsTotal(array $payments, float $expectedTotal): void
    {
        $sumPayments = 0;
        foreach ($payments as $payment) {
            if (abs($payment['base_amount'] + $payment['surcharge_amount'] - $payment['total_amount']) > 0.01) {
                throw new \InvalidArgumentException('Inconsistencia en el pago: base + recargo no coinciden con el total.');
            }
            $sumPayments += $payment['total_amount'];
        }
        
        if ($sumPayments < $expectedTotal - 0.1) {
            throw new \InvalidArgumentException('El monto de los pagos enviados ('. $sumPayments .') no cubre el total esperado de la venta ('. $expectedTotal .').');
        }
    }

    /**
     * Register payments in the sale, including ThirdPartyChecks bridge.
     */
    public function registerPayments(Sale $sale, array $payments, ?array $checkDetails, SaleContextDTO $context): void
    {
        foreach ($payments as $payment) {
            $paymentMethod = PaymentMethod::find($payment['payment_method_id']);

            $sale->payments()->create([
                'payment_method_id' => $payment['payment_method_id'],
                'base_amount'       => $payment['base_amount'],
                'surcharge_amount'  => $payment['surcharge_amount'],
                'total_amount'      => $payment['total_amount'],
            ]);

            // Bridge de Cheque
            if ($paymentMethod && $paymentMethod->code === 'cheque' && !empty($checkDetails)) {
                ThirdPartyCheck::create([
                    'bank_name'    => $checkDetails['bank_name'],
                    'check_number' => $checkDetails['check_number'],
                    'amount'       => $payment['total_amount'],
                    'issue_date'   => $checkDetails['issue_date'],
                    'payment_date' => $checkDetails['payment_date'],
                    'issuer_name'  => $checkDetails['issuer_name'],
                    'issuer_cuit'  => $checkDetails['issuer_cuit'] ?? null,
                    'customer_id'  => $context->customerId,
                    'sale_id'      => $sale->id,
                    'cash_shift_id'=> $context->cashShiftId,
                    'supplier_id'  => null,
                    'status'       => 'in_wallet',
                ]);
            }
        }
    }

    /**
     * Deduct from customer's balance. Pessimistic locking applied.
     */
    public function registerCustomerCharge(Sale $sale, float $amount, SaleContextDTO $context): void
    {
        if (!$context->customerId) return;

        $customer = Customer::lockForUpdate()->find($context->customerId);
        if (!$customer) return;

        $customer->balance += $amount; // El balance de cuenta corriente suele sumar deudas en este POS? En el original: $customer->balance += $ccPaymentTotal; (Sí, balance = deuda)
        $customer->save();

        CustomerTransaction::create([
            'customer_id'   => $customer->id,
            'user_id'       => $context->userId ?? 1,
            'sale_id'       => $sale->id,
            'type'          => 'charge',
            'amount'        => $amount,
            'balance_after' => $customer->balance,
            'description'   => "Venta en Cta. Cte. - Ticket #{$sale->id}",
        ]);
    }

    /**
     * Revert customer transaction asymmetrically (payment to counteract charge).
     */
    public function revertCustomerTransactionsForVoid(Sale $sale, SaleContextDTO $context): void
    {
        $transactions = CustomerTransaction::where('sale_id', $sale->id)->get();
        foreach ($transactions as $tx) {
            if ($tx->type === 'charge') {
                $customer = Customer::lockForUpdate()->find($tx->customer_id);
                if ($customer) {
                    $customer->balance -= $tx->amount;
                    $customer->save();

                    CustomerTransaction::create([
                        'customer_id'   => $customer->id,
                        'user_id'       => $context->userId ?? 1,
                        'sale_id'       => $sale->id,
                        'type'          => 'payment', // payment cancels out the charge
                        'amount'        => $tx->amount,
                        'balance_after' => $customer->balance,
                        'description'   => "Reversión por anulación de Venta #{$sale->id}",
                    ]);
                }
            }
        }
    }
}
