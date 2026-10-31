<?php

namespace App\Modules\Conservation\Listeners;

use App\Modules\Conservation\Services\LotService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Events\StockMovementRecorded;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;

/**
 * Synchrone, dans la transaction du mouvement : un lot n'existe jamais sans
 * son mouvement, ni l'inverse. Sans la feature `conservation`, ne fait rien —
 * les autres domaines ne paient rien de plus qu'une vérification.
 */
class TrackStockLots
{
    public function __construct(
        private readonly LotService $lots,
        private readonly FeatureGate $features,
    ) {}

    public function handle(StockMovementRecorded $event): void
    {
        $store = Store::query()->find($event->product->boutique_id);
        if ($store === null || ! $this->features->allows($store, 'conservation')) {
            return;
        }

        $delta = (string) $event->movement->quantite;
        $movement = $event->movement;
        // Annulation d'une vente dont les lots sont connus : ils sont reconstitués (pas de nouveau lot).
        if (bccomp($delta, '0', 3) > 0 && $movement->reference_type === (new Sale)->getMorphClass()
            && $this->lots->restoreFromSale((int) $movement->reference_id, $event->product, $delta)) {
            return;
        }
        if (bccomp($delta, '0', 3) > 0) {
            $this->lots->recordEntry($event->movement, $event->product);
        } elseif (bccomp($delta, '0', 3) < 0) {
            $preferred = $event->movement->reference_type === 'lot_stock' ? (int) $event->movement->reference_id : null;
            $this->lots->consume($event->product, bcmul($delta, '-1', 3), $preferred);
        }
    }
}
