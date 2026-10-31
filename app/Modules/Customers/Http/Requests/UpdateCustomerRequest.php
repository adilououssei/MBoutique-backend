<?php

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
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
