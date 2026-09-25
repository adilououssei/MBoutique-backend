<?php

namespace App\Modules\CashRegister\Exceptions;

use RuntimeException;

/** At most one open session per register — see docs/cash-register.md §"Une seule session ouverte". */
class CashRegisterAlreadyOpenException extends RuntimeException {}
