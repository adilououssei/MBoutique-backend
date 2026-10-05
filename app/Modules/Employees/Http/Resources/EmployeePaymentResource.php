<?php

namespace App\Modules\Employees\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'montant' => $this->montant,
            'periode' => $this->periode,
            'mode' => $this->mode->value,
            'note' => $this->note,
            'paye_le' => $this->paye_le,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
        ];
    }
}
