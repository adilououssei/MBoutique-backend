<?php

namespace App\Modules\Repairs\Http\Resources;

use App\Modules\Repairs\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'statut' => $this->effectiveStatus(),
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            'nom_client' => $this->customer?->nom ?? $this->nom_client,
            'telephone_client' => $this->customer?->telephone ?? $this->telephone_client,
            'objet' => $this->objet,
            'description' => $this->description,
            'valide_jusqu_au' => $this->valide_jusqu_au?->toDateString(),
            'lignes' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id, 'type' => $l->type, 'service_id' => $l->service_id, 'produit_id' => $l->produit_id, 'libelle' => $l->libelle,
                'quantite' => $l->quantite, 'prix_unitaire' => $l->prix_unitaire, 'montant' => $l->montant,
            ])->values()),
            'sous_total' => $this->whenLoaded('lines', fn () => number_format((float) $this->lines->sum('montant'), 2, '.', '')),
            'montant_remise' => $this->montant_remise,
            'montant_total' => $this->montant_total,
            'note' => $this->note,
            'reparation' => $this->whenLoaded('repair', fn () => $this->repair ? ['id' => $this->repair->id, 'numero' => $this->repair->numero, 'statut' => $this->repair->statut] : null),
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->nom),
            'cree_le' => $this->created_at,
        ];
    }
}
