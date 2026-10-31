<?php

namespace App\Modules\Warranties\Services;

use App\Modules\Features\Services\FeatureGate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Exceptions\WarrantyException;
use App\Modules\Warranties\Models\Warranty;
use App\Modules\Warranties\Models\WarrantyClaim;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des garanties — docs/modules.md §Warranties. Appelé
 * par Sales (vente, annulation) ; ne fait rien sans la feature `garanties`.
 */
class WarrantyService
{
    public function __construct(private readonly FeatureGate $features) {}

    /**
     * Une garantie par appareil identifié vendu, sinon une par ligne, pour
     * chaque produit qui en offre une (`garantie_mois`). Début : jour de vente.
     */
    public function createForSale(Sale $sale): void
    {
        $store = Store::query()->find($sale->boutique_id);
        if ($store === null || ! $this->features->allows($store, 'garanties')) {
            return;
        }

        $start = $sale->vendue_le->copy()->startOfDay();
        foreach ($sale->items()->with(['product', 'devices'])->whereNotNull('produit_id')->get() as $item) {
            $months = (int) ($item->product?->garantie_mois ?? 0);
            if ($months <= 0) {
                continue;
            }
            $base = [
                'vente_id' => $sale->id,
                'ligne_vente_id' => $item->id,
                'produit_id' => $item->produit_id,
                'client_id' => $sale->client_id,
                'nom_produit' => $item->nom_produit,
                'duree_mois' => $months,
                'debut_le' => $start->toDateString(),
                'fin_le' => $start->copy()->addMonthsNoOverflow($months)->toDateString(),
                'conditions' => $item->product?->conditions_garantie,
                'statut' => Warranty::VALID,
            ];
            $units = $item->devices->isNotEmpty() ? $item->devices->map(fn ($d) => ['appareil_id' => $d->id, 'quantite' => 1]) : collect([['appareil_id' => null, 'quantite' => (string) $item->quantite]]);
            foreach ($units as $unit) {
                $warranty = Warranty::create([...$base, ...$unit]);
                $warranty->update(['numero' => sprintf('GAR-%s-%06d', $start->format('Ymd'), $warranty->id)]);
            }
        }
    }

    /** Vente annulée : ses garanties aussi. */
    public function cancelForSale(Sale $sale): void
    {
        Warranty::query()->where('vente_id', $sale->id)->where('statut', Warranty::VALID)->update(['statut' => Warranty::CANCELLED, 'annulee_le' => now()]);
    }

    /** Garanties d'appareils ou de lignes retournés (module Returns). @param  array<int, int>  $deviceIds */
    public function cancelFor(int $saleItemId, array $deviceIds = []): void
    {
        Warranty::query()->where('ligne_vente_id', $saleItemId)->where('statut', Warranty::VALID)
            ->when($deviceIds !== [], fn ($q) => $q->whereIn('appareil_id', $deviceIds))
            ->update(['statut' => Warranty::CANCELLED, 'annulee_le' => now()]);
    }

    /** Réclamation : seulement sur une garantie active. */
    public function fileClaim(Warranty $warranty, string $description, ?int $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($warranty, $description, $userId) {
            $state = $warranty->state();
            if ($state !== 'active') {
                throw new WarrantyException($state === 'expiree'
                    ? "La garantie {$warranty->numero} a expiré le {$warranty->fin_le->format('d/m/Y')}."
                    : "La garantie {$warranty->numero} a été annulée.", $state === 'expiree' ? 'GARANTIE_EXPIREE' : 'GARANTIE_ANNULEE');
            }
            $claim = $warranty->claims()->create(['description' => $description, 'statut' => 'ouverte', 'cree_par_id' => $userId]);
            $claim->update(['numero' => sprintf('REC-%s-%05d', now()->format('Ymd'), $claim->id)]);
            $claim->followUps()->create(['statut' => 'ouverte', 'note' => $description, 'cree_par_id' => $userId]);

            return $claim;
        });
    }

    /** Suivi d'une réclamation : changement d'étape et note, historisés. */
    public function followUp(WarrantyClaim $claim, string $status, ?string $note, ?string $resolution, ?int $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $status, $note, $resolution, $userId) {
            if (! in_array($claim->statut, WarrantyClaim::OPEN_STATUSES, true)) {
                throw new WarrantyException("La réclamation {$claim->numero} est close.", 'RECLAMATION_CLOSE');
            }
            $closing = ! in_array($status, WarrantyClaim::OPEN_STATUSES, true);
            $claim->update(['statut' => $status, 'resolution' => $resolution ?? $claim->resolution, 'cloturee_le' => $closing ? now() : null]);
            $claim->followUps()->create(['statut' => $status, 'note' => $note, 'cree_par_id' => $userId]);

            return $claim;
        });
    }
}
