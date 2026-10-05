<?php

namespace App\Modules\Sales\Enums;

/**
 * Cash and Credit are accepted by CreateSaleCheckoutRequest — the
 * rest exist so the column never needs a shape change when a real
 * Payment/PaymentMethod module is built later (Phase 4.3 brief §6/§41).
 */
enum PaymentMethod: string
{
    case Cash = 'especes';
    case MobileMoney = 'mobile_money';
    case Card = 'carte';
    case BankTransfer = 'virement';
    case Mixed = 'mixte';
    // À crédit : un acompte éventuel en espèces, le reste sur le compte client (docs/sales.md §24).
    case Credit = 'credit';

    /** @return array<int, self> */
    public static function acceptedForCheckout(): array
    {
        return [self::Cash, self::Credit];
    }
}
