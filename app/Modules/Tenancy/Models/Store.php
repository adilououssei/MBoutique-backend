<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Tenancy\Enums\StoreStatus;
use Database\Factories\Modules\Tenancy\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The operational tenant: the isolation boundary for every business-data
 * table (BelongsToStore). Belongs to one Business, which may own several,
 * and to exactly one BusinessDomain (added in Phase 2 — see
 * docs/features.md and the migration that adds business_domain_id).
 */
#[Fillable(['name', 'slug', 'address', 'phone', 'currency', 'timezone', 'business_domain_id'])]
class Store extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * See the identical note on App\Models\User — Eloquent's create()
     * doesn't re-fetch DB-level defaults, so this must mirror the
     * `stores` migration's defaults.
     */
    protected $attributes = [
        'status' => 'active',
        'currency' => 'XOF',
        'timezone' => 'UTC',
    ];

    protected static function newFactory(): StoreFactory
    {
        return StoreFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'settings' => AsArrayObject::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function businessDomain(): BelongsTo
    {
        return $this->belongsTo(BusinessDomain::class);
    }

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
