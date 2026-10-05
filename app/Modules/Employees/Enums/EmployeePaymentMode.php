<?php

namespace App\Modules\Employees\Enums;

/** Mêmes valeurs que PurchasePaymentMode (Suppliers) : chaque module garde ses enums. */
enum EmployeePaymentMode: string
{
    /** Sorti d'une session de caisse ouverte (mouvement de sortie). */
    case CashRegister = 'caisse';

    /** Payé hors caisse : banque, Mobile Money… */
    case External = 'externe';
}
