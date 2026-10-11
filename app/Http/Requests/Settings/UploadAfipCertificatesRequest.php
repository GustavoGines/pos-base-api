<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadAfipCertificatesRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cuit' => 'required|string',
            'cert_file' => 'nullable|file',
            'key_file' => 'nullable|file',
            'cert_content' => 'nullable|string',
            'key_content' => 'nullable|string',
            'cert' => 'nullable',
            'key' => 'nullable',
            'key_passphrase' => 'nullable|string',
        ];
    }
}
