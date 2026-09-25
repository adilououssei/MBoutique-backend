<?php

namespace App\Modules\CashRegister\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class CreateCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', TenantScopedRules::uniqueInCurrentStore('cash_registers', 'code')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
