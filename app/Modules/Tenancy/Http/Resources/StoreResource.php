<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entreprise_id' => $this->entreprise_id,
            'domaine_activite' => $this->whenLoaded('businessDomain', fn () => [
                'slug' => $this->businessDomain->slug,
                'nom' => $this->businessDomain->nom,
            ]),
            'nom' => $this->nom,
            'slug' => $this->slug,
            'adresse' => $this->adresse,
            'telephone' => $this->telephone,
            'devise' => $this->devise,
            'fuseau_horaire' => $this->fuseau_horaire,
            'statut' => $this->statut->value,
            'parametres' => $this->parametres,
            // Populated only when this resource wraps a StoreUser-joined
            // row (see StoreController::mine) — the caller's own role/
            // membership status on this specific store.
            'mon_role' => $this->when(isset($this->mon_role), fn () => $this->mon_role),
            'cree_le' => $this->created_at,
        ];
    }
}
