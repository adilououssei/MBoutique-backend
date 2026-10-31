<?php

namespace App\Modules\Orders\Enums;

enum ReservationStatus: string
{
    case Confirmed = 'confirmee';
    /** Les clients sont arrivés (une commande peut être ouverte à leur table). */
    case Arrived = 'arrivee';
    case Cancelled = 'annulee';
    case NoShow = 'non_honoree';
}
