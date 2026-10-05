<?php

namespace App\Modules\Appointments\Exceptions;

use RuntimeException;

/** Rendez-vous déjà terminé, annulé ou marqué absent : il ne change plus. */
class InvalidAppointmentTransitionException extends RuntimeException {}
