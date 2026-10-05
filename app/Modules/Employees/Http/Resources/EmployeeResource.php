<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Sales\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $withMonthTotal = array_key_exists('paye_ce_mois', $this->resource->getAttributes());

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'poste' => $this->poste,
            'telephone' => $this->telephone,
            'adresse' => $this->adresse,
            'date_embauche' => $this->date_embauche?->toDateString(),
            'salaire' => $this->salaire,
            'periodicite_salaire' => $this->periodicite_salaire?->value,
            'notes' => $this->notes,
            'actif' => $this->actif,
            'compte' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'nom' => $this->user->nom, 'email' => $this->user->email] : null),
            // Somme versée ce mois-ci (salaire + avances + primes), quand chargée.
            'paye_ce_mois' => $this->when($withMonthTotal, fn () => Money::round((string) ($this->paye_ce_mois ?? 0))),
            'cree_le' => $this->created_at,
        ];
    }
}
