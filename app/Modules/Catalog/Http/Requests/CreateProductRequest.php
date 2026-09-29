<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Support\ProductRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateProductRequest extends FormRequest
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
        return ProductRules::rules();
    }
}
