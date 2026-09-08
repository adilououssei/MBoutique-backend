<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->input('slug') ?: Str::slug((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', TenantScopedRules::uniqueInCurrentStore('products', 'slug')],
            'description' => ['nullable', 'string'],
            // category_id must belong to the CURRENT store — Couche 5 of
            // docs/multi-tenancy.md, first real consumer of this helper.
            'category_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'sku' => ['nullable', 'string', 'max:100', TenantScopedRules::uniqueInCurrentStore('products', 'sku')],
            'barcode' => ['nullable', 'string', 'max:100', TenantScopedRules::uniqueInCurrentStore('products', 'barcode')],
            'unit' => ['sometimes', Rule::enum(ProductUnit::class)],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
