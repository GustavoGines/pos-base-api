<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSupplierInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->input('type') === 'delivery_note') {
            $merge['affects_vat_book'] = false;
        }

        if ($this->has('voucher_letter') && $this->voucher_letter !== null) {
            $letter = strtoupper(trim((string) $this->voucher_letter));
            $merge['voucher_letter'] = $letter;

            // If invoice_number is empty, generate from letter, point of sale and voucher number
            if (! $this->filled('invoice_number') && $this->filled('point_of_sale') && $this->filled('voucher_number')) {
                $merge['invoice_number'] = sprintf(
                    '%s-%05d-%08d',
                    $letter,
                    (int) $this->point_of_sale,
                    (int) $this->voucher_number
                );
            }

            // B, C, X comprobantes do not discriminate IVA
            if (in_array($letter, ['B', 'C', 'X'])) {
                $merge['iva_21'] = 0.0;
                $merge['iva_105'] = 0.0;
                $merge['iva_27'] = 0.0;
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
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

            // Fiscal fields
            'voucher_letter' => 'nullable|string|in:A,B,C,M,X,a,b,c,m,x',
            'point_of_sale' => 'nullable|integer|min:1|max:99999',
            'voucher_number' => 'nullable|integer|min:1|max:99999999',
            'net_21' => 'nullable|numeric|min:0',
            'iva_21' => 'nullable|numeric|min:0',
            'net_105' => 'nullable|numeric|min:0',
            'iva_105' => 'nullable|numeric|min:0',
            'net_27' => 'nullable|numeric|min:0',
            'iva_27' => 'nullable|numeric|min:0',
            'non_taxed_amount' => 'nullable|numeric|min:0',
            'exempt_amount' => 'nullable|numeric|min:0',
            'iva_perception' => 'nullable|numeric|min:0',
            'iibb_perception' => 'nullable|numeric|min:0',
            'internal_taxes' => 'nullable|numeric|min:0',
            'affects_vat_book' => 'nullable|boolean',

            // Items
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.new_selling_price' => 'nullable|numeric|min:0',
            'items.*.new_sale_price' => 'nullable|numeric|min:0',
            'items.*.expiration_date' => 'nullable|date',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type', 'invoice');
            $affectsVatBook = $this->has('affects_vat_book')
                ? filter_var($this->input('affects_vat_book'), FILTER_VALIDATE_BOOLEAN)
                : true;

            if ($type === 'delivery_note' || ! $affectsVatBook) {
                return;
            }

            $letter = $this->has('voucher_letter') && $this->voucher_letter !== null
                ? strtoupper(trim((string) $this->voucher_letter))
                : null;

            if ($letter && in_array($letter, ['A', 'M'])) {
                $net21 = (float) $this->input('net_21', 0);
                $iva21 = (float) $this->input('iva_21', 0);
                $net105 = (float) $this->input('net_105', 0);
                $iva105 = (float) $this->input('iva_105', 0);
                $net27 = (float) $this->input('net_27', 0);
                $iva27 = (float) $this->input('iva_27', 0);
                $nonTaxed = (float) $this->input('non_taxed_amount', 0);
                $exempt = (float) $this->input('exempt_amount', 0);
                $ivaPerception = (float) $this->input('iva_perception', 0);
                $iibbPerception = (float) $this->input('iibb_perception', 0);
                $internalTaxes = (float) $this->input('internal_taxes', 0);

                $sumComponents = $net21 + $iva21 + $net105 + $iva105 + $net27 + $iva27
                    + $nonTaxed + $exempt + $ivaPerception + $iibbPerception + $internalTaxes;

                if ($this->filled('amount')) {
                    $targetAmount = (float) $this->input('amount');
                } elseif ($this->has('items') && is_array($this->input('items'))) {
                    $itemsSubtotal = collect($this->input('items', []))->sum(fn ($it) => (float) ($it['subtotal'] ?? 0));
                    $taxComponent = (float) $this->input('tax_amount', 0);
                    if ($taxComponent <= 0) {
                        $taxComponent = (float) $this->input('iva_21', 0) + (float) $this->input('iva_105', 0) + (float) $this->input('iva_27', 0)
                            + (float) $this->input('iva_perception', 0) + (float) $this->input('iibb_perception', 0) + (float) $this->input('internal_taxes', 0);
                    }
                    $targetAmount = $itemsSubtotal + (float) $this->input('freight_amount', 0) + $taxComponent - (float) $this->input('discount_amount', 0);
                } else {
                    $targetAmount = $sumComponents;
                }

                $diff = abs($sumComponents - $targetAmount);
                if ($diff > 0.05) {
                    $validator->errors()->add(
                        'amount',
                        sprintf(
                            'La suma de los componentes fiscales ($%s) no coincide con el total del comprobante ($%s). Diferencia: $%s',
                            number_format($sumComponents, 2, ',', '.'),
                            number_format($targetAmount, 2, ',', '.'),
                            number_format($diff, 2, ',', '.')
                        )
                    );
                }
            }
        });
    }
}
