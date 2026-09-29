<?php

namespace App\Modules\CashRegister\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant_fermeture_reel' => ['required', 'numeric', 'min:0'],
            'note_fermeture' => ['nullable', 'string'],
        ];
    }
}
