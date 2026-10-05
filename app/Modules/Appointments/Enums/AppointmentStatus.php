<?php

namespace App\Modules\Appointments\Enums;

enum AppointmentStatus: string
{
    case Planned = 'prevu';
    case Confirmed = 'confirme';
    case Done = 'termine';
    case Cancelled = 'annule';
    case NoShow = 'absent';

    /** Un rendez-vous encore « vivant » : il occupe le créneau et peut être modifié. */
    public function isActive(): bool
    {
        return $this === self::Planned || $this === self::Confirmed;
    }

    /** Statuts qui bloquent le créneau de l'employé. */
    public static function blocking(): array
    {
        return [self::Planned->value, self::Confirmed->value, self::Done->value];
    }
}
