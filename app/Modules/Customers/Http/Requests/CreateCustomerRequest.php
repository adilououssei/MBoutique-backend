<?php

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            // No format imposed beyond "a string" — the platform isn't
            // limited to one country's phone numbering, see docs/customers.md.
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'nom_entreprise' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'instructions_remise' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'sexe' => ['nullable', 'in:homme,femme'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'preferences' => ['nullable', 'array', 'max:12'],
            'preferences.*' => ['nullable', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
