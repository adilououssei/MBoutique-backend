<?php

namespace App\Modules\CashRegister\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
