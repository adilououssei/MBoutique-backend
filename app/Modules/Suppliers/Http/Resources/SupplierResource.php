<?php

namespace App\Modules\Suppliers\Http\Resources;

use App\Modules\Sales\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $withTotals = array_key_exists('total_achats', $this->resource->getAttributes());

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'nom_contact' => $this->nom_contact,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'adresse' => $this->adresse,
            'notes' => $this->notes,
            'actif' => $this->actif,
            // Présents quand le contrôleur charge les agrégats (withSum).
            'total_achats' => $this->when($withTotals, fn () => Money::round((string) ($this->total_achats ?? 0))),
            'solde_du' => $this->when($withTotals, fn () => Money::round(bcsub((string) ($this->total_achats ?? 0), (string) ($this->total_paye ?? 0), 2))),
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
