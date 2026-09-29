<?php

namespace App\Modules\CashRegister\Http\Resources;

use App\Modules\CashRegister\Models\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegister
 */
class CashRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'code' => $this->code,
            'actif' => $this->actif,
            'est_ouverte' => $this->isOpen(),
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
