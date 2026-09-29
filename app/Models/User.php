<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Modules\Tenancy\Models\BusinessUser;
use App\Modules\Tenancy\Models\StoreUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * The platform-wide user account. Owns authentication and profile data
 * only — membership in a Business (BusinessUser) or a Store (StoreUser)
 * is modelled separately (see app/Modules/Tenancy). See docs/database.md
 * and docs/modules.md "Auth" vs "Users".
 */
#[Fillable(['nom', 'email', 'telephone', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[Table('utilisateurs')]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Eloquent does not re-fetch DB-level column defaults after create()
     * (create() keeps only the attributes it was given, plus the
     * generated key) — without this, a freshly-registered User has
     * `statut === null` in memory even though the `utilisateurs` migration
     * defaults the column to 'actif'. Must stay in sync with that
     * migration. Found while wiring UserResource in Phase 1.
     */
    protected $attributes = [
        'statut' => 'actif',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'statut' => UserStatus::class,
        ];
    }

    public function businessMemberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class, 'utilisateur_id');
    }

    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreUser::class, 'utilisateur_id');
    }

    public function isActive(): bool
    {
        return $this->statut === UserStatus::Active;
    }
}
