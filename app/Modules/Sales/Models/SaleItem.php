<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Sales\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot line, not a live reference — `product_name`/`unit_price`
 * are frozen at the time of sale so a later Product rename or price
 * change never alters a past receipt. See docs/sales.md §"Snapshot".
 * Product-only in this phase: Service isn't sellable through Sales yet
 * (pricing_mode/détail-gros are Product-specific concepts, see
 * docs/sales.md §"Écart d'architecture").
 */
#[Fillable(['sale_id', 'product_id', 'product_name', 'pricing_mode', 'unit_price', 'quantity', 'total_amount'])]
class SaleItem extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'pricing_mode' => PricingMode::class,
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:3',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function newFactory(): SaleItemFactory
    {
        return SaleItemFactory::new();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
