<?php

namespace App\Modules\Expenses\Enums;

enum ExpensePaymentMode: string
{
    /** Sortie d'argent de la session ouverte d'une caisse. */
    case CashRegister = 'caisse';

    /** Payée hors caisse (banque, Mobile Money, poche du gérant…). */
    case External = 'externe';
}
