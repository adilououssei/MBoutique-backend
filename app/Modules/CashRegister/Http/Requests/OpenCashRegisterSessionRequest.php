<?php

namespace App\Modules\CashRegister\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant_ouverture' => ['required', 'numeric', 'min:0'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
