<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->input('slug') ?: Str::slug((string) $this->input('nom')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', TenantScopedRules::uniqueInCurrentStore('services', 'slug')],
            'description' => ['nullable', 'string'],
            'categorie_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'prix' => ['required', 'numeric', 'min:0'],
            'duree_minutes' => ['nullable', 'integer', 'min:1'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
