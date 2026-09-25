<?php

namespace App\Modules\CashRegister\Exceptions;

use RuntimeException;

/** No movement, and no second close, on a session that isn't open — see docs/cash-register.md §"Fermeture immutable". */
class CashRegisterSessionClosedException extends RuntimeException {}
