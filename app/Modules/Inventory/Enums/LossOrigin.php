<?php

namespace App\Modules\Inventory\Enums;

/**
 * Origine d'une perte de stock (mouvement `perte`). Facultative : une perte
 * sans origine reste valide. Les pertes de découpe ne sont pas des mouvements
 * `perte` : elles sont l'écart de poids d'une transformation.
 */
enum LossOrigin: string
{
    case Expiry = 'peremption';
    case Spoiled = 'avarie';
    case ColdChain = 'conservation';
    case Handling = 'manipulation';
    case Theft = 'vol';
    case Other = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Expiry => 'Date limite dépassée',
            self::Spoiled => 'Produit avarié ou endommagé',
            self::ColdChain => 'Problème de froid',
            self::Handling => 'Chute / manipulation',
            self::Theft => 'Vol',
            self::Other => 'Autre',
        };
    }
}
