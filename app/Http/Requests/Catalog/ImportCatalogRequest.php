<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportCatalogRequest extends FormRequest
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
            'file' => 'required|file|mimes:xlsx,csv,xls,txt',
            'default_supplier_id' => 'nullable|integer|exists:suppliers,id',
            'default_margin_percent' => 'nullable|numeric|min:0|max:10000',
        ];
    }

    /**
     * Custom message for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debe adjuntar un archivo para la importación.',
            'file.file' => 'El archivo proporcionado no es válido.',
            'file.mimes' => 'El archivo debe ser un documento de Excel o CSV (.xlsx, .csv, .xls, .txt).',
            'default_supplier_id.exists' => 'El proveedor seleccionado por defecto no existe.',
            'default_margin_percent.numeric' => 'El margen por defecto debe ser un número válido.',
        ];
    }
}
