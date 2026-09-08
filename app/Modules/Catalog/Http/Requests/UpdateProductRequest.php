<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
                TenantScopedRules::uniqueInCurrentStore('products', 'slug')->ignore($this->route('product')),
            ],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'sku' => [
                'nullable', 'string', 'max:100',
                TenantScopedRules::uniqueInCurrentStore('products', 'sku')->ignore($this->route('product')),
            ],
            'barcode' => [
                'nullable', 'string', 'max:100',
                TenantScopedRules::uniqueInCurrentStore('products', 'barcode')->ignore($this->route('product')),
            ],
            'unit' => ['sometimes', Rule::enum(ProductUnit::class)],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
