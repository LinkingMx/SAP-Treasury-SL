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
    public function rules(): array
    {
        return [
            'acquirer_id' => ['required', 'exists:acquirers,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:40960'],
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
        ];
    }
}
