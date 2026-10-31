<?php

namespace App\Modules\Warranties\Exceptions;

use RuntimeException;

class WarrantyException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
