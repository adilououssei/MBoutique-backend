<?php

namespace App\Modules\Suppliers\Http\Requests;

use App\Modules\Suppliers\Enums\PurchasePaymentMode;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::enum(PurchasePaymentMode::class)],
            'caisse_id' => ['nullable', 'required_if:mode,caisse', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
