<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Émis à chaque mouvement de stock, DANS la transaction qui l'écrit (à la
 * différence de StockLevelChanged, distribué après le commit) : un écouteur
 * qui tient une projection du stock (les lots du module Conservation) reste
 * ainsi tout-ou-rien avec le mouvement. Inventory ne connaît pas ses écouteurs.
 */
class StockMovementRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly StockMovement $movement,
        public readonly Product $product,
    ) {}
}
