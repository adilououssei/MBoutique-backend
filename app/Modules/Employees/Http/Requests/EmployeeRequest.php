<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Enums\SalaryPeriod;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Création (POST) et modification (PUT, champs facultatifs) d'une fiche employé. */
class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nom' => [$required, 'string', 'max:255'],
            'poste' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'date_embauche' => ['nullable', 'date'],
            'salaire' => ['nullable', 'numeric', 'min:0'],
            'periodicite_salaire' => ['nullable', 'required_with:salaire', Rule::enum(SalaryPeriod::class)],
            'notes' => ['nullable', 'string'],
            'actif' => ['sometimes', 'boolean'],
            // Compte d'un membre de CETTE boutique, et un seul employé par compte.
            'utilisateur_id' => [
                'nullable', 'integer',
                TenantScopedRules::existsInCurrentStore('utilisateurs_boutique', 'utilisateur_id'),
                TenantScopedRules::uniqueInCurrentStore('employes', 'utilisateur_id')->ignore($this->route('employee'))->whereNull('deleted_at'),
            ],
        ];
    }
}
