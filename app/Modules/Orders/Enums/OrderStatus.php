<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case Waiting = 'en_attente';
    case Preparing = 'en_preparation';
    case Ready = 'prete';
    /** Servie (restaurant) ou remise au client (atelier, pressing). */
    case Served = 'servie';
    case Paid = 'payee';
    case Cancelled = 'annulee';

    /** Commande ouverte : modifiable, occupe sa table. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Paid, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Waiting->value, self::Preparing->value, self::Ready->value, self::Served->value];
    }
}
