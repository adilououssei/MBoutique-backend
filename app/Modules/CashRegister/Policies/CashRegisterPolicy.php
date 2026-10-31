<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Tenancy\Models\Store;

class CashRegisterPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('caisse.voir');
    }

    public function view(User $user, CashRegister $cashRegister, Store $store): bool
    {
        return $cashRegister->boutique_id === $store->id && $user->can('caisse.voir');
    }

    /**
     * Chacun crée SA caisse : le gérant (caisse.gerer) comme le caissier qui
     * tient la caisse (caisse.ouvrir). « Une seule caisse par personne » est
     * vérifié par le contrôleur (CAISSE_DEJA_ATTRIBUEE), pour un message clair.
     */
    public function create(User $user, Store $store): bool
    {
        return $user->can('caisse.gerer') || $user->can('caisse.ouvrir');
    }

    /** Seul celui qui a créé la caisse la modifie (CashRegister::isEditableBy). */
    public function update(User $user, CashRegister $cashRegister, Store $store): bool
    {
        return $cashRegister->boutique_id === $store->id && $cashRegister->isEditableBy($user);
    }
}
