<?php

namespace App\Modules\Equipment\Http\Requests;

use App\Modules\Equipment\Enums\InterventionType;
use App\Modules\Expenses\Enums\ExpensePaymentMode;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InterventionType::class)],
            'survenue_le' => ['nullable', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string', 'max:1000'],
            'cout' => ['nullable', 'numeric', 'min:0'],
            'prestataire' => ['nullable', 'string', 'max:255'],
            // Coût payé maintenant : enregistré comme dépense « Entretien et réparations ».
            'paiement' => ['nullable', 'array'],
            'paiement.mode' => ['required_with:paiement', Rule::enum(ExpensePaymentMode::class)],
            'paiement.caisse_id' => ['nullable', 'required_if:paiement.mode,caisse', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ];
    }
}
