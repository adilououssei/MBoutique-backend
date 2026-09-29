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
#[Fillable(['produit_id', 'quantite', 'quantite_minimum'])]
class Stock extends Model
{
    use BelongsToStore, HasFactory;

    protected $attributes = [
        'quantite' => 0,
    ];

    protected static function newFactory(): StockFactory
    {
        return StockFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'quantite_minimum' => 'decimal:3',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_id');
    }

    public function isLowStock(): bool
    {
        return $this->quantite_minimum !== null && $this->quantite <= $this->quantite_minimum;
    }
}
