<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/** type=initial is only valid once per product — see docs/inventory.md §"Initialisation". */
class StockAlreadyInitializedException extends RuntimeException {}
