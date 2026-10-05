<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** Commande déjà encaissée ou annulée : elle ne change plus. */
class OrderClosedException extends RuntimeException {}
