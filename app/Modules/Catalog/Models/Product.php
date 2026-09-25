<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\PricingMode;
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
use InvalidArgumentException;

/**
 * A physical good. See docs/catalog.md for why this stays a plain model
 * (no inventory/stock fields here — that's Inventory, a later phase) and
 * for the Sellable contract this implements.
 */
#[Fillable(['category_id', 'name', 'slug', 'description', 'sku', 'barcode', 'unit', 'purchase_price', 'retail_enabled', 'retail_price', 'wholesale_enabled', 'wholesale_price', 'is_active'])]
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

    protected static function booted(): void
    {
        // Belt-and-braces for the invariant FormRequest validation
        // already enforces on the payload (docs/catalog.md §4): a
        // partial update can flip *_enabled to false without touching
        // *_price at all, which validation alone wouldn't catch — this
        // guarantees the STORED row never disagrees, regardless of
        // entry point (manual, import, voice, or a raw Eloquent call).
        static::saving(function (Product $product) {
            if (! $product->retail_enabled) {
                $product->retail_price = null;
            }
            if (! $product->wholesale_enabled) {
                $product->wholesale_price = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'unit' => ProductUnit::class,
            'purchase_price' => 'decimal:2',
            'retail_enabled' => 'boolean',
            'retail_price' => 'decimal:2',
            'wholesale_enabled' => 'boolean',
            'wholesale_price' => 'decimal:2',
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

    /**
     * Generic display price — retail takes priority since it's the
     * common case. NOT what a future Sales module should use to compute
     * an actual sale amount: that must resolve the mode explicitly via
     * priceFor(), per the contract documented in docs/catalog.md §14.
     */
    public function getSellablePrice(): string
    {
        return $this->retail_price ?? $this->wholesale_price;
    }

    /**
     * The future Sales contract (docs/catalog.md §14): given an explicit
     * pricing_mode, return the price the backend — never the client —
     * is the source of truth for. Throws if that mode isn't enabled on
     * this product, so Sales can never silently sell at a disabled price.
     */
    public function priceFor(PricingMode $mode): string
    {
        return match ($mode) {
            PricingMode::Retail => $this->retail_enabled
                ? $this->retail_price
                : throw new InvalidArgumentException("Product #{$this->id} does not have retail pricing enabled."),
            PricingMode::Wholesale => $this->wholesale_enabled
                ? $this->wholesale_price
                : throw new InvalidArgumentException("Product #{$this->id} does not have wholesale pricing enabled."),
        };
    }

    public function tracksStock(): bool
    {
        // Every Product is expected to track stock once Inventory exists;
        // kept as a method (not a hardcoded true) so Sales/Inventory never
        // need an `instanceof Product` check — see docs/database.md §5.
        return true;
    }
}
