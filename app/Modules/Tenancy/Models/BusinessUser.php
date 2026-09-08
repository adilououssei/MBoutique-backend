<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\BusinessUserRole;
use Database\Factories\Modules\Tenancy\BusinessUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business-level authorization: who may create stores, manage billing,
 * see consolidated reports for a Business. Distinct from StoreUser
 * (store-level membership) and from spatie roles (store-level RBAC).
 * See docs/permissions.md §7.
 */
#[Fillable(['business_id', 'user_id', 'role'])]
class BusinessUser extends Model
{
    use HasFactory;

    protected static function newFactory(): BusinessUserFactory
    {
        return BusinessUserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'role' => BusinessUserRole::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
