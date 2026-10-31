<?php

namespace App\Modules\Repairs\Exceptions;

use RuntimeException;

class RepairException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
