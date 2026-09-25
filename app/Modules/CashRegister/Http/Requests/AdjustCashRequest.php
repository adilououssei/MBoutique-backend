<?php

namespace App\Modules\CashRegister\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The one movement type whose `amount` is signed (a correction can go
 * either way) rather than an unsigned magnitude like CashIn/CashOut —
 * see docs/cash-register.md §"Ajustement". `reason` is required, unlike
 * every other movement type (Phase 4.2 §19).
 */
class AdjustCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('amount') && is_numeric($this->input('amount')) && bccomp((string) $this->input('amount'), '0', 2) === 0) {
                $validator->errors()->add('amount', "Un ajustement de 0 n'a pas de sens — il ne changerait rien au solde.");
            }
        });
    }
}
