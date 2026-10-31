<?php

namespace App\Modules\Customers\Exceptions;

use RuntimeException;

/** ORDONNANCE_REQUISE, ORDONNANCE_INSUFFISANTE, ORDONNANCE_CLOSE… */
class PrescriptionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
