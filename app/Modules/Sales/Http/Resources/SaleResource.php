<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `remise`/`total` here, not `montant_remise`/`montant_total` (the
 * column names) — matches the receipt contract exactly as specified by
 * the Phase 4.3 brief §17, a deliberate API-vs-storage naming split.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'client' => new CustomerResource($this->whenLoaded('customer')),
            'vendeur' => $this->whenLoaded('soldBy', fn () => $this->soldBy ? ['id' => $this->soldBy->id, 'nom' => $this->soldBy->nom] : null),
            'lignes' => SaleItemResource::collection($this->whenLoaded('items')),
            'sous_total' => $this->sous_total,
            'remise' => $this->montant_remise,
            'total' => $this->montant_total,
            'mode_paiement' => $this->mode_paiement->value,
            'statut' => $this->statut->value,
            'vendue_le' => $this->vendue_le,
            'annulation' => $this->isCancelled() ? [
                'le' => $this->annulee_le,
                'motif' => $this->motif_annulation,
                'par' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? ['id' => $this->cancelledBy->id, 'nom' => $this->cancelledBy->nom] : null),
            ] : null,
        ];
    }
}
