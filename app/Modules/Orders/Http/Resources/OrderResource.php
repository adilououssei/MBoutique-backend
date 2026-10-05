<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'statut' => $this->statut->value,
            'table' => $this->whenLoaded('diningTable', fn () => $this->diningTable ? ['id' => $this->diningTable->id, 'nom' => $this->diningTable->nom] : null),
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            'nom_client' => $this->customer?->nom ?? $this->nom_client,
            'telephone_client' => $this->customer?->telephone ?? $this->telephone_client,
            'adresse_livraison' => $this->adresse_livraison,
            'date_promise' => $this->date_promise,
            'note' => $this->note,
            'lignes' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->produit_id !== null ? 'produit' : 'service',
                'produit_id' => $item->produit_id,
                'service_id' => $item->service_id,
                'mode_prix' => $item->mode_prix?->value,
                'nom' => $item->nom,
                'quantite' => $item->quantite,
                'prix_unitaire' => $item->prix_unitaire,
                'total' => $item->lineTotal(),
                'note' => $item->note,
            ])),
            // Indicatif : le montant encaissé est celui de la vente liée.
            'total' => $this->whenLoaded('items', fn () => $this->total()),
            'nombre_articles' => $this->whenLoaded('items', fn () => $this->items->count()),
            'vente_id' => $this->vente_id,
            'motif_annulation' => $this->motif_annulation,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
