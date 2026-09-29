<?php

namespace App\Modules\CashRegister\Http\Resources;

use App\Modules\CashRegister\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashMovement
 */
class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'montant' => $this->montant,
            'solde_avant' => $this->solde_avant,
            'solde_apres' => $this->solde_apres,
            'motif' => $this->motif,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'nom' => $this->createdBy->nom,
            ] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
