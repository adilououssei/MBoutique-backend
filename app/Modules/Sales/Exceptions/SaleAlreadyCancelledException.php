<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/** Une vente ne s'annule qu'une fois — voir docs/sales.md §20. */
class SaleAlreadyCancelledException extends RuntimeException {}
