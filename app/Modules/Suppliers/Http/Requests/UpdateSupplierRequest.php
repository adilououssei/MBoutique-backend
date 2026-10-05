<?php

namespace App\Modules\Suppliers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'nom_contact' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
