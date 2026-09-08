<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared by Product and Service on purpose — one category tree per
 * store, not one per catalog type, per the Phase 3 brief. See
 * docs/catalog.md.
 */
#[Fillable(['name', 'slug', 'description', 'is_active'])]
class Category extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
