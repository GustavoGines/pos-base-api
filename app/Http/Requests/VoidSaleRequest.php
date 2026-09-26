<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidSaleRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'user_id'       => 'nullable|integer|exists:users,id',
            'cash_shift_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('cash_shifts', 'id')->where('status', 'open'),
            ],
        ];
    }
}
