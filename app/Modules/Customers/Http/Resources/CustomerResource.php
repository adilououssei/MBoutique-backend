<?php

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'nom_entreprise' => $this->nom_entreprise,
            'adresse' => $this->adresse,
            'notes' => $this->notes,
            'actif' => $this->actif,
            // Dette en cours (négatif = avoir) — présent quand chargé via withSum.
            'solde' => $this->whenHas('account_entries_sum_montant', fn ($sum) => number_format((float) $sum, 2, '.', '')),
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
