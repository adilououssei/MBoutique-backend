<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/** L'acompte d'une vente à crédit ne peut pas dépasser le total — docs/sales.md §24. */
class InvalidDepositException extends RuntimeException {}
