<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Services\DropOffTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    private function dropOff(): array
    {
        $open = in_array($this->statut->value, ['en_attente', 'en_preparation', 'controle', 'prete'], true);

        return [
            'articles' => $this->articles->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->code,
                'ligne_commande_id' => $a->ligne_commande_id,
                'type_article' => $a->type_article,
                'couleur' => $a->couleur,
                'marque' => $a->marque,
                'etat' => $a->etat,
                'defauts' => $a->defauts,
                'instructions' => $a->instructions,
                'photo_url' => $a->photo ? asset('storage/'.$a->photo) : null,
                'statut' => $a->statut,
                'employe' => $a->employee ? ['id' => $a->employee->id, 'nom' => $a->employee->nom] : null,
                'remis_le' => $a->remis_le,
            ])->values(),
            'paiement' => app(DropOffTracker::class)->paymentSummary($this->resource),
            'en_retard' => $open && $this->date_promise !== null && $this->date_promise->isPast(),
            'responsable' => $this->responsible ? ['id' => $this->responsible->id, 'nom' => $this->responsible->nom] : null,
            'statut_livraison' => $this->statut_livraison,
            'livreur' => $this->deliverer ? ['id' => $this->deliverer->id, 'nom' => $this->deliverer->nom] : null,
            'livree_le' => $this->livree_le,
            'remise_le' => $this->remise_le,
            'consommation_faite' => (bool) $this->consommation_faite,
            'remises' => $this->whenLoaded('handovers', fn () => $this->handovers->map(fn ($h) => [
                'id' => $h->id, 'mode' => $h->mode, 'nombre_articles' => $h->nombre_articles, 'montant_paye' => $h->montant_paye,
                'note' => $h->note, 'par' => $h->handedBy?->nom, 'le' => $h->remis_le,
            ])->values()),
            'historique' => $this->whenLoaded('history', fn () => $this->history->map(fn ($e) => [
                'id' => $e->id, 'evenement' => $e->evenement, 'article_id' => $e->article_id, 'de' => $e->de, 'vers' => $e->vers,
                'montant' => $e->montant, 'note' => $e->note, 'par' => $e->user?->nom, 'le' => $e->created_at,
            ])->values()),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'statut' => $this->statut->value,
            'table' => $this->whenLoaded('diningTable', fn () => $this->diningTable ? ['id' => $this->diningTable->id, 'nom' => $this->diningTable->nom] : null),
            'serveur' => $this->whenLoaded('waiter', fn () => $this->waiter ? ['id' => $this->waiter->id, 'nom' => $this->waiter->nom] : null),
            'nombre_couverts' => $this->nombre_couverts,
            'addition_demandee_le' => $this->addition_demandee_le,
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            'nom_client' => $this->customer?->nom ?? $this->nom_client,
            'telephone_client' => $this->customer?->telephone ?? $this->telephone_client,
            'adresse_livraison' => $this->adresse_livraison,
            'date_promise' => $this->date_promise,
            'note' => $this->note,
            'lignes' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'ligne_parent_id' => $item->ligne_parent_id,
                'vente_id' => $item->vente_id,
                'type' => $item->produit_id !== null ? 'produit' : 'service',
                'produit_id' => $item->produit_id,
                'service_id' => $item->service_id,
                'mode_prix' => $item->mode_prix?->value,
                'nom' => $item->nom,
                'quantite' => $item->quantite,
                'prix_unitaire' => $item->prix_unitaire,
                'total' => $item->lineTotal(),
                'note' => $item->note,
                'statut_cuisine' => $item->statut_cuisine?->value,
                'preparation_commencee_le' => $item->preparation_commencee_le,
                'prete_le' => $item->prete_le,
                'servie_le' => $item->servie_le,
            ])),
            // Indicatif : le montant encaissé est celui de la vente liée.
            'total' => $this->whenLoaded('items', fn () => $this->total()),
            'acompte' => $this->montant_acompte,
            'reste_a_payer' => $this->whenLoaded('items', fn () => $this->remainingAmount()),
            'deja_regle' => $this->whenLoaded('items', fn () => bcsub($this->total(), $this->unpaidTotal(), 2)),
            'nombre_articles' => $this->whenLoaded('items', fn () => $this->items->count()),
            'vente_id' => $this->vente_id,
            // Pressing : pièces, paiement (indépendant de la remise), retard, responsable, livraison, remises, historique.
            ...($this->relationLoaded('articles') ? $this->dropOff() : []),
            'motif_annulation' => $this->motif_annulation,
            'cree_par' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'nom' => $this->createdBy->nom] : null),
            'cree_le' => $this->created_at,
        ];
    }
}
