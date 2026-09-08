<?php

namespace App\Modules\Features\Models;

use App\Modules\Tenancy\Models\Store;
use Database\Factories\Modules\Features\BusinessDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The business type chosen when a Store is created (hair_salon,
 * general_store, restaurant, ...). Platform-wide reference data, not
 * tenant-scoped — administered by the platform admin (Phase 6), seeded
 * for now. See docs/features.md.
 */
#[Fillable(['name', 'slug', 'description', 'icon', 'is_active'])]
class BusinessDomain extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): BusinessDomainFactory
    {
        return BusinessDomainFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function domainFeatures(): HasMany
    {
        return $this->hasMany(DomainFeature::class);
    }
}
