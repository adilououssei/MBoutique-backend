<?php

namespace App\Modules\Conservation\Exceptions;

use RuntimeException;

/** Pharmacie : lot périmé, bloqué ou rappelé, ou quantité vendable insuffisante (LOT_NON_VENDABLE). */
class LotNotSellableException extends RuntimeException {}
