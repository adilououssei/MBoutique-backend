<?php

namespace App\Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'vente' => ['id' => $this->sale->id, 'reference' => $this->sale->reference],
            'client' => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom] : null,
            'motif' => $this->motif,
            'decision' => $this->decision,
            'etat_produits' => $this->etat_produits,
            'montant' => $this->montant,
            'montant_rendu' => $this->montant_rendu,
            'statut_verification' => $this->statut_verification,
            'verifie_le' => $this->verifie_le,
            'note_verification' => $this->note_verification,
            'vente_echange_id' => $this->vente_echange_id,
            'mode_remboursement' => $this->mode_remboursement,
            'lignes' => $this->lines->map(fn ($l) => ['ligne_vente_id' => $l->ligne_vente_id, 'nom_produit' => $l->nom_produit, 'quantite' => $l->quantite, 'montant' => $l->montant, 'appareil_ids' => $l->appareil_ids]),
            'cree_par' => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null,
            'cree_le' => $this->created_at,
        ];
    }
}
