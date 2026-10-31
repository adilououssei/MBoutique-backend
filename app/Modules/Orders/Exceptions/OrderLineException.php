<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** Ligne refusée : plat épuisé (PRODUIT_INDISPONIBLE) ou supplément non proposé (SUPPLEMENT_INVALIDE). */
class OrderLineException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
