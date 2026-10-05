<?php

namespace App\Modules\Appointments\Policies;

use App\Models\User;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Tenancy\Models\Store;

class AppointmentPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('rendez_vous.voir');
    }

    public function view(User $user, Appointment $appointment, Store $store): bool
    {
        return $appointment->boutique_id === $store->id && $user->can('rendez_vous.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('rendez_vous.creer');
    }

    /** Déplacer, confirmer, terminer, marquer absent. */
    public function update(User $user, Appointment $appointment, Store $store): bool
    {
        return $appointment->boutique_id === $store->id && $user->can('rendez_vous.modifier');
    }

    public function cancel(User $user, Appointment $appointment, Store $store): bool
    {
        return $appointment->boutique_id === $store->id && $user->can('rendez_vous.annuler');
    }
}
