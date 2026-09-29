<?php

namespace App\Modules\CashRegister\Enums;

/** Two states, on purpose — no "suspended"/"pending" invented without a real need. */
enum CashRegisterSessionStatus: string
{
    case Open = 'ouverte';
    case Closed = 'fermee';
}
