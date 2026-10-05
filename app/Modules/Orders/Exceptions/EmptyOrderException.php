<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** Rien à encaisser : la commande n'a aucune ligne. */
class EmptyOrderException extends RuntimeException {}
