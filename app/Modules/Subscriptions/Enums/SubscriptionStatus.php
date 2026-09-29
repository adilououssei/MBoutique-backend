<?php

namespace App\Modules\Subscriptions\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'essai';
    case Active = 'actif';
    case PastDue = 'impaye';
    case Cancelled = 'annule';
}
