<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Support\ProductRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ProductRules::rules(ignore: $this->route('product'), partial: true);
    }
}
