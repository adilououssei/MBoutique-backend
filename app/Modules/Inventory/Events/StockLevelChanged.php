<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Émis à chaque mouvement de stock. Distribué seulement après le commit de
 * la transaction : une vente annulée par une erreur ne déclenche aucune alerte.
 * Écouté par Notifications (alerte stock faible / rupture).
 */
class StockLevelChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Stock $stock,
        public readonly Product $product,
        public readonly string $before,
        public readonly string $after,
        public readonly ?int $userId,
    ) {}

    /** Le stock vient de passer sous son seuil minimum (ou à zéro) avec ce mouvement. */
    public function crossedLowThreshold(): bool
    {
        if (bccomp($this->after, '0', 3) <= 0) {
            return bccomp($this->before, '0', 3) > 0;
        }

        $minimum = $this->stock->quantite_minimum;

        return $minimum !== null
            && bccomp($this->after, (string) $minimum, 3) <= 0
            && bccomp($this->before, (string) $minimum, 3) > 0;
    }

    public function isOutOfStock(): bool
    {
        return bccomp($this->after, '0', 3) <= 0;
    }
}
