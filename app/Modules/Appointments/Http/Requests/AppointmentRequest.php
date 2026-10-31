<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/** Réservation (POST) ou modification/déplacement (PUT, champs facultatifs). */
class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'service_id' => [$creating ? 'required_without:services' : 'sometimes', 'integer', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')],
            // Employé actif de la boutique, ou null (« n'importe qui »).
            'employe_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')->where('actif', true)->whereNull('deleted_at')],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')->whereNull('deleted_at')],
            // Sans client enregistré, il faut au moins un nom.
            'nom_client' => [$creating ? 'required_without:client_id' : 'nullable', 'nullable', 'string', 'max:255'],
            'telephone_client' => ['nullable', 'string', 'max:30'],
            'debut_le' => [$creating ? 'required' : 'sometimes', 'date'],
            'duree_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'notes' => ['nullable', 'string'],
            // Salon : plusieurs prestations (durée totale = somme), chacune avec son employé éventuel.
            'services' => ['sometimes', 'array', 'min:1', 'max:10'],
            'services.*.service_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')],
            'services.*.employe_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')->where('actif', true)->whereNull('deleted_at')],
            // Demande à confirmer (le créneau est tout de même réservé).
            'statut' => ['sometimes', 'nullable', 'in:prevu,en_attente,en_cours'],
            // Acompte encaissé à la réservation, dans cette caisse.
            'acompte' => [$creating ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            // Forfait du client : la séance est décomptée quand le rendez-vous est terminé.
            'forfait_client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('forfaits_clients')],
        ];
    }
}
