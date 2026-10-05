<?php

namespace App\Modules\Appointments\Exceptions;

use RuntimeException;

/** L'employé a déjà un rendez-vous qui chevauche ce créneau. */
class SlotUnavailableException extends RuntimeException {}
