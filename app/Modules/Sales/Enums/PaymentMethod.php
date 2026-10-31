<?php

namespace App\Modules\Sales\Enums;

/**
 * Moyens acceptés à l'encaissement : espèces, Mobile Money, carte, virement,
 * crédit. Seules les espèces (et l'acompte d'une vente à crédit) entrent dans
 * la caisse : un paiement Mobile Money ou carte n'est pas de l'argent dans le
 * tiroir. `mixte` reste réservé.
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
        return [self::Cash, self::MobileMoney, self::Card, self::BankTransfer, self::Credit];
    }

    /** L'argent reçu à la vente entre-t-il dans le tiroir-caisse ? */
    public function collectsCash(): bool
    {
        return $this === self::Cash || $this === self::Credit;
    }
}
