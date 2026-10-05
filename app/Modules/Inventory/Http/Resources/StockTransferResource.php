<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `sens` est relatif à la boutique de la requête : `sortant` si elle a
 * envoyé la marchandise, `entrant` si elle l'a reçue. Les produits des
 * lignes sont ceux de cette boutique.
 *
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $store = $request->route('store');
        $outgoing = $store instanceof Store && $store->id === $this->boutique_source_id;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'sens' => $outgoing ? 'sortant' : 'entrant',
            'source' => $this->whenLoaded('source', fn () => ['id' => $this->source->id, 'nom' => $this->source->nom]),
            'destination' => $this->whenLoaded('destination', fn () => ['id' => $this->destination->id, 'nom' => $this->destination->nom]),
            'nombre_articles' => $this->whenLoaded('lines', fn () => $this->lines->count()),
            'lignes' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'produit_id' => $outgoing ? $line->produit_source_id : $line->produit_destination_id,
                'nom_produit' => $line->nom_produit,
                'quantite' => $line->quantite,
                'produit_cree' => $line->produit_cree,
            ])),
            'note' => $this->note,
            'par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
