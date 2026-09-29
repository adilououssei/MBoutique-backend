<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddStoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'exists:utilisateurs,email'],
            'role' => ['required', Rule::in(StoreRole::all())],
        ];
    }
}
