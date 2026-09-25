<?php

namespace App\Modules\CashRegister\Exceptions;

use RuntimeException;

/** A deactivated register (is_active=false) may not open a new session — see docs/cash-register.md §"Caisse inactive". */
class CashRegisterInactiveException extends RuntimeException {}
