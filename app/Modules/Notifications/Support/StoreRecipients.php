<?php

namespace App\Modules\Notifications\Support;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Support\Collection;

/**
 * Qui notifier dans une boutique : les membres actifs qui ont une permission
 * donnée. Les permissions Spatie sont scopées par boutique (« teams ») : le
 * contexte de la boutique est donc posé le temps de la vérification, puis
 * restauré — même technique que StoreController::roleOnStore().
 */
class StoreRecipients
{
    public function __construct(private readonly TenantContextContract $tenantContext) {}

    /** @return Collection<int, User> */
    public function withPermission(int $storeId, string $permission, ?int $exceptUserId = null): Collection
    {
        $previous = $this->tenantContext->getStoreId();
        $this->tenantContext->setStoreId($storeId);

        try {
            return StoreUser::query()
                ->where('boutique_id', $storeId)
                ->where('statut', StoreUserStatus::Active->value)
                ->when($exceptUserId, fn ($q) => $q->where('utilisateur_id', '!=', $exceptUserId))
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                // Utilisateurs rechargés : pas de rôles mis en cache pour une autre boutique.
                ->map(fn (User $user) => $user->fresh())
                ->filter(fn (?User $user) => $user !== null && $user->checkPermissionTo($permission))
                ->values();
        } finally {
            $previous !== null ? $this->tenantContext->setStoreId($previous) : $this->tenantContext->clear();
        }
    }
}
