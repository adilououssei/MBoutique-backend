<?php

namespace App\Modules\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'categorie' => $this->categorie->value,
            'libelle_categorie' => $this->categorie->label(),
            'libelle' => $this->libelle,
            'montant' => $this->montant,
            'mode' => $this->mode->value,
            'moyen_paiement' => $this->moyen_paiement,
            'a_justificatif' => $this->justificatif !== null,
            'depense_le' => $this->depense_le,
            'note' => $this->note,
            'statut' => $this->statut,
            'annulee_le' => $this->annulee_le,
            'motif_annulation' => $this->motif_annulation,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
        ];
    }
}
