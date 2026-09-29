<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'quantite' => $this->quantite,
            'quantite_avant' => $this->quantite_avant,
            'quantite_apres' => $this->quantite_apres,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'motif' => $this->motif,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'nom' => $this->createdBy->nom,
            ] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
