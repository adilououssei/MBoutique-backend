<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Service;
use App\Modules\Sales\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie' => new CategoryResource($this->whenLoaded('category')),
            'nom' => $this->nom,
            'slug' => $this->slug,
            'description' => $this->description,
            'prix' => $this->prix,
            'duree_minutes' => $this->duree_minutes,
            'prix_variable' => (bool) $this->prix_variable,
            'image_url' => $this->image ? asset('storage/'.$this->image) : null,
            'commission_type' => $this->commission_type,
            'commission_valeur' => $this->commission_valeur,
            'consommables' => $this->whenLoaded('consumables', fn () => $this->consumables->map(fn ($c) => [
                'produit_id' => $c->produit_id,
                'nom' => $c->product?->nom,
                'unite' => $c->product?->unite,
                'quantite' => $c->quantite,
                'cout' => $c->product?->prix_achat === null ? null : Money::round(bcmul((string) $c->product->prix_achat, (string) $c->quantite, 4)),
            ])->values()),
            'cout_consommables' => $this->when(isset($this->cout_consommables), fn () => $this->cout_consommables),
            'est_formule' => (bool) $this->est_formule,
            'unite_tarif' => $this->unite_tarif,
            'delai_heures' => $this->delai_heures,
            'consignes' => $this->consignes,
            'tarifs_articles' => $this->whenLoaded('articlePrices', fn () => $this->articlePrices->map(fn ($p) => ['type_article' => $p->type_article, 'prix' => $p->prix])->values()),
            'composants' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => ['id' => $c->id, 'nom' => $c->nom, 'prix' => $c->prix, 'duree_minutes' => $c->duree_minutes])->values()),
            'duree_effective' => $this->when($this->relationLoaded('components'), fn () => $this->resource->effectiveDuration()),
            'employes_habilites' => $this->when(isset($this->employes_habilites), fn () => $this->employes_habilites),
            'employes_sans_restriction' => $this->when(isset($this->employes_sans_restriction), fn () => $this->employes_sans_restriction),
            'actif' => $this->actif,
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
