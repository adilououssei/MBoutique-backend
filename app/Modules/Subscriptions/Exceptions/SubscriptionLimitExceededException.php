<?php

namespace App\Modules\Subscriptions\Exceptions;

use RuntimeException;

class SubscriptionLimitExceededException extends RuntimeException
{
    public static function forStores(int $max): self
    {
        return new self("Ce plan est limité à {$max} boutique(s). Passez à un plan supérieur pour en créer davantage.");
    }
}
