<?php

namespace App\Modules\Appointments\Enums;

enum AppointmentStatus: string
{
    /** Demande à confirmer (salon) : le créneau est déjà réservé. */
    case Pending = 'en_attente';
    case Planned = 'prevu';
    case Confirmed = 'confirme';
    /** Client installé, prestation commencée (salon). */
    case InProgress = 'en_cours';
    case Done = 'termine';
    case Cancelled = 'annule';
    case NoShow = 'absent';

    /** Un rendez-vous encore « vivant » : il occupe le créneau et peut être modifié. */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Planned, self::Confirmed, self::InProgress], true);
    }

    /** Statuts qui bloquent le créneau de l'employé. */
    public static function blocking(): array
    {
        return [self::Pending->value, self::Planned->value, self::Confirmed->value, self::InProgress->value, self::Done->value];
    }
}
