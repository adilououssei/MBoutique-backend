<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/** A discount may never make the total negative — see docs/sales.md §"Remise". */
class InvalidDiscountException extends RuntimeException {}
