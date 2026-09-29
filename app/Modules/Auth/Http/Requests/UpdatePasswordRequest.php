<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mot_de_passe_actuel' => ['required', 'string'],
            'mot_de_passe' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
