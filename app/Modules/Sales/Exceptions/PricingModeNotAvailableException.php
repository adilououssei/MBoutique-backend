<?php

namespace App\Modules\Sales\Exceptions;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use RuntimeException;

/** The server, never the client, decides whether a product's price is trusted — see docs/sales.md §"Calcul des prix". */
class PricingModeNotAvailableException extends RuntimeException
{
    public static function forProduct(Product $product, PricingMode $mode): self
    {
        $label = $mode === PricingMode::Retail ? 'détail' : 'gros';

        return new self("Le mode de prix \"{$label}\" n'est pas activé pour \"{$product->name}\".");
    }
}
