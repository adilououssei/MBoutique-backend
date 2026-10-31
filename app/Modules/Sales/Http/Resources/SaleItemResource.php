<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->forfait_id !== null ? 'forfait' : ($this->isService() ? 'service' : 'produit'),
            'produit_id' => $this->produit_id,
            'service_id' => $this->service_id,
            'employe_id' => $this->employe_id,
            'forfait_id' => $this->forfait_id,
            // Libellé figé au moment de la vente (produit ou service) —
            // le nom de colonne est historique.
            'nom_produit' => $this->nom_produit,
            'mode_prix' => $this->mode_prix?->value,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'remise' => $this->montant_remise,
            'promotion_id' => $this->promotion_id,
            // Pharmacie : lots d'où les unités sont sorties (traçabilité).
            'lots' => DB::table('lots_vente')->join('lots_stock', 'lots_stock.id', '=', 'lots_vente.lot_id')
                ->where('lots_vente.ligne_vente_id', $this->id)->get(['lots_stock.id', 'lots_stock.numero', 'lots_stock.numero_lot', 'lots_stock.date_limite', 'lots_vente.quantite'])
                ->map(fn ($l) => ['id' => $l->id, 'numero' => $l->numero_lot ?? $l->numero, 'date_limite' => $l->date_limite, 'quantite' => $l->quantite]),
            'total' => $this->montant_total,
            'id' => $this->id,
            'quantite_retournee' => $this->whenLoaded('returnLines', fn () => number_format((float) $this->returnLines->sum('quantite'), 3, '.', '')),
            'garanties' => $this->whenLoaded('warranties', fn () => $this->warranties->map(fn ($w) => ['id' => $w->id, 'numero' => $w->numero, 'appareil_id' => $w->appareil_id, 'fin_le' => $w->fin_le->toDateString(), 'duree_mois' => $w->duree_mois, 'etat' => $w->state()])),
            'appareils' => $this->whenLoaded('devices', fn () => $this->devices->map(fn ($d) => ['id' => $d->id, 'imei' => $d->imei, 'numero_serie' => $d->numero_serie])),
        ];
    }
}
