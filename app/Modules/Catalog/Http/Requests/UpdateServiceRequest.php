<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                TenantScopedRules::uniqueInCurrentStore('services', 'slug')->ignore($this->route('service')),
            ],
            'description' => ['nullable', 'string'],
            'categorie_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'prix' => ['sometimes', 'required', 'numeric', 'min:0'],
            'duree_minutes' => ['nullable', 'integer', 'min:1'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
