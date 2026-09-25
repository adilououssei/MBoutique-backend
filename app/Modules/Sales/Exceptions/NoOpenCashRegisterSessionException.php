<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/** A cash sale is never allowed without an open session on the chosen register — see docs/sales.md §"Intégration CashRegister". */
class NoOpenCashRegisterSessionException extends RuntimeException {}
