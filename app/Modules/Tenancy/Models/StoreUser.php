<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Tenancy\StoreUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a User in a Store, including pending invitations.
 * The existence of an `actif` row here is what Couche 1 of
 * docs/multi-tenancy.md checks before granting access to a store.
 *
 * Uses BelongsToStore: this is the one model in this phase with a real
 * `boutique_id` column representing tenant-scoped data.
 */
#[Fillable(['boutique_id', 'utilisateur_id', 'statut', 'invite_par_id', 'invite_le', 'rejoint_le'])]
#[Table('utilisateurs_boutique')]
class StoreUser extends Model
{
    use BelongsToStore, HasFactory;

    protected static function newFactory(): StoreUserFactory
    {
        return StoreUserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'statut' => StoreUserStatus::class,
            'invite_le' => 'datetime',
            'rejoint_le' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invite_par_id');
    }

    public function isActive(): bool
    {
        return $this->statut === StoreUserStatus::Active;
    }
}
