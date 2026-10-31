<?php

namespace App\Modules\Equipment\Policies;

use App\Models\User;
use App\Modules\Equipment\Enums\InterventionType;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Tenancy\Models\Store;

class EquipmentPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('equipements.voir');
    }

    public function view(User $user, Equipment $equipment, Store $store): bool
    {
        return $equipment->boutique_id === $store->id && $user->can('equipements.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('equipements.gerer');
    }

    public function update(User $user, Equipment $equipment, Store $store): bool
    {
        return $equipment->boutique_id === $store->id && $user->can('equipements.gerer');
    }

    public function delete(User $user, Equipment $equipment, Store $store): bool
    {
        return $this->update($user, $equipment, $store);
    }

    /** Signaler une panne : tout le personnel qui voit les équipements. Entretien, réparation : le gérant. */
    public function intervene(User $user, Equipment $equipment, Store $store, InterventionType $type): bool
    {
        if ($equipment->boutique_id !== $store->id) {
            return false;
        }

        return $type === InterventionType::Breakdown ? $user->can('equipements.voir') : $user->can('equipements.gerer');
    }
}
