<?php

namespace App\Modules\Suppliers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'fournisseur' => $this->whenLoaded('supplier', fn () => $this->supplier ? ['id' => $this->supplier->id, 'nom' => $this->supplier->nom] : null),
            'numero_facture_fournisseur' => $this->numero_facture_fournisseur,
            'reference_commande' => $this->reference_commande,
            'frais_annexes' => $this->frais_annexes,
            'detail_frais' => $this->detail_frais,
            'montant_total' => $this->montant_total,
            'montant_paye' => $this->montant_paye,
            'reste_a_payer' => $this->amountDue(),
            'statut_paiement' => $this->paymentStatus(),
            'statut' => $this->statut ?? 'recu',
            'commande_le' => $this->commande_le,
            'remise_fournisseur' => $this->remise_fournisseur,
            'note' => $this->note,
            'achete_le' => $this->achete_le,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'lignes' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'numero_lot' => $item->numero_lot,
                'date_peremption' => $item->date_peremption?->toDateString(),
                'fabrique_le' => $item->fabrique_le?->toDateString(),
                'quantite_commandee' => $item->quantite_commandee,
                'produit_id' => $item->produit_id,
                'nom_produit' => $item->nom_produit,
                'quantite' => $item->quantite,
                'cout_unitaire' => $item->cout_unitaire,
                'cout_revient_unitaire' => $item->cout_revient_unitaire,
                'total' => $item->montant_total,
            ])),
            'paiements' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'montant' => $payment->montant,
                'mode' => $payment->mode->value,
                'note' => $payment->note,
                'paye_le' => $payment->paye_le,
                'cree_par' => $payment->createdBy ? ['id' => $payment->createdBy->id, 'nom' => $payment->createdBy->nom] : null,
            ])),
        ];
    }
}
