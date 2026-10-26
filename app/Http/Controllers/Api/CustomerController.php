<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->has('search') && ! empty($request->search)) {
            $searchTerm = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                    ->orWhere('document_number', 'like', $searchTerm);
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();

        if (! isset($validated['balance'])) {
            $validated['balance'] = 0.00;
        }

        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        $customer->load(['transactions' => function ($query) {
            $query->latest()->take(10);
        }]);

        return response()->json($customer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        if (abs((float) $customer->balance) > 0.001) {
            $msg = (float) $customer->balance > 0
                ? 'No se puede eliminar un cliente con saldo pendiente (deudor).'
                : 'No se puede eliminar un cliente con saldo a favor pendiente de reintegro.';

            return response()->json([
                'message' => $msg,
            ], 403);
        }

        $customer->delete();

        return response()->json(null, 204);
    }

    /**
     * Retorna los tickets pendientes de pago de un cliente.
     * Tickets con payment_method = 'cuenta_corriente' y payment_status 'pending' o 'partial'.
     */
    public function getPendingSales(Customer $customer)
    {
        $pendingSales = Sale::where('customer_id', $customer->id)
            ->where('amount_due', '>', 0)
            ->where('status', 'completed')
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($pendingSales);
    }

    /**
     * Registrar un pago (abono) para el cliente, con soporte para:
     * - Pago global: reduce el balance del cliente.
     * - Pago de ticket específico: también reduce el amount_due del ticket.
     *
     * Garantiza integridad transaccional (ACID) con DB::transaction + lockForUpdate.
     */
    public function registerPayment(Request $request, Customer $customer)
    {
        $request->validate([
            'payments' => 'sometimes|array',
            'payments.*.method' => 'required_with:payments|string|in:cash,card,transfer,cheque,efectivo,tarjeta,transferencia',
            'payments.*.amount' => 'required_with:payments|numeric|gt:0',
            'payments.*.check_details' => 'nullable|array',
            'amount' => 'required_without:payments|numeric|gt:0',
            'payment_method' => 'required_without:payments|string|in:cash,card,transfer,cheque,efectivo,tarjeta,transferencia',
            'description' => 'nullable|string|max:255',
            'sale_ids' => 'nullable|array',
            'sale_ids.*' => 'integer|exists:sales,id',
            'check_details' => 'nullable|array|required_if:payment_method,cheque',
            'cash_shift_id' => 'nullable|integer|exists:cash_shifts,id',
            'cash_register_id' => 'nullable|integer|exists:cash_registers,id',
            'is_refund' => 'nullable|boolean',
        ]);

        $isRefund = $request->input('is_refund', false);

        $rawPayments = $request->filled('payments') ? $request->payments : [
            [
                'method' => $request->payment_method,
                'amount' => (float) $request->amount,
                'check_details' => $request->check_details,
            ],
        ];

        $methodMap = [
            'efectivo' => 'cash',
            'tarjeta' => 'card',
            'transferencia' => 'transfer',
        ];

        $payments = [];
        foreach ($rawPayments as $idx => $p) {
            $rawMethod = strtolower(trim((string) ($p['method'] ?? 'cash')));
            $normalizedMethod = $methodMap[$rawMethod] ?? $rawMethod;

            // CC-05: Exigir datos obligatorios del cheque para impedir pérdida de trazabilidad en cartera
            if ($normalizedMethod === 'cheque') {
                $cd = $p['check_details'] ?? $request->check_details;
                if (
                    ! is_array($cd)
                    || empty(trim((string) ($cd['bank_name'] ?? '')))
                    || empty(trim((string) ($cd['check_number'] ?? '')))
                ) {
                    return response()->json([
                        'message' => 'Debe completar los datos obligatorios del cheque (banco y número).',
                        'errors' => [
                            "payments.{$idx}.check_details" => ['Debe completar los datos obligatorios del cheque (banco y número).'],
                        ],
                    ], 422);
                }
                $p['check_details'] = $cd;
            }

            $p['method'] = $normalizedMethod;
            $payments[] = $p;
        }

        $totalAmount = array_sum(array_column($payments, 'amount'));

        try {
            $transaction = DB::transaction(function () use ($customer, $totalAmount, $payments, $request, $isRefund) {
                // COL-02 / CC-01: Adquirir lock pesimista en orden canónico estricto:
                // 1° CashShift -> 2° Customer -> 3° Sale (idéntico a SaleService para evitar Deadlocks InnoDB)
                if ($request->filled('cash_shift_id')) {
                    $activeShift = CashShift::where('id', $request->cash_shift_id)->where('status', 'open')->lockForUpdate()->first();
                } elseif ($request->filled('cash_register_id')) {
                    $activeShift = CashShift::where('cash_register_id', $request->cash_register_id)->where('status', 'open')->latest('id')->lockForUpdate()->first();
                } else {
                    $activeShift = CashShift::where('status', 'open')->latest('id')->lockForUpdate()->first();
                }

                if (! $activeShift) {
                    throw ValidationException::withMessages([
                        'cash_shift_id' => ['No hay turno de caja abierto para registrar este movimiento.'],
                    ]);
                }

                $lockedCustomer = Customer::where('id', $customer->id)->lockForUpdate()->first();

                if ($isRefund) {
                    if ($lockedCustomer->balance >= 0) {
                        throw ValidationException::withMessages([
                            'amount' => ['No se puede procesar un reintegro porque el cliente no tiene saldo a favor.'],
                        ]);
                    }
                    if ($totalAmount > abs($lockedCustomer->balance)) {
                        throw ValidationException::withMessages([
                            'amount' => ["El monto del reintegro (\${$totalAmount}) no puede superar el saldo a favor actual (\$".abs($lockedCustomer->balance).').'],
                        ]);
                    }
                } else {
                    if ($totalAmount > $lockedCustomer->balance) {
                        throw ValidationException::withMessages([
                            'amount' => ["El monto del abono (\${$totalAmount}) no puede superar el saldo actual de la deuda (\${$lockedCustomer->balance})."],
                        ]);
                    }
                }

                $description = $request->filled('description') ? $request->description : ($isRefund ? 'Devolución de saldo a favor' : 'Abono en caja');
                $remainingAmount = $totalAmount;
                $processedSales = [];

                if (! $isRefund) {
                    // ── Distribuir a tickets específicos o a los más antiguos pendientes ──────────────────────
                    if ($request->filled('sale_ids')) {
                        $sales = Sale::whereIn('id', $request->sale_ids)
                            ->where('customer_id', $lockedCustomer->id)
                            ->where('status', 'completed')
                            ->lockForUpdate()
                            ->orderBy('created_at', 'asc')
                            ->get();
                    } else {
                        $sales = Sale::where('customer_id', $lockedCustomer->id)
                            ->where('status', 'completed')
                            ->whereIn('payment_status', ['pending', 'partial'])
                            ->lockForUpdate()
                            ->orderBy('created_at', 'asc')
                            ->get();
                    }

                    /** @var Collection<int, Sale> $sales */
                    foreach ($sales as $sale) {
                        if ($remainingAmount <= 0) {
                            break;
                        }

                        $payForThisSale = min((float) $sale->amount_due, $remainingAmount);

                        if ($payForThisSale > 0) {
                            $sale->amount_due -= $payForThisSale;
                            $sale->payment_status = $sale->amount_due <= 0 ? 'paid' : 'partial';
                            $sale->save();

                            $remainingAmount -= $payForThisSale;
                            $processedSales[] = $sale->id;
                        }
                    }

                    // CC-03: Si se seleccionaron sale_ids específicos pero el abono los supera, imputar
                    // el excedente por orden FIFO a los demás tickets pendientes para evitar desincronizar
                    // customer.balance respecto a sum(sales.amount_due).
                    if ($request->filled('sale_ids') && $remainingAmount > 0.001) {
                        $otherPendingSales = Sale::where('customer_id', $lockedCustomer->id)
                            ->whereNotIn('id', $request->sale_ids)
                            ->where('status', 'completed')
                            ->whereIn('payment_status', ['pending', 'partial'])
                            ->where('amount_due', '>', 0)
                            ->lockForUpdate()
                            ->orderBy('created_at', 'asc')
                            ->get();

                        foreach ($otherPendingSales as $extraSale) {
                            if ($remainingAmount <= 0) {
                                break;
                            }

                            $payExtra = min((float) $extraSale->amount_due, $remainingAmount);
                            if ($payExtra > 0) {
                                $extraSale->amount_due -= $payExtra;
                                $extraSale->payment_status = $extraSale->amount_due <= 0 ? 'paid' : 'partial';
                                $extraSale->save();

                                $remainingAmount -= $payExtra;
                                $processedSales[] = $extraSale->id;
                            }
                        }
                    }

                    if (! empty($processedSales) && ! $request->filled('description')) {
                        if ($request->filled('sale_ids')) {
                            $description = 'Pago de Tickets: #'.implode(', #', $processedSales);
                        } else {
                            $description = 'Abono Global aplicado a Tickets: #'.implode(', #', $processedSales);
                        }
                    }
                }

                // ── CC-07: Actualizar el balance progresivamente por cada método de pago ──
                // Así cada registro en el Ledger refleja su balance_after cronológico exacto.
                $runningBalance = (float) $lockedCustomer->balance;
                $actingUserId = $request->user()?->id ?? $request->attributes->get('authenticated_user')?->id ?? User::first()?->id ?? 1;
                $trx = null;

                foreach ($payments as $payment) {
                    $paymentAmount = (float) $payment['amount'];
                    $paymentMethod = $payment['method'];

                    if ($isRefund) {
                        $runningBalance = round($runningBalance + $paymentAmount, 2);
                    } else {
                        $runningBalance = round($runningBalance - $paymentAmount, 2);
                    }

                    $trx = CustomerTransaction::create([
                        'customer_id' => $lockedCustomer->id,
                        'user_id' => $actingUserId,
                        'cash_shift_id' => $activeShift ? $activeShift->id : null,
                        'sale_id' => count($processedSales) === 1 ? $processedSales[0] : null,
                        'type' => $isRefund ? 'refund' : 'payment',
                        'payment_method' => $paymentMethod,
                        'amount' => $paymentAmount,
                        'balance_after' => $runningBalance,
                        'description' => $description,
                    ]);

                    // ── Crear cheque si aplica ───────────────────────────────────
                    if ($paymentMethod === 'cheque' && isset($payment['check_details'])) {
                        $cd = $payment['check_details'];
                        ThirdPartyCheck::create([
                            'cash_shift_id' => $activeShift ? $activeShift->id : null,
                            'customer_id' => $lockedCustomer->id,
                            'sale_id' => count($processedSales) === 1 ? $processedSales[0] : null,
                            'bank_name' => $cd['bank_name'] ?? '',
                            'check_number' => $cd['check_number'] ?? '',
                            'issuer_cuit' => $cd['issuer_cuit'] ?? '',
                            'issuer_name' => $cd['issuer_name'] ?? '',
                            'issue_date' => ! empty($cd['issue_date']) ? $cd['issue_date'] : now()->toDateString(),
                            'payment_date' => ! empty($cd['payment_date']) ? $cd['payment_date'] : now()->toDateString(),
                            'amount' => $paymentAmount,
                            'status' => 'in_wallet',
                        ]);
                    }
                }

                $lockedCustomer->balance = $runningBalance;
                $lockedCustomer->save();

                return $trx;
            });

            $customer->refresh();

            return response()->json([
                'message' => 'Abono registrado exitosamente.',
                'transaction' => $transaction,
                'new_balance' => $customer->balance,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al procesar el pago.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
