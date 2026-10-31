<?php

namespace App\Modules\Appointments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Le créneau n'est pas réservable : chevauchement (CRENEAU_INDISPONIBLE),
 * salon fermé (SALON_FERME), employé hors horaires, en pause ou absent
 * (EMPLOYE_INDISPONIBLE), ou non habilité (EMPLOYE_NON_HABILITE).
 */
class SlotUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'CRENEAU_INDISPONIBLE', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
