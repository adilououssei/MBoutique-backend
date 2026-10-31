<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
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
                TenantScopedRules::uniqueInCurrentStore('categories', 'slug')->ignore($this->route('category')),
            ],
            'description' => ['nullable', 'string'],
            // Sous-catégorie : rattachée à une catégorie de la boutique.
            'parent_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')->whereNull('deleted_at')],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
