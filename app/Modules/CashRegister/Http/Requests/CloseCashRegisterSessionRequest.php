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
            'actual_closing_amount' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string'],
        ];
    }
}
