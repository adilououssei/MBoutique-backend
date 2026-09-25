<?php

namespace App\Modules\CashRegister\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:100',
                TenantScopedRules::uniqueInCurrentStore('cash_registers', 'code')->ignore($this->route('cashRegister')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
