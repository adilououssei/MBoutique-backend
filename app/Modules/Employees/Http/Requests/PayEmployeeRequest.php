<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Enums\EmployeePaymentMode;
use App\Modules\Employees\Enums\EmployeePaymentType;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(EmployeePaymentType::class)],
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::enum(EmployeePaymentMode::class)],
            'caisse_id' => ['nullable', 'required_if:mode,caisse', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'periode' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
