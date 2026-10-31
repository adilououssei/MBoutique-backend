<?php

namespace App\Modules\Conservation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'numero_lot' => $this->numero_lot,
            'statut' => $this->statut ?? 'actif',
            'motif_blocage' => $this->motif_blocage,
            'vendable' => $this->isSellable(),
            'fabrique_le' => $this->fabrique_le?->toDateString(),
            'prix_achat' => $this->prix_achat,
            'fournisseur' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['id' => $this->supplier->id, 'nom' => $this->supplier->nom] : null),
            'achat_id' => $this->achat_id,
            'journal' => $this->whenLoaded('journal', fn () => $this->journal->map(fn ($j) => ['action' => $j->action, 'details' => $j->details, 'par' => $j->createdBy?->nom, 'le' => $j->created_at])),
            'produit' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'nom' => $this->product->nom,
                'unite' => $this->product->unite->value,
            ]),
            'quantite_initiale' => $this->quantite_initiale,
            'quantite_restante' => $this->quantite_restante,
            'recu_le' => $this->recu_le,
            'date_limite' => $this->date_limite?->toDateString(),
            'jours_restants' => $this->daysLeft(),
            'etat' => $this->state(),
            'conservation' => $this->conservation?->value,
            'equipement' => $this->whenLoaded('storage', fn () => $this->storage ? ['id' => $this->storage->id, 'nom' => $this->storage->nom] : null),
            'note' => $this->note,
        ];
    }
}
