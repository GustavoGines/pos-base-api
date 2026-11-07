<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductStockToPlatformsJob;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupplierInvoiceController extends Controller
{
    /**
     * Listado y auditoría global de comprobantes de proveedores (Facturas, Remitos, Notas de Crédito)
     * con filtros avanzados y desglose Maestro-Detalle.
     */
    public function index(Request $request)
    {
        $auditFilter = $request->query('audit_filter', 'active');

        $query = SupplierInvoice::query();
        if ($auditFilter === 'deleted') {
            $query->onlyTrashed();
        } elseif ($auditFilter === 'edited') {
            $query->withTrashed()->where('edit_count', '>', 0);
        } elseif ($auditFilter === 'all' || $auditFilter === 'with_deleted') {
            $query->withTrashed();
        }

        $relations = [
            'supplier:id,name,cuit',
            'user:id,name',
            'lastEditor:id,name',
            'deletedBy:id,name',
            'items.product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,selling_price,cost_price',
        ];
        if (Schema::hasTable('supplier_invoice_audits')) {
            $relations[] = 'audits.user:id,name';
            $relations[] = 'audits.authorizer:id,name';
        }
        $query->with($relations);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('cuit', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('items.product', function ($pq) use ($search) {
                        $pq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('internal_code', 'LIKE', "%{$search}%")
                            ->orWhere('barcode', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issue_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issue_date', '<=', $request->query('date_to'));
        }

        $invoices = $query->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(250)
            ->get()
            ->map(fn (SupplierInvoice $inv) => $this->formatInvoice($inv));

        return response()->json([
            'data' => $invoices,
        ]);
    }

    /**
     * Detalle individual de un comprobante de proveedor (incluyendo anulados y su historial de auditoría).
     */
    public function show(int $invoice)
    {
        $relations = [
            'supplier:id,name,cuit',
            'user:id,name',
            'lastEditor:id,name',
            'deletedBy:id,name',
            'items.product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,selling_price,cost_price',
        ];
        if (Schema::hasTable('supplier_invoice_audits')) {
            $relations[] = 'audits.user:id,name';
            $relations[] = 'audits.authorizer:id,name';
        }

        $inv = SupplierInvoice::withTrashed()->with($relations)->findOrFail($invoice);

        return response()->json($this->formatInvoice($inv));
    }

    public function formatInvoice(SupplierInvoice $inv): array
    {
        $hasAuditTable = Schema::hasTable('supplier_invoice_audits');
        $audits = ($hasAuditTable && $inv->relationLoaded('audits'))
            ? $inv->audits->map(fn (SupplierInvoiceAudit $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'reason' => $a->reason,
                'user_id' => $a->user_id,
                'user_name' => $a->user?->name ?? 'Sistema',
                'authorized_by_id' => $a->authorized_by_id,
                'authorizer_name' => $a->authorizer?->name,
                'before_snapshot' => $a->before_snapshot,
                'after_snapshot' => $a->after_snapshot,
                'changes_summary' => $a->changes_summary ?? [],
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all()
            : [];

        $editCount = (int) ($inv->edit_count ?? 0);

        return [
            'id' => $inv->id,
            'supplier_id' => $inv->supplier_id,
            'supplier_name' => $inv->supplier?->name ?? 'Proveedor #'.$inv->supplier_id,
            'supplier_cuit' => $inv->supplier?->cuit,
            'user_id' => $inv->user_id,
            'user_name' => $inv->user?->name ?? 'Sistema',
            'type' => $inv->type ?? 'invoice',
            'status' => $inv->status ?? 'pending',
            'invoice_number' => $inv->invoice_number,
            'description' => $inv->description,
            'receipt_file_url' => $inv->receipt_file_url,
            'amount' => (float) $inv->amount,
            'total_amount' => (float) $inv->amount,
            'tax_amount' => (float) ($inv->tax_amount ?? 0),
            'freight_amount' => (float) ($inv->freight_amount ?? 0),
            'discount_amount' => (float) ($inv->discount_amount ?? 0),
            'issue_date' => $inv->issue_date?->toIso8601String(),
            'due_date' => $inv->due_date?->format('Y-m-d'),
            'created_at' => $inv->created_at?->toIso8601String(),
            'edit_count' => $editCount,
            'is_edited' => $editCount > 0,
            'last_edited_at' => $inv->last_edited_at?->toIso8601String(),
            'last_edited_by_id' => $inv->last_edited_by_id,
            'last_edited_by_name' => $inv->lastEditor?->name,
            'is_deleted' => $inv->trashed(),
            'deleted_at' => $inv->deleted_at?->toIso8601String(),
            'deleted_by_id' => $inv->deleted_by_id,
            'deleted_by_name' => $inv->deletedBy?->name,
            'deletion_reason' => $inv->deletion_reason,
            'items_count' => $inv->items->count(),
            'items' => $inv->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name ?? 'Producto #'.$i->product_id,
                'internal_code' => $i->product?->internal_code ?? '-',
                'barcode' => $i->product?->barcode,
                'unit_type' => $i->product?->unit_type ?? 'un',
                'is_sold_by_weight' => (bool) ($i->product?->is_sold_by_weight ?? false),
                'current_cost_price' => (float) ($i->product?->cost_price ?? 0),
                'current_selling_price' => (float) ($i->product?->selling_price ?? 0),
                'quantity' => (float) $i->quantity,
                'previous_cost' => $i->previous_cost !== null ? (float) $i->previous_cost : null,
                'unit_cost' => (float) $i->unit_cost,
                'new_selling_price' => $i->new_selling_price !== null
                    ? (float) $i->new_selling_price
                    : ($i->product?->selling_price !== null ? (float) $i->product->selling_price : null),
                'subtotal' => (float) $i->subtotal,
            ])->values()->all(),
            'audits' => $audits,
        ];
    }

    public function store(Request $request, Supplier $supplier)
    {
        // CP-06: 'type' es nullable con default 'invoice' para retrocompatibilidad con v1.8.5
        $validated = $request->validate([
            'type' => 'nullable|string|in:invoice,delivery_note,credit_note',
            'status' => 'nullable|string|in:pending,partial,paid',
            'amount' => 'nullable|numeric|min:0',
            'invoice_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'receipt_file_url' => 'nullable|string',
            'tax_amount' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            // Arrays para ítems
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            // Actualización opcional de precios dictada por el usuario desde el frontend
            'items.*.new_selling_price' => 'nullable|numeric|min:0',
            'items.*.new_sale_price' => 'nullable|numeric|min:0',
            'items.*.expiration_date' => 'nullable|date',
        ]);

        $validated['items'] = $this->normalizeValidatedItems($validated['items'] ?? []);

        $type = $validated['type'] ?? 'invoice';
        $isCreditNote = $type === 'credit_note';

        if ($request->filled('invoice_number')) {
            $exists = SupplierInvoice::where('supplier_id', $supplier->id)
                ->where('invoice_number', $request->invoice_number)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'El número de comprobante ya existe para este proveedor.',
                ], 422);
            }
        }

        // CP-10: Validar que el nuevo precio de venta no sea inferior al costo unitario en compras (sólo cuando unit_cost > 0)
        if (! $isCreditNote && ! empty($validated['items'])) {
            foreach ($validated['items'] as $idx => $itemData) {
                $unitCost = (float) ($itemData['unit_cost'] ?? 0);
                $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                if ($unitCost > 0 && $newPrice !== null && (float) $newPrice > 0 && (float) $newPrice < $unitCost) {
                    return response()->json([
                        'message' => 'El nuevo precio de venta no puede ser menor al costo unitario de compra.',
                        'errors' => [
                            "items.{$idx}.new_selling_price" => ['El nuevo precio de venta no puede ser menor al costo unitario de compra.'],
                        ],
                    ], 422);
                }
            }
        }

        try {
            $affectedProductIds = [];
            $createdInvoice = null;

            DB::transaction(function () use ($validated, $supplier, $request, $type, $isCreditNote, &$affectedProductIds, &$createdInvoice) {
                $user = $request->user() ?? $request->attributes->get('authenticated_user');
                $authorizedAdminId = $request->attributes->get('authorized_by_admin_id') ?? $request->input('authorized_by_admin_id');

                // CP-01: Bloqueo pesimista del proveedor al inicio de la transacción
                $lockedSupplier = Supplier::where('id', $supplier->id)->lockForUpdate()->firstOrFail();

                // BK-CONC-04: Re-evaluar unicidad de invoice_number bajo lock del proveedor para prevenir duplicados concurrentes
                if (! empty($validated['invoice_number'])) {
                    $duplicate = SupplierInvoice::where('supplier_id', $lockedSupplier->id)
                        ->where('invoice_number', $validated['invoice_number'])
                        ->exists();
                    if ($duplicate) {
                        throw new \InvalidArgumentException('El número de comprobante ya existe para este proveedor.');
                    }
                }

                $tax = $validated['tax_amount'] ?? 0;
                $freight = $validated['freight_amount'] ?? 0;
                $discount = $validated['discount_amount'] ?? 0;

                $subtotal = 0;
                if (! empty($validated['items'])) {
                    $subtotal = array_sum(array_column($validated['items'], 'subtotal'));
                } else {
                    $subtotal = $validated['amount'] ?? 0;
                }

                $totalAmount = $subtotal + $freight + $tax - $discount;

                // 1. Crear la cabecera de la factura
                $invoice = $lockedSupplier->invoices()->create([
                    'type' => $type,
                    'status' => $validated['status'] ?? 'pending',
                    'amount' => $totalAmount,
                    'tax_amount' => $tax,
                    'freight_amount' => $freight,
                    'discount_amount' => $discount,
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'receipt_file_url' => $validated['receipt_file_url'] ?? null,
                    'issue_date' => $validated['issue_date'] ?? now(),
                    'due_date' => $validated['due_date'] ?? null,
                    'user_id' => $user->id ?? null,
                ]);

                // 2. Procesar ítems si existen con bloqueo pesimista ordenado (CP-01 / CP-08)
                if (! empty($validated['items'])) {
                    $this->applyInvoiceItemsAndStock(
                        invoice: $invoice,
                        items: $validated['items'],
                        isCreditNote: $isCreditNote,
                        userId: $user->id ?? null,
                        affectedProductIds: $affectedProductIds,
                        notePrefix: $isCreditNote ? 'Devolución: Nota de Crédito ' : 'Ingreso por Compra: Factura '
                    );
                }

                // 3. Aumentar o disminuir deuda global del proveedor
                if ($isCreditNote) {
                    $lockedSupplier->decrement('balance', $totalAmount);
                } else {
                    $lockedSupplier->increment('balance', $totalAmount);
                }

                // 4. Registrar auditoría de creación inicial
                if (Schema::hasTable('supplier_invoice_audits')) {
                    $invoice->load('items.product');
                    SupplierInvoiceAudit::create([
                        'supplier_invoice_id' => $invoice->id,
                        'user_id' => $user->id ?? null,
                        'authorized_by_id' => $authorizedAdminId ? (int) $authorizedAdminId : null,
                        'action' => 'created',
                        'reason' => 'Alta inicial de comprobante',
                        'before_snapshot' => null,
                        'after_snapshot' => $this->buildInvoiceSnapshot($invoice),
                        'changes_summary' => [],
                    ]);
                }

                $createdInvoice = $invoice;
            });

            // CP-02: Sincronizar stock con plataformas e-commerce (MercadoLibre / Tiendanube)
            if (! empty($affectedProductIds)) {
                SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique($affectedProductIds)));
            }

            return response()->json([
                'id' => $createdInvoice?->id,
                'message' => 'Factura/Remito cargado exitosamente',
                'supplier' => $supplier->fresh(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar la factura: '.$e->getMessage()], 500);
        }
    }

    /**
     * Edición integral de un comprobante de proveedor (cabecera, montos e ítems)
     * revirtiendo atómicamente el impacto contable/stock previo y aplicando el nuevo con trazabilidad de auditoría.
     */
    public function update(Request $request, int $invoice)
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:invoice,delivery_note,credit_note',
            'status' => 'nullable|string|in:pending,partial,paid',
            'amount' => 'nullable|numeric|min:0',
            'invoice_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'receipt_file_url' => 'nullable|string',
            'tax_amount' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'edit_reason' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.new_selling_price' => 'nullable|numeric|min:0',
            'items.*.new_sale_price' => 'nullable|numeric|min:0',
            'items.*.expiration_date' => 'nullable|date',
        ]);

        $validated['items'] = $this->normalizeValidatedItems($validated['items'] ?? []);

        $existingInvoice = SupplierInvoice::withTrashed()->findOrFail($invoice);
        if ($existingInvoice->trashed()) {
            return response()->json([
                'message' => 'No se puede editar un comprobante anulado. Restáurelo primero desde la Papelera o el Historial.',
            ], 422);
        }

        $newType = $validated['type'] ?? $existingInvoice->type ?? 'invoice';
        $isNewCreditNote = $newType === 'credit_note';

        if (! $isNewCreditNote && ! empty($validated['items'])) {
            foreach ($validated['items'] as $idx => $itemData) {
                $unitCost = (float) ($itemData['unit_cost'] ?? 0);
                $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                if ($unitCost > 0 && $newPrice !== null && (float) $newPrice > 0 && (float) $newPrice < $unitCost) {
                    return response()->json([
                        'message' => 'El nuevo precio de venta no puede ser menor al costo unitario de compra.',
                        'errors' => [
                            "items.{$idx}.new_selling_price" => ['El nuevo precio de venta no puede ser menor al costo unitario de compra.'],
                        ],
                    ], 422);
                }
            }
        }

        try {
            $affectedProductIds = [];
            $updatedInvoice = null;

            DB::transaction(function () use ($validated, $invoice, $request, $newType, $isNewCreditNote, &$affectedProductIds, &$updatedInvoice) {
                $user = $request->user() ?? $request->attributes->get('authenticated_user');
                $authorizedAdminId = $request->attributes->get('authorized_by_admin_id') ?? $request->input('authorized_by_admin_id');

                $lockedInvoice = SupplierInvoice::with(['items.product', 'supplier', 'user'])
                    ->where('id', $invoice)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedSupplier = Supplier::withTrashed()
                    ->where('id', $lockedInvoice->supplier_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! empty($validated['invoice_number'])) {
                    $duplicate = SupplierInvoice::where('supplier_id', $lockedSupplier->id)
                        ->where('invoice_number', $validated['invoice_number'])
                        ->where('id', '!=', $lockedInvoice->id)
                        ->exists();
                    if ($duplicate) {
                        throw new \InvalidArgumentException('El número de comprobante ya existe para este proveedor.');
                    }
                }

                $beforeSnapshot = $this->buildInvoiceSnapshot($lockedInvoice);

                // 1. Revertir el impacto de stock y deuda del comprobante anterior
                $wasCreditNote = ($lockedInvoice->type ?? 'invoice') === 'credit_note';
                $oldAmount = (float) $lockedInvoice->amount;

                if ($wasCreditNote) {
                    $lockedSupplier->increment('balance', $oldAmount);
                } else {
                    $lockedSupplier->decrement('balance', $oldAmount);
                }

                $this->revertInvoiceItemsAndStock(
                    invoice: $lockedInvoice,
                    wasCreditNote: $wasCreditNote,
                    userId: $user->id ?? null,
                    affectedProductIds: $affectedProductIds,
                    noteLabel: 'Reversión por edición de comprobante #'.($lockedInvoice->invoice_number ?? $lockedInvoice->id)
                );

                $lockedInvoice->items()->delete();

                // 2. Calcular nuevos totales y actualizar cabecera
                $tax = isset($validated['tax_amount']) ? (float) $validated['tax_amount'] : (float) ($lockedInvoice->tax_amount ?? 0);
                $freight = isset($validated['freight_amount']) ? (float) $validated['freight_amount'] : (float) ($lockedInvoice->freight_amount ?? 0);
                $discount = isset($validated['discount_amount']) ? (float) $validated['discount_amount'] : (float) ($lockedInvoice->discount_amount ?? 0);

                $subtotal = 0;
                if (! empty($validated['items'])) {
                    $subtotal = array_sum(array_column($validated['items'], 'subtotal'));
                } else {
                    $subtotal = isset($validated['amount']) ? (float) $validated['amount'] : 0.0;
                }

                $newTotalAmount = $subtotal + $freight + $tax - $discount;

                $updatePayload = [
                    'type' => $newType,
                    'status' => $validated['status'] ?? $lockedInvoice->status ?? 'pending',
                    'amount' => $newTotalAmount,
                    'tax_amount' => $tax,
                    'freight_amount' => $freight,
                    'discount_amount' => $discount,
                    'invoice_number' => array_key_exists('invoice_number', $validated) ? $validated['invoice_number'] : $lockedInvoice->invoice_number,
                    'description' => array_key_exists('description', $validated) ? $validated['description'] : $lockedInvoice->description,
                    'receipt_file_url' => array_key_exists('receipt_file_url', $validated) ? $validated['receipt_file_url'] : $lockedInvoice->receipt_file_url,
                    'issue_date' => $validated['issue_date'] ?? $lockedInvoice->issue_date ?? now(),
                    'due_date' => array_key_exists('due_date', $validated) ? $validated['due_date'] : $lockedInvoice->due_date,
                ];

                if (Schema::hasColumn('supplier_invoices', 'edit_count')) {
                    $updatePayload['edit_count'] = ((int) ($lockedInvoice->edit_count ?? 0)) + 1;
                    $updatePayload['last_edited_at'] = now();
                    $updatePayload['last_edited_by_id'] = $user->id ?? null;
                }

                $lockedInvoice->update($updatePayload);

                // 3. Aplicar los nuevos ítems y su impacto en stock/costos
                if (! empty($validated['items'])) {
                    $this->applyInvoiceItemsAndStock(
                        invoice: $lockedInvoice,
                        items: $validated['items'],
                        isCreditNote: $isNewCreditNote,
                        userId: $user->id ?? null,
                        affectedProductIds: $affectedProductIds,
                        notePrefix: $isNewCreditNote ? 'Edición Nota de Crédito ' : 'Edición Comprobante Compra '
                    );
                }

                // 4. Aplicar el nuevo saldo al proveedor
                if ($isNewCreditNote) {
                    $lockedSupplier->decrement('balance', $newTotalAmount);
                } else {
                    $lockedSupplier->increment('balance', $newTotalAmount);
                }

                // 5. Guardar auditoría Antes / Después
                $lockedInvoice->load('items.product');
                $afterSnapshot = $this->buildInvoiceSnapshot($lockedInvoice);
                $changesSummary = $this->computeChangesSummary($beforeSnapshot, $afterSnapshot);

                if (Schema::hasTable('supplier_invoice_audits')) {
                    SupplierInvoiceAudit::create([
                        'supplier_invoice_id' => $lockedInvoice->id,
                        'user_id' => $user->id ?? null,
                        'authorized_by_id' => $authorizedAdminId ? (int) $authorizedAdminId : null,
                        'action' => 'updated',
                        'reason' => ! empty($validated['edit_reason'])
                            ? trim((string) $validated['edit_reason'])
                            : 'Modificación de comprobante',
                        'before_snapshot' => $beforeSnapshot,
                        'after_snapshot' => $afterSnapshot,
                        'changes_summary' => $changesSummary,
                    ]);
                }

                $lockedInvoice->load([
                    'supplier:id,name,cuit',
                    'user:id,name',
                    'lastEditor:id,name',
                    'deletedBy:id,name',
                    'items.product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,selling_price,cost_price',
                    'audits.user:id,name',
                    'audits.authorizer:id,name',
                ]);
                $updatedInvoice = $lockedInvoice;
            });

            if (! empty($affectedProductIds)) {
                SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique($affectedProductIds)));
            }

            return response()->json([
                'message' => 'Comprobante actualizado correctamente. Stock, costos y deuda recalculados.',
                'data' => $updatedInvoice ? $this->formatInvoice($updatedInvoice) : null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar el comprobante: '.$e->getMessage()], 500);
        }
    }

    /**
     * Anula (baja lógica / Papelera) un comprobante de proveedor, revirtiendo su impacto
     * en la deuda del proveedor y en el stock de los artículos involucrados.
     */
    public function destroy(Request $request, int $invoice)
    {
        $reason = trim((string) $request->input('reason', 'Anulación de comprobante'));
        if ($reason === '') {
            $reason = 'Anulación de comprobante';
        }

        try {
            $affectedProductIds = [];

            DB::transaction(function () use ($invoice, $request, $reason, &$affectedProductIds) {
                $user = $request->user() ?? $request->attributes->get('authenticated_user');
                $authorizedAdminId = $request->attributes->get('authorized_by_admin_id') ?? $request->input('authorized_by_admin_id');

                $lockedInvoice = SupplierInvoice::with(['items.product', 'supplier'])
                    ->where('id', $invoice)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedSupplier = Supplier::withTrashed()
                    ->where('id', $lockedInvoice->supplier_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $beforeSnapshot = $this->buildInvoiceSnapshot($lockedInvoice);
                $wasCreditNote = ($lockedInvoice->type ?? 'invoice') === 'credit_note';
                $oldAmount = (float) $lockedInvoice->amount;

                // 1. Revertir deuda en el proveedor
                if ($wasCreditNote) {
                    $lockedSupplier->increment('balance', $oldAmount);
                } else {
                    $lockedSupplier->decrement('balance', $oldAmount);
                }

                // 2. Revertir impacto de stock en productos y combos
                $this->revertInvoiceItemsAndStock(
                    invoice: $lockedInvoice,
                    wasCreditNote: $wasCreditNote,
                    userId: $user->id ?? null,
                    affectedProductIds: $affectedProductIds,
                    noteLabel: 'Anulación Comprobante Proveedor #'.($lockedInvoice->invoice_number ?? $lockedInvoice->id)." ({$reason})"
                );

                // 3. Marcar metadatos de anulación y ejecutar SoftDelete
                if (Schema::hasColumn('supplier_invoices', 'deleted_by_id')) {
                    $lockedInvoice->deleted_by_id = $user->id ?? null;
                    $lockedInvoice->deletion_reason = $reason;
                    $lockedInvoice->save();
                }

                $lockedInvoice->delete();

                // 4. Registrar evento en tabla de auditoría
                if (Schema::hasTable('supplier_invoice_audits')) {
                    SupplierInvoiceAudit::create([
                        'supplier_invoice_id' => $lockedInvoice->id,
                        'user_id' => $user->id ?? null,
                        'authorized_by_id' => $authorizedAdminId ? (int) $authorizedAdminId : null,
                        'action' => 'deleted',
                        'reason' => $reason,
                        'before_snapshot' => $beforeSnapshot,
                        'after_snapshot' => null,
                        'changes_summary' => [
                            [
                                'field' => 'Estado',
                                'old' => 'Vigente ($'.number_format($oldAmount, 2, ',', '.').')',
                                'new' => 'Anulado / En Papelera',
                            ],
                        ],
                    ]);
                }
            });

            if (! empty($affectedProductIds)) {
                SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique($affectedProductIds)));
            }

            return response()->json([
                'message' => 'Comprobante anulado correctamente. Se revirtió la deuda del proveedor y el stock ingresado.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al anular el comprobante: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restaura un comprobante anulado (desde Papelera o desde el Historial de Comprobantes),
     * volviendo a aplicar su impacto en la deuda del proveedor y en el stock de sus artículos.
     */
    public function restore(Request $request, int $invoice)
    {
        try {
            $restored = $this->restoreTrashedInvoice($request, $invoice);

            return response()->json([
                'message' => 'Comprobante restaurado exitosamente. Stock y saldo del proveedor reaplicados.',
                'data' => $this->formatInvoice($restored),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al restaurar el comprobante: '.$e->getMessage(),
            ], 500);
        }
    }

    public function restoreTrashedInvoice(Request $request, int $invoiceId): SupplierInvoice
    {
        $affectedProductIds = [];
        $restoredInvoice = null;

        DB::transaction(function () use ($invoiceId, $request, &$affectedProductIds, &$restoredInvoice) {
            $user = $request->user() ?? $request->attributes->get('authenticated_user');
            $authorizedAdminId = $request->attributes->get('authorized_by_admin_id') ?? $request->input('authorized_by_admin_id');

            $lockedInvoice = SupplierInvoice::onlyTrashed()
                ->with(['items.product', 'supplier'])
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedSupplier = Supplier::withTrashed()
                ->where('id', $lockedInvoice->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! empty($lockedInvoice->invoice_number)) {
                $duplicate = SupplierInvoice::where('supplier_id', $lockedSupplier->id)
                    ->where('invoice_number', $lockedInvoice->invoice_number)
                    ->where('id', '!=', $lockedInvoice->id)
                    ->exists();
                if ($duplicate) {
                    throw new \InvalidArgumentException(
                        'No se puede restaurar: ya existe otro comprobante vigente con el número '.$lockedInvoice->invoice_number.' para este proveedor.'
                    );
                }
            }

            $isCreditNote = ($lockedInvoice->type ?? 'invoice') === 'credit_note';
            $totalAmount = (float) $lockedInvoice->amount;

            $lockedInvoice->restore();
            if (Schema::hasColumn('supplier_invoices', 'deleted_by_id')) {
                $lockedInvoice->deleted_by_id = null;
                $lockedInvoice->deletion_reason = null;
                $lockedInvoice->save();
            }

            // Reaplicar stock de los ítems existentes sin duplicar filas en supplier_invoice_items
            $existingItems = $lockedInvoice->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => (float) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'subtotal' => (float) $item->subtotal,
                'new_selling_price' => $item->new_selling_price !== null ? (float) $item->new_selling_price : null,
            ])->values()->all();

            if (! empty($existingItems)) {
                $this->applyInvoiceItemsAndStock(
                    invoice: $lockedInvoice,
                    items: $existingItems,
                    isCreditNote: $isCreditNote,
                    userId: $user->id ?? null,
                    affectedProductIds: $affectedProductIds,
                    notePrefix: 'Restauración Comprobante Proveedor ',
                    createInvoiceItemRows: false
                );
            }

            if ($isCreditNote) {
                $lockedSupplier->decrement('balance', $totalAmount);
            } else {
                $lockedSupplier->increment('balance', $totalAmount);
            }

            if (Schema::hasTable('supplier_invoice_audits')) {
                $lockedInvoice->load('items.product');
                SupplierInvoiceAudit::create([
                    'supplier_invoice_id' => $lockedInvoice->id,
                    'user_id' => $user->id ?? null,
                    'authorized_by_id' => $authorizedAdminId ? (int) $authorizedAdminId : null,
                    'action' => 'restored',
                    'reason' => 'Restaurado desde Papelera / Historial',
                    'before_snapshot' => null,
                    'after_snapshot' => $this->buildInvoiceSnapshot($lockedInvoice),
                    'changes_summary' => [
                        [
                            'field' => 'Estado',
                            'old' => 'Anulado / En Papelera',
                            'new' => 'Vigente ($'.number_format($totalAmount, 2, ',', '.').')',
                        ],
                    ],
                ]);
            }

            $lockedInvoice->load([
                'supplier:id,name,cuit',
                'user:id,name',
                'lastEditor:id,name',
                'deletedBy:id,name',
                'items.product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,selling_price,cost_price',
                'audits.user:id,name',
                'audits.authorizer:id,name',
            ]);
            $restoredInvoice = $lockedInvoice;
        });

        if (! empty($affectedProductIds)) {
            SyncProductStockToPlatformsJob::dispatchForProducts(array_values(array_unique($affectedProductIds)));
        }

        return $restoredInvoice;
    }

    private function normalizeValidatedItems(array $items): array
    {
        foreach ($items as $idx => $itemData) {
            $qty = (float) ($itemData['quantity'] ?? 1);
            $unitCost = isset($itemData['unit_cost']) && is_numeric($itemData['unit_cost'])
                ? (float) $itemData['unit_cost']
                : 0.0;
            $items[$idx]['unit_cost'] = $unitCost;
            if (! isset($itemData['subtotal']) || $itemData['subtotal'] === null) {
                $items[$idx]['subtotal'] = round($qty * $unitCost, 2);
            }
        }

        return $items;
    }

    private function lockProductsGraph(array $requestedIds)
    {
        if (empty($requestedIds)) {
            return [collect(), []];
        }

        $childIds = DB::table('product_combos')
            ->whereIn('parent_product_id', $requestedIds)
            ->pluck('child_product_id')
            ->toArray();

        $allIngredientIds = array_values(array_unique(array_merge($requestedIds, $childIds)));

        $parentComboIds = DB::table('product_combos')
            ->whereIn('child_product_id', $allIngredientIds)
            ->pluck('parent_product_id')
            ->toArray();

        $allLockIds = array_values(array_unique(array_merge($allIngredientIds, $parentComboIds)));

        $lockedProducts = Product::withTrashed()
            ->whereIn('id', $allLockIds)
            ->with('children')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$lockedProducts, $parentComboIds];
    }

    private function applyInvoiceItemsAndStock(
        SupplierInvoice $invoice,
        array $items,
        bool $isCreditNote,
        ?int $userId,
        array &$affectedProductIds,
        string $notePrefix,
        bool $createInvoiceItemRows = true
    ): void {
        $requestedIds = array_values(array_unique(array_column($items, 'product_id')));
        [$lockedProducts, $parentComboIds] = $this->lockProductsGraph($requestedIds);

        foreach ($items as $itemData) {
            $product = $lockedProducts->get($itemData['product_id']);
            $previousCost = $product ? (float) $product->cost_price : null;
            $rawNewPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
            $effectiveSellingPrice = ($rawNewPrice !== null && (float) $rawNewPrice > 0)
                ? (float) $rawNewPrice
                : ($product ? (float) $product->selling_price : null);

            if ($createInvoiceItemRows) {
                $invoice->items()->create([
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'previous_cost' => $previousCost,
                    'unit_cost' => $itemData['unit_cost'],
                    'new_selling_price' => $effectiveSellingPrice,
                    'subtotal' => $itemData['subtotal'],
                ]);
            }

            if (! $product) {
                continue;
            }

            $absQty = abs((float) $itemData['quantity']);
            $quantityModifier = $isCreditNote ? -$absQty : $absQty;
            $invNumLabel = $invoice->invoice_number ?? 'S/N';

            if ($product->is_combo && $product->children->isNotEmpty()) {
                foreach ($product->children as $child) {
                    $lockedChild = $lockedProducts->get($child->id) ?? $child;
                    $childDelta = $quantityModifier * (float) $child->pivot->quantity;

                    StockMovement::create([
                        'product_id' => $lockedChild->id,
                        'user_id' => $userId,
                        'supplier_invoice_id' => $invoice->id,
                        'type' => $isCreditNote ? 'out' : 'purchase',
                        'quantity' => $childDelta,
                        'notes' => "{$notePrefix}(Hijo Combo) #{$invNumLabel}",
                    ]);

                    $lockedChild->stock = (float) $lockedChild->stock + $childDelta;
                    $lockedChild->save();
                    $affectedProductIds[] = $lockedChild->id;
                }

                if (! $isCreditNote) {
                    $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                    if ($newPrice !== null && (float) $newPrice > 0) {
                        $product->selling_price = (float) $newPrice;
                    }
                }
                $product->load('children');
                $product->cost_price = $product->calculateComboCostPrice();
                $product->stock = $product->calculateComboAvailableStock();
                $product->save();
                $affectedProductIds[] = $product->id;
            } else {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'supplier_invoice_id' => $invoice->id,
                    'type' => $isCreditNote ? 'out' : 'purchase',
                    'quantity' => $quantityModifier,
                    'notes' => "{$notePrefix}#{$invNumLabel}",
                ]);

                $product->stock = (float) $product->stock + $quantityModifier;

                if (! $isCreditNote) {
                    $unitCost = (float) ($itemData['unit_cost'] ?? 0);
                    if ($unitCost > 0) {
                        $product->cost_price = $unitCost;
                    }
                    $newPrice = $itemData['new_selling_price'] ?? $itemData['new_sale_price'] ?? null;
                    if ($newPrice !== null && (float) $newPrice > 0) {
                        $product->selling_price = (float) $newPrice;
                    }
                    if (! empty($itemData['expiration_date'])) {
                        $product->expiration_date = $itemData['expiration_date'];
                        $product->expiry_alert_snoozed_until = null;
                        $product->expiry_alert_last_stage = null;
                    } elseif (! empty($product->vencimiento_dias) && $product->vencimiento_dias > 0) {
                        $product->expiration_date = now()->addDays((int) $product->vencimiento_dias)->format('Y-m-d');
                        $product->expiry_alert_snoozed_until = null;
                        $product->expiry_alert_last_stage = null;
                    }
                }

                $product->save();
                $affectedProductIds[] = $product->id;
            }
        }

        foreach ($parentComboIds as $comboId) {
            $combo = $lockedProducts->get($comboId);
            if ($combo && $combo->is_combo) {
                $combo->load('children');
                $combo->cost_price = $combo->calculateComboCostPrice();
                $combo->stock = $combo->calculateComboAvailableStock();
                $combo->save();
                $affectedProductIds[] = $combo->id;
            }
        }
    }

    private function revertInvoiceItemsAndStock(
        SupplierInvoice $invoice,
        bool $wasCreditNote,
        ?int $userId,
        array &$affectedProductIds,
        string $noteLabel
    ): void {
        if ($invoice->items->isEmpty()) {
            return;
        }

        $requestedIds = $invoice->items->pluck('product_id')->filter()->unique()->values()->all();
        [$lockedProducts, $parentComboIds] = $this->lockProductsGraph($requestedIds);

        foreach ($invoice->items as $oldItem) {
            $product = $lockedProducts->get($oldItem->product_id);
            if (! $product) {
                continue;
            }

            $absQty = abs((float) $oldItem->quantity);
            // Al revertir: si era compra normal, descontamos lo que había sumado; si era nota de crédito, devolvemos lo que había restado
            $reverseDelta = $wasCreditNote ? $absQty : -$absQty;

            if ($product->is_combo && $product->children->isNotEmpty()) {
                foreach ($product->children as $child) {
                    $lockedChild = $lockedProducts->get($child->id) ?? $child;
                    $childDelta = $reverseDelta * (float) $child->pivot->quantity;

                    StockMovement::create([
                        'product_id' => $lockedChild->id,
                        'user_id' => $userId,
                        'supplier_invoice_id' => $invoice->id,
                        'type' => $childDelta >= 0 ? 'in' : 'out',
                        'quantity' => $childDelta,
                        'notes' => "{$noteLabel} (Hijo Combo)",
                    ]);

                    $lockedChild->stock = (float) $lockedChild->stock + $childDelta;
                    $lockedChild->save();
                    $affectedProductIds[] = $lockedChild->id;
                }

                $product->load('children');
                $product->cost_price = $product->calculateComboCostPrice();
                $product->stock = $product->calculateComboAvailableStock();
                $product->save();
                $affectedProductIds[] = $product->id;
            } else {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'supplier_invoice_id' => $invoice->id,
                    'type' => $reverseDelta >= 0 ? 'in' : 'out',
                    'quantity' => $reverseDelta,
                    'notes' => $noteLabel,
                ]);

                $product->stock = (float) $product->stock + $reverseDelta;

                // Si el comprobante había pisado el costo del artículo y tenemos previous_cost registrado, restaurarlo
                if (! $wasCreditNote && $oldItem->previous_cost !== null && (float) $oldItem->previous_cost > 0) {
                    $product->cost_price = (float) $oldItem->previous_cost;
                }

                $product->save();
                $affectedProductIds[] = $product->id;
            }
        }

        foreach ($parentComboIds as $comboId) {
            $combo = $lockedProducts->get($comboId);
            if ($combo && $combo->is_combo) {
                $combo->load('children');
                $combo->cost_price = $combo->calculateComboCostPrice();
                $combo->stock = $combo->calculateComboAvailableStock();
                $combo->save();
                $affectedProductIds[] = $combo->id;
            }
        }
    }

    private function buildInvoiceSnapshot(SupplierInvoice $inv): array
    {
        return [
            'type' => $inv->type ?? 'invoice',
            'invoice_number' => $inv->invoice_number,
            'description' => $inv->description,
            'issue_date' => $inv->issue_date?->format('Y-m-d'),
            'due_date' => $inv->due_date?->format('Y-m-d'),
            'amount' => round((float) $inv->amount, 2),
            'tax_amount' => round((float) ($inv->tax_amount ?? 0), 2),
            'freight_amount' => round((float) ($inv->freight_amount ?? 0), 2),
            'discount_amount' => round((float) ($inv->discount_amount ?? 0), 2),
            'items' => $inv->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name ?? 'Producto #'.$i->product_id,
                'quantity' => round((float) $i->quantity, 3),
                'unit_cost' => round((float) $i->unit_cost, 2),
                'subtotal' => round((float) $i->subtotal, 2),
            ])->values()->all(),
        ];
    }

    private function computeChangesSummary(array $before, array $after): array
    {
        $changes = [];

        $typeLabels = [
            'invoice' => 'Factura de Compra',
            'delivery_note' => 'Remito de Mercadería',
            'credit_note' => 'Nota de Crédito',
        ];

        if (($before['type'] ?? '') !== ($after['type'] ?? '')) {
            $changes[] = [
                'field' => 'Tipo Comprobante',
                'old' => $typeLabels[$before['type'] ?? 'invoice'] ?? $before['type'],
                'new' => $typeLabels[$after['type'] ?? 'invoice'] ?? $after['type'],
            ];
        }

        if (($before['invoice_number'] ?? '') !== ($after['invoice_number'] ?? '')) {
            $changes[] = [
                'field' => 'Nº Comprobante',
                'old' => $before['invoice_number'] ?: 'S/N',
                'new' => $after['invoice_number'] ?: 'S/N',
            ];
        }

        if (($before['issue_date'] ?? '') !== ($after['issue_date'] ?? '')) {
            $changes[] = [
                'field' => 'Fecha Emisión',
                'old' => $before['issue_date'] ?: '-',
                'new' => $after['issue_date'] ?: '-',
            ];
        }

        if (($before['description'] ?? '') !== ($after['description'] ?? '')) {
            $changes[] = [
                'field' => 'Concepto',
                'old' => $before['description'] ?: '-',
                'new' => $after['description'] ?: '-',
            ];
        }

        foreach ([
            'freight_amount' => 'Flete',
            'tax_amount' => 'Impuestos',
            'discount_amount' => 'Descuento',
            'amount' => 'Total Comprobante',
        ] as $key => $label) {
            $oldVal = (float) ($before[$key] ?? 0);
            $newVal = (float) ($after[$key] ?? 0);
            if (abs($oldVal - $newVal) >= 0.01) {
                $changes[] = [
                    'field' => $label,
                    'old' => '$'.number_format($oldVal, 2, ',', '.'),
                    'new' => '$'.number_format($newVal, 2, ',', '.'),
                ];
            }
        }

        $oldItemsByProd = collect($before['items'] ?? [])->keyBy('product_id');
        $newItemsByProd = collect($after['items'] ?? [])->keyBy('product_id');
        $allProdIds = $oldItemsByProd->keys()->merge($newItemsByProd->keys())->unique();

        foreach ($allProdIds as $prodId) {
            $oldIt = $oldItemsByProd->get($prodId);
            $newIt = $newItemsByProd->get($prodId);

            if ($oldIt && ! $newIt) {
                $changes[] = [
                    'field' => 'Artículo Quitado: '.$oldIt['product_name'],
                    'old' => $oldIt['quantity'].' x $'.number_format((float) $oldIt['unit_cost'], 2, ',', '.'),
                    'new' => 'Eliminado del comprobante',
                ];
            } elseif (! $oldIt && $newIt) {
                $changes[] = [
                    'field' => 'Artículo Agregado: '.$newIt['product_name'],
                    'old' => 'No estaba',
                    'new' => $newIt['quantity'].' x $'.number_format((float) $newIt['unit_cost'], 2, ',', '.'),
                ];
            } elseif ($oldIt && $newIt) {
                $qtyChanged = abs((float) $oldIt['quantity'] - (float) $newIt['quantity']) >= 0.001;
                $costChanged = abs((float) $oldIt['unit_cost'] - (float) $newIt['unit_cost']) >= 0.01;
                if ($qtyChanged || $costChanged) {
                    $changes[] = [
                        'field' => 'Artículo: '.$newIt['product_name'],
                        'old' => $oldIt['quantity'].' u. a $'.number_format((float) $oldIt['unit_cost'], 2, ',', '.'),
                        'new' => $newIt['quantity'].' u. a $'.number_format((float) $newIt['unit_cost'], 2, ',', '.'),
                    ];
                }
            }
        }

        return $changes;
    }

    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $safeName = Str::random(40).($extension ? '.'.strtolower($extension) : '');
            $path = $file->storeAs('supplier_invoices', $safeName, 'public');

            // CP-05: Devolver ambas claves ('file_url' y 'path') para compatibilidad total con v1.8.5 y v2.0.3
            return response()->json([
                'message' => 'Archivo subido correctamente',
                'file_url' => '/storage/'.$path,
                'path' => '/storage/'.$path,
            ]);
        }

        return response()->json(['message' => 'No se recibió ningún archivo.'], 400);
    }
}
