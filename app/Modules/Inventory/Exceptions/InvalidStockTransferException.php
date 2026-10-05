<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/** Destination hors de l'entreprise, sans suivi de stock, ou non autorisée — docs/inventory.md §"Transferts". */
class InvalidStockTransferException extends RuntimeException {}
