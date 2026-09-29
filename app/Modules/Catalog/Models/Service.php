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
#[Fillable(['categorie_id', 'nom', 'slug', 'description', 'prix', 'duree_minutes', 'actif'])]
class Service extends Model implements Sellable
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'actif' => true,
    ];

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categorie_id');
    }

    public function getSellableLabel(): string
    {
        return $this->nom;
    }

    public function getSellablePrice(): string
    {
        return $this->prix;
    }

    public function tracksStock(): bool
    {
        return false;
    }
}
