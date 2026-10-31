<?php

namespace App\Modules\Devices\Enums;

enum DeviceStatus: string
{
    /** En stock, disponible à la vente. */
    case InStock = 'en_stock';
    case Sold = 'vendu';
    /** Retiré de la vente (casse, panne) : n'est plus compté dans le stock. */
    case Defective = 'defectueux';
    /** Perdu ou volé : n'est plus compté dans le stock. */
    case Lost = 'perdu';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'En stock',
            self::Sold => 'Vendu',
            self::Defective => 'Défectueux',
            self::Lost => 'Perdu / volé',
        };
    }
}
