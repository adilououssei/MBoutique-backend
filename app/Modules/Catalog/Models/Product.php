<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Contracts\Sellable;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical good. See docs/catalog.md for why this stays a plain model
 * (no inventory/stock fields here — that's Inventory, a later phase) and
 * for the Sellable contract this implements.
 */
#[Fillable(['category_id', 'name', 'slug', 'description', 'sku', 'barcode', 'unit', 'purchase_price', 'selling_price', 'is_active'])]
class Product extends Model implements Sellable
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'unit' => 'piece',
    ];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected function casts(): array
    {
        return [
            'unit' => ProductUnit::class,
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
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
        return $this->selling_price;
    }

    public function tracksStock(): bool
    {
        // Every Product is expected to track stock once Inventory exists;
        // kept as a method (not a hardcoded true) so Sales/Inventory never
        // need an `instanceof Product` check — see docs/database.md §5.
        return true;
    }
}
