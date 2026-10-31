<?php

namespace App\Modules\Returns\Exceptions;

use RuntimeException;

class ReturnException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
