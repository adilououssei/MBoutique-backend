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
            'notes' => ['nullable', 'string'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
