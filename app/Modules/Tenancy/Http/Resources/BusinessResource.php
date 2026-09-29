<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Business
 */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'raison_sociale' => $this->raison_sociale,
            'pays' => $this->pays,
            'devise' => $this->devise,
            'fuseau_horaire' => $this->fuseau_horaire,
            'statut' => $this->statut->value,
            'cree_le' => $this->created_at,
        ];
    }
}
