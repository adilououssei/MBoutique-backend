<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
            'capacite' => ['nullable', 'integer', 'min:1', 'max:500'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
