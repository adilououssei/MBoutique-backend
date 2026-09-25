<?php

namespace App\Modules\Sales\Enums;

/**
 * Only Cash is accepted by CreateSaleCheckoutRequest in this phase — the
 * rest exist so the column never needs a shape change when a real
 * Payment/PaymentMethod module is built later (Phase 4.3 brief §6/§41).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Mixed = 'mixed';

    /** @return array<int, self> */
    public static function acceptedForCheckout(): array
    {
        return [self::Cash];
    }
}
