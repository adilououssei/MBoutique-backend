<?php

namespace App\Modules\Catalog\Enums;

/**
 * The two commercial modes a Product can be sold under. Not a stored
 * column — it's the value a future Sales module will send alongside a
 * product_id/quantity, resolved server-side against retail_enabled/
 * wholesale_enabled via Product::priceFor(). See docs/catalog.md §14.
 */
enum PricingMode: string
{
    case Retail = 'detail';
    case Wholesale = 'gros';
}
