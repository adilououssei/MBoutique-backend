<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jeton' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'mot_de_passe' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
