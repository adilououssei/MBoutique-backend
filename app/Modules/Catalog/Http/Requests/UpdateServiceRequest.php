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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                TenantScopedRules::uniqueInCurrentStore('services', 'slug')->ignore($this->route('service')),
            ],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
