<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie' => new CategoryResource($this->whenLoaded('category')),
            'nom' => $this->nom,
            'marque' => $this->marque,
            'modele' => $this->modele,
            'etat' => $this->etat?->value,
            'caracteristiques' => $this->caracteristiques,
            'fournisseur' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['id' => $this->supplier->id, 'nom' => $this->supplier->nom] : null),
            'suivi_unitaire' => $this->suivi_unitaire,
            'garantie_mois' => $this->garantie_mois,
            'quantites_vente' => $this->quantites_vente === null ? null : array_map(fn ($q) => number_format((float) $q, 3, '.', ''), $this->quantites_vente),
            'conditions_garantie' => $this->conditions_garantie,
            'slug' => $this->slug,
            'description' => $this->description,
            // asset() suit l'hôte de la requête (IP du PC vue par le téléphone),
            // contrairement à Storage::url() qui figerait APP_URL (localhost).
            'image_url' => $this->image ? asset('storage/'.$this->image) : null,
            'sku' => $this->sku,
            'code_barres' => $this->code_barres,
            'unite' => $this->unite->value,
            'type_article' => $this->type_article?->value,
            'dci' => $this->dci,
            'forme' => $this->forme,
            'dosage' => $this->dosage,
            'presentation' => $this->presentation,
            'laboratoire' => $this->laboratoire,
            'sur_ordonnance' => (bool) $this->sur_ordonnance,
            'unites_par_boite' => $this->unites_par_boite,
            'produit_parent_id' => $this->produit_parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? ['id' => $this->parent->id, 'nom' => $this->parent->nom] : null),
            'taille' => $this->taille,
            'couleur' => $this->couleur,
            'public_cible' => $this->public_cible,
            'collection' => $this->whenLoaded('collection', fn () => $this->collection ? ['id' => $this->collection->id, 'nom' => $this->collection->nom] : null),
            'nombre_variantes' => $this->whenCounted('variants'),
            'promotion' => $this->getAttribute('promotion_courante'),
            'temps_preparation_minutes' => $this->temps_preparation_minutes,
            'disponible' => $this->disponible,
            'supplements' => $this->whenLoaded('supplements', fn () => $this->supplements->map(fn ($s) => [
                'id' => $s->id, 'nom' => $s->nom, 'prix' => $s->vente_detail_active ? $s->prix_detail : $s->prix_gros, 'disponible' => $s->disponible && $s->actif,
            ])->values()),
            'conservation' => $this->conservation?->value,
            'duree_conservation_jours' => $this->duree_conservation_jours,
            'prix_achat' => $this->prix_achat,
            'vente_detail_active' => $this->vente_detail_active,
            'prix_detail' => $this->prix_detail,
            'vente_gros_active' => $this->vente_gros_active,
            'prix_gros' => $this->prix_gros,
            'actif' => $this->actif,
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
