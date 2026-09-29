<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\BusinessUserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddBusinessUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Users are platform-wide, not tenant-scoped: a plain
            // exists:utilisateurs,email is correct here, no store-scoping needed
            // (contrast with docs/multi-tenancy.md Couche 5, which applies
            // to references to *tenant-scoped* resources).
            'email' => ['required', 'string', 'email', 'exists:utilisateurs,email'],
            'role' => ['required', Rule::in([BusinessUserRole::Owner->value, BusinessUserRole::Admin->value])],
        ];
    }
}
