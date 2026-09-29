<?php

namespace App\Modules\CashRegister\Enums;

/**
 * `Opening` mirrors Inventory's `StockMovementType::Initial` (see
 * docs/inventory.md): the amount a session starts with is a first-class
 * ledger entry, not a bare `montant_ouverture` column with the ledger
 * starting at 0 — see docs/cash-register.md §"Ouverture et premier mouvement".
 *
 * `Sale`/`Refund` exist so the ledger already knows how to represent
 * them, but neither is manually recordable in this phase — reserved for
 * a future Sales/Payments module calling CashRegisterService directly.
 */
enum CashMovementType: string
{
    case Opening = 'ouverture';
    case CashIn = 'entree';
    case CashOut = 'sortie';
    case Adjustment = 'ajustement';
    case Sale = 'vente';
    case Refund = 'remboursement';

    /** @return array<int, self> */
    public static function manuallyRecordable(): array
    {
        return [self::CashIn, self::CashOut, self::Adjustment];
    }
}
