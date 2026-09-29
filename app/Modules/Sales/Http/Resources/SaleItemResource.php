<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'produit_id' => $this->produit_id,
            'nom_produit' => $this->nom_produit,
            'mode_prix' => $this->mode_prix->value,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'total' => $this->montant_total,
        ];
    }
}
