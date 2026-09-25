<?php

namespace App\Modules\CashRegister\Exceptions;

use App\Modules\CashRegister\Models\CashRegisterSession;
use RuntimeException;

/** A cash-out or a negative adjustment may never push the drawer's balance below zero — see docs/cash-register.md §"Cash out". */
class InsufficientCashException extends RuntimeException
{
    public static function forSession(CashRegisterSession $session, string $balance, string $delta): self
    {
        return new self(
            "Solde de caisse insuffisant : solde actuel {$balance}, mouvement demandé {$delta}."
        );
    }
}
