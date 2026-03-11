<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;

class ReconciliationValidateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $branchId = $this->input('branch_id');

        if (! $branchId) {
            return true; // Let validation handle missing branch_id
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
            'branch_id' => ['required', 'exists:branches,id'],
            'bank_account_id' => [
                'required',
                'exists:bank_accounts,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $bankAccount = BankAccount::find($value);
                    if (! $bankAccount) {
                        $fail('La cuenta bancaria no existe.');

                        return;
                    }
                    if ($bankAccount->branch_id !== (int) $this->input('branch_id')) {
                        $fail('La cuenta bancaria no pertenece a esta sucursal.');
                    }
                    if (! $bankAccount->sap_bank_key) {
                        $fail('La cuenta bancaria no tiene configurada la Clave Bancaria SAP.');
                    }
                },
            ],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
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
            'branch_id.required' => 'La sucursal es obligatoria.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
            'bank_account_id.required' => 'La cuenta bancaria es obligatoria.',
            'bank_account_id.exists' => 'La cuenta bancaria seleccionada no existe.',
            'date_from.required' => 'La fecha inicial es obligatoria.',
            'date_from.date' => 'La fecha inicial no es valida.',
            'date_to.required' => 'La fecha final es obligatoria.',
            'date_to.date' => 'La fecha final no es valida.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'file.required' => 'El archivo es obligatorio.',
            'file.extensions' => 'El archivo debe ser de tipo xlsx, xls o csv.',
            'file.max' => 'El archivo no puede superar los 10 MB.',
        ];
    }
}
