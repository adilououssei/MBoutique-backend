<?php

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Enums\ExpensePaymentMode;
use App\Modules\Expenses\Models\Expense;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categorie' => ['required', Rule::enum(ExpenseCategory::class)],
            'libelle' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::enum(ExpensePaymentMode::class)],
            'caisse_id' => ['nullable', 'required_if:mode,caisse', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'depense_le' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
            // Hors caisse : moyen réellement utilisé.
            'moyen_paiement' => ['nullable', 'prohibited_if:mode,caisse', Rule::in(Expense::PAYMENT_MEANS)],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }
}
