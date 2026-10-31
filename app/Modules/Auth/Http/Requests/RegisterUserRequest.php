<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:utilisateurs,email'],
            'telephone' => ['nullable', 'string', 'max:32', 'unique:utilisateurs,telephone'],
            // Code ISO à 2 lettres (GET /auth/pays) : statistiques par pays.
            'pays' => ['required', 'string', Rule::in(Countries::codes())],
            'mot_de_passe' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
