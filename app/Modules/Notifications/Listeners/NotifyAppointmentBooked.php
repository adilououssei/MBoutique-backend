<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Appointments\Events\AppointmentBooked;
use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\LocalTime;

/** Nouveau rendez-vous → l'employé concerné, s'il a un compte (et n'a pas réservé lui-même). */
class NotifyAppointmentBooked
{
    public function handle(AppointmentBooked $event): void
    {
        $appointment = $event->appointment->loadMissing(['employee.user', 'service', 'customer']);
        $user = $appointment->employee?->user;

        if ($user === null || $user->id === $event->userId) {
            return;
        }

        $client = $appointment->customer?->nom ?? $appointment->nom_client ?? 'un client';
        $when = LocalTime::when($appointment->debut_le, $appointment->boutique_id);

        $user->notify(new StoreAlert(
            $appointment->boutique_id,
            StoreAlert::APPOINTMENT_BOOKED,
            'Nouveau rendez-vous',
            "{$appointment->service?->nom} avec {$client}, {$when}.",
            ['ecran' => 'rendez_vous', 'id' => $appointment->id],
        ));
    }
}
