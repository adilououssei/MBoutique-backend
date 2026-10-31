<?php

namespace App\Modules\Transformations\Http\Resources;

use App\Modules\Transformations\Models\TransformationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransformationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $line = fn (TransformationItem $item) => [
            'produit_id' => $item->produit_id,
            'nom_produit' => $item->nom_produit,
            'unite' => $item->unite,
            'quantite' => $item->quantite,
            'cout_unitaire' => $item->cout_unitaire,
            'cout_total' => $item->cout_total,
            'prix_vente_unitaire' => $item->prix_vente_unitaire,
            'marge_potentielle' => $item->potentialMargin(),
        ];

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'reference' => $this->reference,
            'statut' => $this->statut->value,
            'fiche' => $this->whenLoaded('preparationSheet', fn () => $this->preparationSheet ? ['id' => $this->preparationSheet->id, 'nom' => $this->preparationSheet->nom] : null),
            'cout_matieres' => $this->cout_matieres,
            'frais_annexes' => $this->frais_annexes,
            'cout_total' => $this->cout_total,
            'poids_entree' => $this->poids_entree,
            'poids_sortie' => $this->poids_sortie,
            'perte' => $this->perte,
            'taux_perte' => $this->lossRate(),
            'methode_repartition' => $this->methode_repartition->value,
            'consommer_avant' => $this->consommer_avant?->toDateString(),
            'note' => $this->note,
            'effectuee_le' => $this->effectuee_le,
            'entrees' => $this->whenLoaded('items', fn () => $this->items->reject->isProduced()->values()->map($line)),
            'sorties' => $this->whenLoaded('items', fn () => $this->items->filter->isProduced()->values()->map($line)),
            // Valeur de vente potentielle des produits obtenus et marge attendue.
            'valeur_vente' => $this->whenLoaded('items', fn () => $this->saleValue()),
            'marge_potentielle' => $this->whenLoaded('items', fn () => $this->saleValue() === null ? null : number_format((float) bcsub($this->saleValue(), (string) $this->cout_total, 2), 2, '.', '')),
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'annulee_le' => $this->annulee_le,
            'motif_annulation' => $this->motif_annulation,
        ];
    }

    /** Somme quantité × prix de vente des produits obtenus (null si l'un n'a pas de prix). */
    private function saleValue(): ?string
    {
        $value = '0';
        foreach ($this->items->filter->isProduced() as $item) {
            if ($item->prix_vente_unitaire === null) {
                return null;
            }
            $value = bcadd($value, bcmul((string) $item->prix_vente_unitaire, (string) $item->quantite, 6), 6);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
