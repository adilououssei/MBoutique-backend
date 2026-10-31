<?php

namespace App\Modules\Catalog\Enums;

/** État d'un article (électronique) : neuf, occasion ou reconditionné. */
enum ProductCondition: string
{
    case New = 'neuf';
    case Used = 'occasion';
    case Refurbished = 'reconditionne';
    // Vêtements : seconde main et fins de série.
    case Thrift = 'friperie';
    case Clearance = 'destockage';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neuf',
            self::Used => 'Occasion',
            self::Refurbished => 'Reconditionné',
        };
    }
}
