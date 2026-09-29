<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Enums\BusinessStatus;
use Database\Factories\Modules\Tenancy\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The contractual tenant: owns the subscription and can have several
 * Stores. Business-level authorization is BusinessUser, not spatie
 * permissions (those are store-level). See docs/database.md §2.
 */
#[Fillable(['nom', 'raison_sociale', 'pays', 'devise', 'fuseau_horaire'])]
#[Table('entreprises')]
class Business extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * See the identical note on App\Models\User — Eloquent's create()
     * doesn't re-fetch DB-level defaults, so this must mirror the
     * `entreprises` migration's default for `statut`.
     */
    protected $attributes = [
        'statut' => 'active',
        'devise' => 'XOF',
        'fuseau_horaire' => 'UTC',
    ];

    protected static function newFactory(): BusinessFactory
    {
        return BusinessFactory::new();
    }

    protected function casts(): array
    {
        return [
            'statut' => BusinessStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietaire_id');
    }

    public function businessUsers(): HasMany
    {
        return $this->hasMany(BusinessUser::class, 'entreprise_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'entreprise_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'entreprise_id');
    }
}
