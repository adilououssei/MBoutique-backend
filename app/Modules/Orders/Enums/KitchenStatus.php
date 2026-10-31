<?php

namespace App\Modules\Orders\Enums;

/** Étape d'un plat en cuisine (une ligne de commande). */
enum KitchenStatus: string
{
    case Pending = 'a_preparer';
    case Preparing = 'en_preparation';
    case Ready = 'prete';
    case Served = 'servie';
    /** Plat épuisé signalé par la cuisine : retiré de l'addition. */
    case Unavailable = 'indisponible';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À préparer',
            self::Preparing => 'En préparation',
            self::Ready => 'Prêt',
            self::Served => 'Servi',
            self::Unavailable => 'Indisponible',
        };
    }
}
