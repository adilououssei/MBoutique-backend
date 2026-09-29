<?php

namespace App\Modules\Catalog\Enums;

/**
 * A small controlled vocabulary, not a unit-conversion system — no
 * conversion logic between units is implemented or planned here. See
 * docs/catalog.md.
 */
enum ProductUnit: string
{
    case Piece = 'piece';
    case Kg = 'kg';
    case G = 'g';
    case Litre = 'litre';
    case Ml = 'ml';
    case Box = 'boite';
    case Pack = 'paquet';
}
