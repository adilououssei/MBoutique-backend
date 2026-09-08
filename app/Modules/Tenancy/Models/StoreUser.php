<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Tenancy\StoreUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a User in a Store, including pending invitations.
 * The existence of an `active` row here is what Couche 1 of
 * docs/multi-tenancy.md checks before granting access to a store.
 *
 * Uses BelongsToStore: this is the one model in this phase with a real
 * `store_id` column representing tenant-scoped data.
 */
#[Fillable(['store_id', 'user_id', 'status', 'invited_by_user_id', 'invited_at', 'joined_at'])]
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
            'status' => StoreUserStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === StoreUserStatus::Active;
    }
}
