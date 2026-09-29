<?php

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Catalog\Models\Product;
use RuntimeException;

/**
 * A negative stock is rejected by default (docs/inventory.md
 * §"Stock négatif") — this is a business rule, not a 500. Controllers
 * catch it and translate it to a clean API error.
 */
class InsufficientStockException extends RuntimeException
{
    public static function forProduct(Product $product, string $available, string $requested): self
    {
        return new self(
            "Stock insuffisant pour \"{$product->nom}\" : disponible {$available}, demandé {$requested}."
        );
    }
}
