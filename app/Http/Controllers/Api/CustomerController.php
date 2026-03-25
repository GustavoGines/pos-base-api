<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = '%' . $request->search . '%';
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
            'document_number' => 'required|string|max:50|unique:customers,document_number',
            'credit_limit'    => 'nullable|numeric|min:0',
            'balance'         => 'nullable|numeric',
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
            'document_number' => 'sometimes|required|string|max:50|unique:customers,document_number,' . $customer->id,
            'credit_limit'    => 'nullable|numeric|min:0',
            'is_active'       => 'sometimes|boolean',
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
            ->where('payment_method', 'cuenta_corriente')
            ->whereIn('payment_status', ['pending', 'partial'])
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
            'amount'      => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:255',
            'sale_id'     => 'nullable|integer|exists:sales,id',
        ]);

        $amount = (float) $request->amount;

        try {
            $transaction = DB::transaction(function () use ($customer, $amount, $request) {
                // Bloqueamos la fila del cliente para prevenir race conditions
                $lockedCustomer = Customer::where('id', $customer->id)->lockForUpdate()->first();

                if ($amount > $lockedCustomer->balance) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'amount' => ["El monto del abono (\${$amount}) no puede superar el saldo actual de la deuda (\${$lockedCustomer->balance})."]
                    ]);
                }

                $saleId = null;
                $description = $request->filled('description') ? $request->description : 'Abono en caja';

                // ── Si es un pago de ticket específico ──────────────────────
                if ($request->filled('sale_id')) {
                    $sale = Sale::lockForUpdate()->find($request->sale_id);

                    // Validar que el ticket pertenezca a este cliente
                    if ($sale->customer_id !== $lockedCustomer->id) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'sale_id' => ['Este ticket no pertenece al cliente seleccionado.']
                        ]);
                    }

                    // Validar que el monto no supere lo que debe este ticket
                    if ($amount > (float) $sale->amount_due) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'amount' => ["El monto (\${$amount}) supera el saldo de este ticket (\${$sale->amount_due})."]
                        ]);
                    }

                    // Reducir el saldo del ticket
                    $sale->amount_due -= $amount;
                    $sale->payment_status = $sale->amount_due <= 0 ? 'paid' : 'partial';
                    $sale->save();

                    $saleId = $sale->id;
                    $description = $request->filled('description')
                        ? $request->description
                        : "Pago de Ticket #{$sale->id}";
                }

                // ── Siempre reducir el balance global del cliente ────────────
                $lockedCustomer->balance -= $amount;
                $lockedCustomer->save();

                // ── Crear registro inmutable en el Ledger ────────────────────
                $trx = CustomerTransaction::create([
                    'customer_id'   => $lockedCustomer->id,
                    'user_id'       => auth()->id() ?? 1,
                    'sale_id'       => $saleId,
                    'type'          => 'payment',
                    'amount'        => $amount,
                    'balance_after' => $lockedCustomer->balance,
                    'description'   => $description,
                ]);

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
