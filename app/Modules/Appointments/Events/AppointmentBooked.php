<?php

namespace App\Modules\Appointments\Events;

use App\Modules\Appointments\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Émis après la réservation d'un rendez-vous (après commit). Écouté par Notifications. */
class AppointmentBooked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly ?int $userId,
    ) {}
}
