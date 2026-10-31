<?php

namespace App\Modules\Warranties\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarrantyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'etat' => $this->state(),
            'nom_produit' => $this->nom_produit,
            'produit_id' => $this->produit_id,
            'quantite' => $this->quantite,
            'duree_mois' => $this->duree_mois,
            'debut_le' => $this->debut_le->toDateString(),
            'fin_le' => $this->fin_le->toDateString(),
            'jours_restants' => $this->statut === 'valide' ? (int) now()->startOfDay()->diffInDays($this->fin_le, false) : null,
            'conditions' => $this->conditions,
            'vente' => $this->whenLoaded('sale', fn () => ['id' => $this->sale->id, 'reference' => $this->sale->reference]),
            'appareil' => $this->whenLoaded('device', fn () => $this->device ? ['id' => $this->device->id, 'imei' => $this->device->imei, 'numero_serie' => $this->device->numero_serie] : null),
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            'reclamations_en_cours' => $this->whenCounted('open_claims'),
            'reclamations' => $this->whenLoaded('claims', fn () => $this->claims->map(fn ($c) => [
                'id' => $c->id,
                'numero' => $c->numero,
                'description' => $c->description,
                'statut' => $c->statut,
                'resolution' => $c->resolution,
                'cree_le' => $c->created_at,
                'cloturee_le' => $c->cloturee_le,
                'suivi' => $c->followUps->map(fn ($f) => ['statut' => $f->statut, 'note' => $f->note, 'par' => $f->createdBy?->nom, 'le' => $f->created_at]),
            ])),
        ];
    }
}
