<?php

namespace App\Modules\CashRegister\Exceptions;

use RuntimeException;

/** A deactivated register (actif=false) may not open a new session — see docs/cash-register.md §"Caisse inactive". */
class CashRegisterInactiveException extends RuntimeException {}
