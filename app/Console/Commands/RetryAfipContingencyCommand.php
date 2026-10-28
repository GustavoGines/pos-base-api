<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\Afip\AfipWsfeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryAfipContingencyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'afip:retry-contingency';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reintenta autorizar automáticamente ante AFIP las ventas que quedaron en estado de contingencia (pending).';

    public function handle(AfipWsfeService $wsfeService): int
    {
        $pendingSales = Sale::with('electronicInvoice')
            ->where('invoice_status', 'pending')
            ->where('status', '!=', 'voided')
            ->where('created_at', '>=', now()->subDays(5))
            ->orderBy('id', 'asc')
            ->limit(25)
            ->get();

        if ($pendingSales->isEmpty()) {
            $this->info('No hay comprobantes pendientes de contingencia para reintentar.');

            return self::SUCCESS;
        }

        $authorizedCount = 0;

        foreach ($pendingSales as $sale) {
            $lock = Cache::lock("sale_invoice_lock:{$sale->id}", 60);
            if (! $lock->get()) {
                continue;
            }

            try {
                DB::transaction(function () use ($sale, $wsfeService, &$authorizedCount) {
                    $lockedCustomer = $sale->customer_id
                        ? Customer::where('id', $sale->customer_id)->lockForUpdate()->first()
                        : null;

                    $lockedSale = Sale::with('electronicInvoice')->lockForUpdate()->find($sale->id);
                    if (! $lockedSale || $lockedSale->isVoided() || $lockedSale->invoice_status !== 'pending') {
                        return;
                    }

                    $existingInvoice = $lockedSale->electronicInvoice;
                    if ($existingInvoice && $existingInvoice->isAuthorized()) {
                        $lockedSale->update(['invoice_status' => 'invoiced']);
                        $authorizedCount++;

                        return;
                    }

                    $invoiceData = $wsfeService->authorizeInvoice($lockedSale, []);

                    $invoice = ElectronicInvoice::updateOrCreate(
                        ['sale_id' => $lockedSale->id],
                        array_merge($invoiceData, [
                            'issued_at' => now(),
                        ])
                    );

                    $saleUpdates = ['invoice_status' => 'invoiced'];
                    $tributeAmount = round((float) ($invoiceData['tribute_amount'] ?? 0.0), 2);
                    if ($tributeAmount > 0.0 && round((float) ($lockedSale->iibb_perception_amount ?? 0.0), 2) <= 0.0) {
                        $saleUpdates['iibb_perception_amount'] = $tributeAmount;
                        $tributeAlic = $invoiceData['tributes_breakdown'][0]['Alic'] ?? $lockedSale->customer?->iibb_perception_rate ?? null;
                        if ($tributeAlic !== null) {
                            $saleUpdates['iibb_perception_rate'] = (float) $tributeAlic;
                        }
                        $saleUpdates['total'] = round((float) $lockedSale->total + $tributeAmount, 2);

                        if (in_array($lockedSale->payment_status, ['pending', 'partial'], true) && $lockedSale->customer_id) {
                            $saleUpdates['amount_due'] = round((float) $lockedSale->amount_due + $tributeAmount, 2);
                            $customerToUpdate = $lockedCustomer
                                ?? Customer::where('id', $lockedSale->customer_id)->lockForUpdate()->first();
                            if ($customerToUpdate) {
                                $customerToUpdate->balance = round((float) $customerToUpdate->balance + $tributeAmount, 2);
                                $customerToUpdate->save();

                                // BK-HIGH-01: Registrar asiento en el Ledger de Cuenta Corriente en contingencia
                                CustomerTransaction::create([
                                    'customer_id' => $customerToUpdate->id,
                                    'user_id' => $lockedSale->user_id ?? $lockedSale->cashier_id ?? User::first()?->id,
                                    'cash_shift_id' => $lockedSale->cash_shift_id,
                                    'sale_id' => $lockedSale->id,
                                    'type' => 'charge',
                                    'amount' => $tributeAmount,
                                    'balance_after' => $customerToUpdate->balance,
                                    'description' => "Percepción IIBB AFIP Factura {$invoice->voucher_letter}-{$invoice->formatted_number} - Ticket #{$lockedSale->id}",
                                ]);
                            }
                        }
                    }

                    $lockedSale->update($saleUpdates);
                    $authorizedCount++;
                });
            } catch (Throwable $e) {
                Log::warning("Reintento automático AFIP para venta #{$sale->id} aún pendiente/fallido: ".$e->getMessage());
            } finally {
                optional($lock)->release();
            }
        }

        $this->info("Proceso finalizado. Comprobantes autorizados: {$authorizedCount}.");

        return self::SUCCESS;
    }
}
