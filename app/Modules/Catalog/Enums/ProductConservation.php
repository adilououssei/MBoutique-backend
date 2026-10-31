<?php

namespace App\Modules\Catalog\Enums;

/** Mode de conservation d'un produit frais (boucherie) : sert aux lots et aux alertes de date limite. */
enum ProductConservation: string
{
    case Fresh = 'frais';
    case Frozen = 'congele';
    case Processed = 'transforme';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Frais',
            self::Frozen => 'Congelé',
            self::Processed => 'Transformé',
        };
    }
}
