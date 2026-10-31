<?php

namespace App\Modules\Repairs\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'statut' => $this->statut,
            'client' => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null,
            'nom_client' => $this->customer?->nom ?? $this->nom_client,
            'telephone_client' => $this->customer?->telephone ?? $this->telephone_client,
            'appareil' => $this->appareil,
            'appareil_id' => $this->appareil_id,
            'marque' => $this->marque,
            'modele' => $this->modele,
            'imei' => $this->imei,
            'numero_serie' => $this->numero_serie,
            'panne' => $this->panne,
            'etat_reception' => $this->etat_reception,
            'diagnostic' => $this->diagnostic,
            'technicien' => $this->technician ? ['id' => $this->technician->id, 'nom' => $this->technician->nom] : null,
            'techniciens' => $this->whenLoaded('technicians', fn () => $this->technicians->map(fn ($t) => ['id' => $t->id, 'nom' => $t->nom])->values()),
            'bien' => $this->bien ?? (object) [],
            'photos' => collect($this->photos ?? [])->map(fn (string $path) => asset('storage/'.$path))->values(),
            'devis' => $this->whenLoaded('quote', fn () => $this->quote ? ['id' => $this->quote->id, 'numero' => $this->quote->numero] : null),
            'prestations' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'id' => $s->id, 'type' => $s->type, 'service_id' => $s->service_id, 'libelle' => $s->libelle, 'quantite' => $s->quantite, 'prix_unitaire' => $s->prix_unitaire, 'montant' => $s->montant,
            ])->values()),
            // Coût des matériaux (prix d'achat actuel) et marge estimée.
            'cout_materiaux' => $this->whenLoaded('parts', fn () => number_format((float) $this->parts->sum(fn ($p) => (float) $p->quantite * (float) (Product::withTrashed()->find($p->produit_id)?->prix_achat ?? 0)), 2, '.', '')),
            'en_retard' => $this->isLate(),
            'montant_credit' => $this->montant_credit,
            'livre_par' => $this->whenLoaded('deliveredBy', fn () => $this->deliveredBy?->nom),
            'remis_a' => $this->remis_a,
            'reception_confirmee' => (bool) $this->reception_confirmee,
            'cout_estime' => $this->cout_estime,
            'cout_final' => $this->cout_final,
            'montant_du' => number_format((float) $this->amountDue(), 2, '.', ''),
            'montant_paye' => $this->montant_paye,
            'reste_a_payer' => $this->remaining(),
            'depose_le' => $this->depose_le,
            'prevu_le' => $this->prevu_le?->toDateString(),
            'livre_le' => $this->livre_le,
            'note' => $this->note,
            'pieces' => $this->whenLoaded('parts', fn () => $this->parts->map(fn ($p) => ['id' => $p->id, 'produit_id' => $p->produit_id, 'nom_produit' => $p->nom_produit, 'quantite' => $p->quantite, 'prix_unitaire' => $p->prix_unitaire, 'montant' => $p->montant, 'facturee' => $p->facturee ?? true])),
            'paiements' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => ['id' => $p->id, 'montant' => $p->montant, 'mode' => $p->mode, 'par' => $p->createdBy?->nom, 'le' => $p->created_at])),
            'suivi' => $this->whenLoaded('followUps', fn () => $this->followUps->sortByDesc('id')->values()->map(fn ($f) => ['statut' => $f->statut, 'note' => $f->note, 'par' => $f->createdBy?->nom, 'le' => $f->created_at])),
        ];
    }
}
