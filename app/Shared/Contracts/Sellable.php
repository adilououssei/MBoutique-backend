<?php

namespace App\Shared\Contracts;

/**
 * Implemented by Product and Service so a future Sales/Orders module can
 * treat "the thing being sold" uniformly without knowing which of the
 * two it is — see docs/catalog.md for why this is a plain interface
 * rather than a polymorphic table, and docs/database.md §10 for the
 * original decision this implements.
 *
 * Deliberately minimal: no isTaxable()/tax rate here (Product/Service
 * don't carry tax fields yet — see docs/catalog.md "Écarts assumés").
 * Extend this interface, not around it, when Sales needs more.
 */
interface Sellable
{
    public function getSellableLabel(): string;

    /** @return string decimal string (never a float) — see docs/database.md §0 */
    public function getSellablePrice(): string;

    /**
     * Whether this Sellable is ever expected to move stock. Lets Sales/
     * Inventory decide whether to write a StockMovement without ever
     * doing `instanceof Product` — see docs/database.md §5.
     */
    public function tracksStock(): bool;
}
