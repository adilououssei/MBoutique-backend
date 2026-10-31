<?php

namespace App\Modules\Equipment\Http\Requests;

use App\Modules\Equipment\Enums\EquipmentStatus;
use App\Modules\Equipment\Enums\EquipmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentRequest extends FormRequest
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
            'type' => [$required, Rule::enum(EquipmentType::class)],
            'marque' => ['nullable', 'string', 'max:255'],
            'numero_serie' => ['nullable', 'string', 'max:255'],
            'date_acquisition' => ['nullable', 'date', 'before_or_equal:today'],
            'cout_acquisition' => ['nullable', 'numeric', 'min:0'],
            // « Hors service » se choisit ici ; panne et réparation passent par les interventions.
            'statut' => ['sometimes', Rule::enum(EquipmentStatus::class)],
            'frequence_entretien_jours' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'prochain_entretien_le' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
