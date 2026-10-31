<?php

namespace App\Modules\Transformations\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Models\PreparationSheet;

/** Consulter les fiches : quiconque voit les transformations (pour préparer). Les définir : le gérant. */
class PreparationSheetPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('transformations.voir');
    }

    public function view(User $user, PreparationSheet $sheet, Store $store): bool
    {
        return $sheet->boutique_id === $store->id && $user->can('transformations.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('fiches_preparation.gerer');
    }

    public function update(User $user, PreparationSheet $sheet, Store $store): bool
    {
        return $sheet->boutique_id === $store->id && $user->can('fiches_preparation.gerer');
    }

    public function delete(User $user, PreparationSheet $sheet, Store $store): bool
    {
        return $this->update($user, $sheet, $store);
    }
}
