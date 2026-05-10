<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\ThirdPartyCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = '%' . $request->input('search') . '%';
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'phone'           => 'nullable|string|max:20',
            'document_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('customers')->whereNull('deleted_at')
            ],
            'credit_limit'    => 'nullable|numeric|min:0',
            'balance'         => 'nullable|numeric',
            'default_price_tier' => 'nullable|string|in:base,wholesale,card',
            'delivery_address' => 'nullable|string|max:500',
            'is_internal_account' => 'nullable|boolean',
        ], [
            'name.required'            => 'El nombre del cliente es obligatorio.',
            'document_number.required' => 'El número de documento es obligatorio.',
            'document_number.unique'   => 'Ya existe un cliente con ese número de documento (DNI/RUT). Verificá los datos.',
        ]);

        if (!isset($validated['balance'])) {
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
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'phone'           => 'nullable|string|max:20',
            'document_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('customers')->ignore($customer->id)->whereNull('deleted_at')
            ],
            'credit_limit'    => 'nullable|numeric|min:0',
            'is_active'       => 'sometimes|boolean',
            'default_price_tier' => 'nullable|string|in:base,wholesale,card',
            'delivery_address' => 'nullable|string|max:500',
            'is_internal_account' => 'sometimes|boolean',
        ], [
            'name.required'            => 'El nombre del cliente es obligatorio.',
            'document_number.required' => 'El número de documento es obligatorio.',
            'document_number.unique'   => 'Ya existe otro cliente con ese número de documento (DNI/RUT).',
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        if ($customer->balance > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un cliente con saldo pendiente (deudor).'
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
            ->where('status', '!=', 'voided')
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
            'payments'          => 'sometimes|array',
            'payments.*.method' => 'required_with:payments|string|in:cash,card,transfer,cheque',
            'payments.*.amount' => 'required_with:payments|numeric|gt:0',
            'payments.*.check_details' => 'nullable|array',
            'amount'            => 'required_without:payments|numeric|gt:0',
            'payment_method'    => 'required_without:payments|string|in:cash,card,transfer,cheque',
            'description'       => 'nullable|string|max:255',
            'sale_ids'          => 'nullable|array',
            'sale_ids.*'        => 'integer|exists:sales,id',
            'check_details'     => 'nullable|array|required_if:payment_method,cheque',
            'cash_shift_id'     => 'nullable|integer|exists:cash_shifts,id',
        ]);

        $payments = $request->filled('payments') ? $request->payments : [
            [
                'method' => $request->payment_method,
                'amount' => (float) $request->amount,
                'check_details' => $request->check_details
            ]
        ];

        $totalAmount = array_sum(array_column($payments, 'amount'));

        try {
            $transaction = DB::transaction(function () use ($customer, $totalAmount, $payments, $request) {
                $lockedCustomer = Customer::where('id', $customer->id)->lockForUpdate()->first();

                if ($totalAmount > $lockedCustomer->balance) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'amount' => ["El monto del abono (\${$totalAmount}) no puede superar el saldo actual de la deuda (\${$lockedCustomer->balance})."]
                    ]);
                }

                if ($request->filled('cash_shift_id')) {
                    $activeShift = CashShift::where('id', $request->cash_shift_id)->where('status', 'open')->first();
                } else {
                    $activeShift = CashShift::where('status', 'open')->latest('id')->first();
                }

                $description = $request->filled('description') ? $request->description : 'Abono en caja';
                $remainingAmount = $totalAmount;
                $processedSales = [];

                // ── Distribuir a tickets específicos o a los más antiguos pendientes ──────────────────────
                if ($request->filled('sale_ids')) {
                    $sales = Sale::whereIn('id', $request->sale_ids)
                                 ->where('customer_id', $lockedCustomer->id)
                                 ->lockForUpdate()
                                 ->orderBy('created_at', 'asc')
                                 ->get();
                } else {
                    $sales = Sale::where('customer_id', $lockedCustomer->id)
                                 ->whereIn('payment_status', ['pending', 'partial'])
                                 ->lockForUpdate()
                                 ->orderBy('created_at', 'asc')
                                 ->get();
                }

                /** @var \Illuminate\Database\Eloquent\Collection<int, Sale> $sales */
                foreach ($sales as $sale) {
                    if ($remainingAmount <= 0) break;

                    $payForThisSale = min((float)$sale->amount_due, $remainingAmount);
                    
                    if ($payForThisSale > 0) {
                        $sale->amount_due -= $payForThisSale;
                        $sale->payment_status = $sale->amount_due <= 0 ? 'paid' : 'partial';
                        $sale->save();
                        
                        $remainingAmount -= $payForThisSale;
                        $processedSales[] = $sale->id;
                    }
                }

                if (!empty($processedSales) && !$request->filled('description')) {
                    if ($request->filled('sale_ids')) {
                        $description = "Pago de Tickets: #" . implode(', #', $processedSales);
                    } else {
                        $description = "Abono Global aplicado a Tickets: #" . implode(', #', $processedSales);
                    }
                }

                // ── Siempre reducir el balance global del cliente ────────────
                $lockedCustomer->balance -= $totalAmount;
                $lockedCustomer->save();

                // ── Crear registros inmutables en el Ledger por cada método de pago ──
                foreach ($payments as $payment) {
                    $paymentAmount = (float) $payment['amount'];
                    $paymentMethod = $payment['method'];

                    $trx = CustomerTransaction::create([
                        'customer_id'            => $lockedCustomer->id,
                        'user_id'                => $request->attributes->get('authenticated_user')?->id ?? 1,
                        'cash_shift_id'          => $activeShift ? $activeShift->id : null,
                        'sale_id'                => count($processedSales) === 1 ? $processedSales[0] : null,
                        'type'                   => 'payment',
                        'payment_method'         => $paymentMethod,
                        'amount'                 => $paymentAmount,
                        'balance_after'          => $lockedCustomer->balance, // Refleja el final
                        'description'            => $description,
                    ]);

                    // ── Crear cheque si aplica ───────────────────────────────────
                    if ($paymentMethod === 'cheque' && isset($payment['check_details'])) {
                        $cd = $payment['check_details'];
                        ThirdPartyCheck::create([
                            'cash_shift_id'   => $activeShift ? $activeShift->id : null,
                            'customer_id'     => $lockedCustomer->id,
                            'sale_id'         => count($processedSales) === 1 ? $processedSales[0] : null,
                            'bank_name'       => $cd['bank_name'] ?? '',
                            'check_number'    => $cd['check_number'] ?? '',
                            'issuer_cuit'     => $cd['issuer_cuit'] ?? '',
                            'issuer_name'     => $cd['issuer_name'] ?? '',
                            'issue_date'      => $cd['issue_date'] ?? now()->toDateString(),
                            'payment_date'    => $cd['payment_date'] ?? now()->toDateString(),
                            'amount'          => $paymentAmount,
                            'status'          => 'in_wallet',
                        ]);
                    }
                }

                return $trx;
            });

            $customer->refresh();

            return response()->json([
                'message'     => 'Abono registrado exitosamente.',
                'transaction' => $transaction,
                'new_balance' => $customer->balance,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Error al procesar el pago.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
