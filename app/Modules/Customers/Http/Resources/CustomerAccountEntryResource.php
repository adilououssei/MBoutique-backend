<?php

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerAccountEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAccountEntry
 */
class CustomerAccountEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'montant' => $this->montant,
            'mode' => $this->mode,
            // Vente concernée (vente_credit / annulation_vente) — pour ouvrir le ticket.
            'vente' => $this->reference_type === 'vente'
                ? ['id' => $this->reference_id, 'reference' => $this->reference?->reference]
                : null,
            'note' => $this->note,
            'par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
