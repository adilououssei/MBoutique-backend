<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Inventory\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Current-state cache, not the source of truth — StockMovement is (see
 * docs/inventory.md). Never write `quantity` directly outside
 * InventoryService; only `minimum_quantity` (a threshold, not a ledger
 * fact) is ever updated directly, via StockController::update().
 */
#[Fillable(['product_id', 'quantity', 'minimum_quantity'])]
class Stock extends Model
{
    use BelongsToStore, HasFactory;

    protected $attributes = [
        'quantity' => 0,
    ];

    protected static function newFactory(): StockFactory
    {
        return StockFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'minimum_quantity' => 'decimal:3',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->minimum_quantity !== null && $this->quantity <= $this->minimum_quantity;
    }
}
