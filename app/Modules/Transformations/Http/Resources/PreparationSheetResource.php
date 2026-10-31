<?php

namespace App\Modules\Transformations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreparationSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'produit' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'nom' => $this->product->nom,
                'unite' => $this->product->unite->value,
            ]),
            'quantite_produite' => $this->quantite_produite,
            'duree_conservation_jours' => $this->duree_conservation_jours,
            'instructions' => $this->instructions,
            'actif' => $this->actif,
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($ingredient) => [
                'produit_id' => $ingredient->produit_id,
                'nom' => $ingredient->product?->nom,
                'unite' => $ingredient->product?->unite->value,
                'prix_achat' => $ingredient->product?->prix_achat,
                'quantite' => $ingredient->quantite,
            ])),
        ];
    }
}
