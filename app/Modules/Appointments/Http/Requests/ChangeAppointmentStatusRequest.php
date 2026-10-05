<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // L'annulation a sa propre route (permission distincte).
            'statut' => ['required', Rule::in(['confirme', 'termine', 'absent'])],
            'vente_id' => ['nullable', 'integer', 'prohibited_unless:statut,termine', TenantScopedRules::existsInCurrentStore('ventes')],
        ];
    }
}
