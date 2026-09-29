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
            'nom' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('boutiques', 'slug')],
            // A domain must be selectable (active) to be chosen for a new
            // store — see docs/feature-gate.md: this is the ONLY place
            // BusinessDomain.actif is enforced, deliberately not at
            // FeatureGate resolution time for stores that already exist.
            'domaine_activite_id' => ['required', Rule::exists('domaines_activite', 'id')->where('actif', true)],
            'adresse' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:32'],
            'devise' => ['nullable', 'string', 'size:3'],
            'fuseau_horaire' => ['nullable', 'string', 'max:64'],
        ];
    }
}
