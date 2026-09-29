<?php

namespace App\Modules\Sales\Enums;

/**
 * Two states, no draft/pending — a Sale only ever exists once Checkout
 * has already succeeded atomically (see docs/sales.md §"Panier"). No
 * cancellation workflow in this phase (§18 of the Phase 4.3 brief), but
 * the enum already distinguishes the case so the column never needs to
 * change shape when that lands.
 */
enum SaleStatus: string
{
    case Completed = 'terminee';
    case Cancelled = 'annulee';
}
