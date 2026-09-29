<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'raison_sociale' => ['nullable', 'string', 'max:255'],
            'pays' => ['nullable', 'string', 'max:2'],
            'devise' => ['nullable', 'string', 'size:3'],
            'fuseau_horaire' => ['nullable', 'string', 'max:64'],
        ];
    }
}
