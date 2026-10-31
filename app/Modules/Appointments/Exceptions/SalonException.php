<?php

namespace App\Modules\Appointments\Exceptions;

use RuntimeException;
use Throwable;

/** Règle métier du salon (forfait, fidélité, commission, acompte) — 422 avec un code exploitable. */
class SalonException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'SALON_INVALIDE', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
