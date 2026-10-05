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
            'type' => $this->isService() ? 'service' : 'produit',
            'produit_id' => $this->produit_id,
            'service_id' => $this->service_id,
            // Libellé figé au moment de la vente (produit ou service) —
            // le nom de colonne est historique.
            'nom_produit' => $this->nom_produit,
            'mode_prix' => $this->mode_prix?->value,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'remise' => $this->montant_remise,
            'total' => $this->montant_total,
        ];
    }
}
