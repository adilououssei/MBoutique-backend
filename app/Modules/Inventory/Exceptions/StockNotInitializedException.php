<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/** Every movement type other than `initial` requires an existing Stock row — see docs/inventory.md §"Initialisation". */
class StockNotInitializedException extends RuntimeException {}
