<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('stores', 'slug')],
            // A domain must be selectable (active) to be chosen for a new
            // store — see docs/feature-gate.md: this is the ONLY place
            // BusinessDomain.is_active is enforced, deliberately not at
            // FeatureGate resolution time for stores that already exist.
            'business_domain_id' => ['required', Rule::exists('business_domains', 'id')->where('is_active', true)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
