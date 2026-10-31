<?php

namespace App\Modules\Transformations\Exceptions;

use RuntimeException;

/** Une matière consommée n'a ni prix d'achat connu, ni coût saisi : le coût de revient serait faux. */
class UnknownCostException extends RuntimeException {}
