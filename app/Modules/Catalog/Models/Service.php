<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Contracts\Sellable;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prestation. No stock, optionally a duration (used later by
 * Appointments). See docs/catalog.md.
 */
#[Fillable(['category_id', 'name', 'slug', 'description', 'price', 'duration_minutes', 'is_active'])]
class Service extends Model implements Sellable
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getSellableLabel(): string
    {
        return $this->name;
    }

    public function getSellablePrice(): string
    {
        return $this->price;
    }

    public function tracksStock(): bool
    {
        return false;
    }
}
