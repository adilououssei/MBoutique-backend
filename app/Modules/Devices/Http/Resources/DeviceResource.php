<?php

namespace App\Modules\Devices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produit' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'nom' => $this->product->nom,
                'marque' => $this->product->marque,
                'modele' => $this->product->modele,
            ]),
            'imei' => $this->imei,
            'imei_2' => $this->imei_2,
            'numero_serie' => $this->numero_serie,
            'identifiant' => $this->identifier(),
            'statut' => $this->statut->value,
            'libelle_statut' => $this->statut->label(),
            'etat' => $this->etat?->value,
            'cout_achat' => $this->cout_achat,
            'vente' => $this->whenLoaded('sale', fn () => $this->sale ? ['id' => $this->sale->id, 'reference' => $this->sale->reference, 'vendue_le' => $this->sale->vendue_le] : null),
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            'vendu_le' => $this->vendu_le,
            'note' => $this->note,
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'evenement' => $e->evenement,
                'description' => $e->description,
                'reference_type' => $e->reference_type,
                'reference_id' => $e->reference_id,
                'cree_par' => $e->createdBy ? ['id' => $e->createdBy->id, 'nom' => $e->createdBy->nom] : null,
                'le' => $e->created_at,
            ])),
            'cree_le' => $this->created_at,
        ];
    }
}
