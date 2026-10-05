<?php

namespace App\Modules\Suppliers\Exceptions;

use RuntimeException;

/** Règlement nul, négatif ou supérieur au reste à payer — docs/modules.md §Suppliers. */
class InvalidPurchasePaymentException extends RuntimeException {}
