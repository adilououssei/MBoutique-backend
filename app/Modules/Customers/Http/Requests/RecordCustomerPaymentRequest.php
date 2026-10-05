<?php

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Services\CustomerAccountService;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Plafonné à la dette par CustomerAccountService (PAIEMENT_INVALIDE).
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in([CustomerAccountService::MODE_CASH_REGISTER, CustomerAccountService::MODE_EXTERNAL])],
            'caisse_id' => ['required_if:mode,'.CustomerAccountService::MODE_CASH_REGISTER, 'nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
