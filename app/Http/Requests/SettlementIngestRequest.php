<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettlementIngestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $branchId = $this->input('branch_id');

        if (! $branchId) {
            return true;
        }

        return $this->user()->branches()->where('branches.id', $branchId)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    /**
     * Decode the parse_config JSON string sent via multipart FormData.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('parse_config'))) {
            $this->merge(['parse_config' => json_decode($this->input('parse_config'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'acquirer_id' => ['required', 'exists:acquirers,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:40960'],
            'remember' => ['nullable', 'boolean'],
            'parse_config' => ['required', 'array'],
            'parse_config.columns' => ['required', 'array'],
            'parse_config.columns.transaction_date.index' => ['required', 'integer', 'min:0'],
            'parse_config.columns.amount.index' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'acquirer_id.required' => 'El adquirente es obligatorio.',
            'acquirer_id.exists' => 'El adquirente seleccionado no existe.',
            'branch_id.required' => 'La sucursal es obligatoria.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
            'file.required' => 'El archivo es obligatorio.',
            'file.extensions' => 'El archivo debe ser de tipo xlsx, xls o csv.',
            'file.max' => 'El archivo no puede superar los 40 MB.',
            'parse_config.required' => 'Falta el mapeo de columnas.',
            'parse_config.columns.transaction_date.index.required' => 'Debes mapear la columna de Fecha.',
            'parse_config.columns.amount.index.required' => 'Debes mapear la columna de Monto.',
        ];
    }
}
