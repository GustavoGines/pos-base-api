<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type'      => 'required|in:in,out,increment,decrement',
            'quantity'  => 'required|numeric|min:0',
            'notes'     => 'nullable|string|max:500',
            'min_stock' => 'nullable|numeric|min:0',
            'user_id'   => 'nullable|exists:users,id',
        ];
    }
}
