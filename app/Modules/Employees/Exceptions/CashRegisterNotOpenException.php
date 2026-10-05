<?php

namespace App\Modules\Employees\Exceptions;

use RuntimeException;

/** Un paiement « depuis la caisse » exige une session de caisse ouverte. */
class CashRegisterNotOpenException extends RuntimeException {}
