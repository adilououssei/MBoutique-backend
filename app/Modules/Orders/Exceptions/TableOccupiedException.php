<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** Une table n'a qu'une commande ouverte à la fois : on ajoute des lignes à celle-ci. */
class TableOccupiedException extends RuntimeException {}
