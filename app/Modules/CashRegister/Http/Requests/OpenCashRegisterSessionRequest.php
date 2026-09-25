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
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
