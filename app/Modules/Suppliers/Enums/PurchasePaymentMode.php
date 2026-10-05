<?php

namespace App\Modules\Suppliers\Enums;

enum PurchasePaymentMode: string
{
    /** Sorti d'une session de caisse ouverte (mouvement de sortie). */
    case CashRegister = 'caisse';

    /** Payé hors caisse : banque, Mobile Money, argent du gérant… */
    case External = 'externe';
}
