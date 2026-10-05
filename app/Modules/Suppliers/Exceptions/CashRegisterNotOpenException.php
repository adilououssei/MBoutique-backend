<?php

namespace App\Modules\Suppliers\Exceptions;

use RuntimeException;

/** Un règlement « depuis la caisse » exige une session de caisse ouverte. */
class CashRegisterNotOpenException extends RuntimeException {}
